<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Enums\MoneyState;
use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Enums\SpendLimitState;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\ValueObjects\MoneyScope;
use Illuminate\Support\Carbon;

/**
 * What a workspace's own spend limits look like right now — BUDGET-GOVERNANCE-001.
 *
 * ## It measures; it does not enforce
 *
 * Every reading this produces carries `enforcement: internal_monitoring`. CampaignsHub cannot stop
 * an ad platform from spending, and an operator who believes otherwise will not go and pause the
 * campaigns. The word travels with the figure rather than being printed once on a page header,
 * because the figure is what gets copied into a report.
 *
 * ## No second money engine
 *
 * Spend comes from `MetricsAggregator` scoped to the limit, and the composition question — is there
 * one figure here at all? — is answered by `MoneyScope`, the same value object the funnel and the
 * campaign budget pacing already consume. A limit whose spend is partly withheld for want of an
 * exchange rate has NO comparable figure, and this returns `unknown` with the reason rather than
 * pacing against the converted subset. That subset is smaller than the truth, which on a governance
 * surface means reporting safety that is not there.
 *
 * ## Projection is evidence-gated
 *
 * «You will reach this limit on the 14th» is a strong sentence and it needs a rate to stand on. It
 * is withheld for a period that has barely started, for spend that is not moving, and for anything
 * whose exhaustion date falls outside the period — where the honest answer is «not before this
 * period ends», not a date beyond the window nobody is measuring.
 */
final class SpendLimitGovernor
{
    /**
     * How many elapsed days a projection needs before it is worth stating.
     *
     * One day of spend extrapolated over a month is not a forecast; it is one number multiplied by
     * thirty. Three is the smallest window in which a weekday and a weekend can both have happened,
     * which is the shortest honest answer to «is this the normal rate?».
     */
    private const MIN_DAYS_FOR_PROJECTION = 3;

    public function __construct(private readonly MetricsAggregator $metrics) {}

    /**
     * One limit, read against today.
     *
     * @return array<string, mixed>
     */
    public function read(SpendLimit $limit, Carbon $today): array
    {
        $from = $limit->starts_on->copy()->startOfDay();
        $to = $limit->ends_on->copy()->startOfDay();

        $scope = $this->spend($limit, $from, $to);
        $consumed = $scope->amount();
        /*
         * A CONVERTED sum is in the project's own reporting currency, which the rows carry and this
         * reads rather than assumes — the same reason `rangeCurrency()` exists on the controller. A
         * helper that defaulted to a market's currency would state the wrong unit, silently, the
         * first time a project reported in another one.
         */
        $currency = $scope->currency($this->reportingCurrency($limit, $from, $to));

        /*
         * Nothing spent is comparable with a limit in ANY currency.
         *
         * With no rows in the window there is no `project_currency` to read, so the currency came
         * back null and a limit with zero spend against it reported «unknown» — the state reserved
         * for money we cannot see. A limit nobody has spent against is not an unknown; it is 0% used,
         * and zero is zero in every currency. Saying «unknown» here would also train the reader to
         * ignore the word on the limits where it means something.
         */
        if ($scope->state === MoneyState::Zero && $currency === null) {
            $currency = $limit->currency;
        }

        $comparable = $consumed !== null
            && is_string($currency)
            && strtoupper($currency) === strtoupper((string) $limit->currency)
            && $limit->amount > 0;

        /*
         * `diffInDays()` returns a FLOAT in Carbon 3, and so does `max()` when the float wins — the
         * same shape that made `MetricSyncRun::durationSeconds()` throw against its declared int.
         * Cast where the value is produced rather than at each use, so a third caller cannot inherit
         * the problem.
         */
        $periodDays = (int) max(1, $from->diffInDays($to) + 1);
        $elapsedDays = $today->lt($from) ? 0 : (int) max(1, $from->diffInDays($today->copy()->min($to)) + 1);
        $elapsedFraction = min(1.0, $elapsedDays / $periodDays);

        $utilisation = $comparable ? $consumed / $limit->amount : null;
        $remaining = $comparable ? $limit->amount - $consumed : null;
        $expected = $limit->amount * $elapsedFraction;

        return [
            'id' => $limit->getKey(),
            'scope' => $limit->scope->value,
            'scope_id' => $limit->scope_id,
            /*
             * The whole safety of this feature, in one field. It is emitted for every limit, in every
             * state, including the ones where nothing could be computed.
             */
            'enforcement' => SpendLimit::ENFORCEMENT,
            'amount' => round($limit->amount, 2),
            'currency' => $limit->currency,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $periodDays],
            'elapsed_days' => $elapsedDays,
            'consumed' => $consumed !== null ? round($consumed, 2) : null,
            'consumed_currency' => $currency,
            'remaining' => $remaining !== null ? round($remaining, 2) : null,
            'utilisation' => $utilisation !== null ? round($utilisation, 4) : null,
            // >1 is ahead of plan. Against the ELAPSED share of the limit, not against the whole of it.
            'pace' => $comparable && $expected > 0 ? round($consumed / $expected, 3) : null,
            'projected_period_spend' => $comparable && $elapsedFraction > 0
                ? round($consumed / $elapsedFraction, 2)
                : null,
            'projected_exhaustion' => $this->projectExhaustion($limit, $consumed, $comparable, $elapsedDays, $today, $to),
            'thresholds' => $limit->thresholdPercents(),
            'state' => $this->state($limit, $utilisation, $comparable)->value,
            'basis' => $this->basis($scope, $limit, $comparable, $currency),
        ];
    }

    /**
     * Spend inside the limit's scope and window, as a composition rather than a number.
     *
     * The four money-truth figures come back from the aggregator exactly as every other surface reads
     * them; `MoneyScope` turns them into «there is one figure» or «there is not».
     */
    private function spend(SpendLimit $limit, Carbon $from, Carbon $to): MoneyScope
    {
        $metrics = $this->metrics->forProjects([$limit->project_id]);

        $metrics = match ($limit->scope) {
            SpendLimitScope::Project => $metrics,
            SpendLimitScope::Platform => $metrics->forProviders([(string) $limit->scope_id]),
            SpendLimitScope::Account => $metrics->forAccounts([(string) $limit->scope_id]),
            SpendLimitScope::Campaign => $metrics->forCampaign((string) $limit->scope_id),
        };

        $totals = $metrics->totals($from, $to);

        // The same five arguments `platformMoney()` and `budgetPacing()` pass — the currency pair is
        // `money_original_*`, which is what `totals()` actually emits.
        return MoneyScope::of(
            (float) ($totals['spend'] ?? 0),
            (int) ($totals['spend_withheld_rows'] ?? 0),
            (float) ($totals['spend_original'] ?? 0),
            (int) ($totals['money_original_currencies'] ?? 0),
            is_string($totals['money_original_currency'] ?? null) ? $totals['money_original_currency'] : null,
        );
    }

    /** The project currency the money rows in this window are expressed in, or null if none are. */
    private function reportingCurrency(SpendLimit $limit, Carbon $from, Carbon $to): ?string
    {
        $value = DailyMetric::query()
            ->where('project_id', $limit->project_id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('project_currency')
            ->toBase()
            ->value('project_currency');

        return $value !== null ? (string) $value : null;
    }

    /**
     * When the limit will be reached at the current rate — or why that cannot be said.
     *
     * @return array{date: string|null, reason: string}
     */
    private function projectExhaustion(
        SpendLimit $limit,
        ?float $consumed,
        bool $comparable,
        int $elapsedDays,
        Carbon $today,
        Carbon $periodEnd,
    ): array {
        if (! $comparable || $consumed === null) {
            return ['date' => null, 'reason' => 'no_comparable_spend'];
        }
        if ($consumed >= $limit->amount) {
            return ['date' => null, 'reason' => 'already_reached'];
        }
        if ($elapsedDays < self::MIN_DAYS_FOR_PROJECTION) {
            return ['date' => null, 'reason' => 'too_early'];
        }

        $daily = $consumed / $elapsedDays;

        if ($daily <= 0.0) {
            // Nothing has been spent, so nothing is on its way to the limit. «Never» is not a date.
            return ['date' => null, 'reason' => 'no_spend_rate'];
        }

        $daysLeft = (int) ceil(($limit->amount - $consumed) / $daily);
        $date = $today->copy()->addDays($daysLeft);

        // A date past the window is a date nobody is measuring — the honest answer is the window.
        return $date->gt($periodEnd)
            ? ['date' => null, 'reason' => 'not_within_period']
            : ['date' => $date->toDateString(), 'reason' => 'projected'];
    }

    /** Ok / approaching / over — or unknown, which is a state and not a missing value. */
    private function state(SpendLimit $limit, ?float $utilisation, bool $comparable): SpendLimitState
    {
        if (! $comparable || $utilisation === null) {
            return SpendLimitState::Unknown;
        }
        if ($utilisation >= 1.0) {
            return SpendLimitState::Over;
        }

        $warnings = array_filter($limit->thresholdPercents(), static fn (int $t): bool => $t < 100);
        $highest = $warnings === [] ? null : max($warnings);

        return $highest !== null && $utilisation * 100 >= $highest
            ? SpendLimitState::Approaching
            : SpendLimitState::Ok;
    }

    /** Why the figures are what they are — the reason travels with the refusal. */
    private function basis(MoneyScope $scope, SpendLimit $limit, bool $comparable, ?string $currency): string
    {
        if ($comparable) {
            return 'comparable';
        }
        if ($scope->state === MoneyState::Partial || $scope->state === MoneyState::MixedCurrency) {
            return $scope->state->value;
        }
        if ($scope->amount() === null) {
            return $scope->state->value;
        }

        return is_string($currency) && strtoupper($currency) !== strtoupper((string) $limit->currency)
            ? 'currency_mismatch'
            : 'no_limit_amount';
    }
}
