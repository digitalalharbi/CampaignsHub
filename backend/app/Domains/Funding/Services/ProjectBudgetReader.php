<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Metrics\Fx\FxConversion;
use App\Domains\Metrics\Spend\SpendLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE-BUDGET-001 — «ميزانية المشروع» has ONE source: the approved budget on /agency/budgets (`project_funding` and its
 * ledger, read by `FundingBalance`). Every other surface — the home dashboard, the client page, the campaigns page, the
 * finance and project-manager dashboards, the spend-limits page — reads it through here, so none of them can disagree.
 *
 * Spend, remaining, the used share and «تكفي X يومًا» are those of the BUDGET'S OWN PERIOD (`FundingBalance`), never a
 * dashboard window: this class takes no date range, on purpose. The campaign budgets set inside the ad platforms are a
 * different thing («ميزانيات الحملات في المنصات», informational) and are never returned from here as a budget.
 *
 * A project with no approved budget (none, or deleted) answers `has_budget: false` — the surface says «بدون ميزانية»
 * and offers «أضف ميزانية»; it never falls back to a platform number.
 */
final class ProjectBudgetReader
{
    public function __construct(private readonly FundingBalance $balance) {}

    /**
     * @param  list<string>  $projectIds
     * @return array<string, array<string, mixed>> keyed by project id; every id asked for is answered
     */
    public function forProjects(array $projectIds, ?Carbon $today = null): array
    {
        $ids = array_values(array_unique(array_map('strval', $projectIds)));
        if ($ids === []) {
            return [];
        }

        $fundings = ProjectFunding::query()->whereIn('project_id', $ids)->whereNull('voided_at')->get()->keyBy('project_id');
        $deposits = DB::table('funding_payments')->whereIn('project_id', $fundings->keys()->all())->whereIn('kind', ['initial', 'top_up'])
            ->selectRaw('project_id, SUM(amount_sar) AS d')->groupBy('project_id')->pluck('d', 'project_id');

        // PERF-BOOTSTRAP-001 — every budget's figures in one pass (a handful of statements), not a dozen per budget.
        $figures = $this->balance->summaries($fundings->values(), $today);

        $out = [];
        foreach ($ids as $id) {
            $funding = $fundings->get($id);
            $out[$id] = $funding instanceof ProjectFunding
                ? $this->summarise($funding, $figures[$id], (float) ($deposits[$id] ?? 0), $today)
                : self::none();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function forProject(string $projectId, ?Carbon $today = null): array
    {
        return $this->forProjects([$projectId], $today)[$projectId];
    }

    /** @return array{has_budget: false} */
    public static function none(): array
    {
        return ['has_budget' => false];
    }

    /**
     * The compact reading every surface draws. `remaining = deposits − settlements − converted spend`, in SAR.
     *
     * @param  array<string, mixed>  $f  `FundingBalance::of()`
     * @return array<string, mixed>
     */
    public function summarise(ProjectFunding $funding, array $f, float $deposits, ?Carbon $today = null): array
    {
        $today = Carbon::parse(($today ?? Carbon::today('Asia/Riyadh'))->toDateString());
        $approved = (float) $f['approved'];
        $spent = (float) $f['spent'];
        $remaining = $f['remaining'] === null ? null : (float) $f['remaining'];
        $pace = $f['pace_3d'] === null ? null : (float) $f['pace_3d'];
        $ratio = $f['used_ratio'] === null ? null : (float) $f['used_ratio'];

        // «تكفي X يومًا» — the same rate as the run-out date: the last three complete days.
        $coverDays = match (true) {
            $remaining === null => null,
            $remaining <= 0 => 0,
            $pace === null || $pace <= 0 => null,
            default => (int) floor($remaining / $pace),
        };

        // «متوقع X من Y» — spend so far plus the same rate to the period's last day; an open period has no forecast.
        $end = $f['period_end'] !== null ? Carbon::parse((string) $f['period_end']) : null;
        $daysToEnd = $end !== null && $end->gte($today) ? (int) $today->diffInDays($end) + 1 : 0;
        $projected = $end === null || $pace === null ? null : round($spent + $pace * $daysToEnd, 2);

        $steps = $funding->thresholdPercents();
        $pct = $ratio === null ? null : $ratio * 100;
        $crossed = $pct === null ? [] : array_values(array_filter($steps, static fn (int $s): bool => $pct >= $s));

        return [
            'has_budget' => true,
            'project_id' => (string) $funding->project_id,
            'currency' => 'SAR',
            'payment_mode' => $f['payment_mode'],
            'payment_method' => $f['payment_method'],
            'status' => $f['status'],
            'approved' => round($approved, 2),
            'deposits' => round($deposits, 2),
            'settlements' => round($deposits - $approved, 2),
            'spent' => round($spent, 2),
            'remaining' => $remaining,
            'used_ratio' => $ratio,
            'pace_per_day' => $pace,
            'cover_days' => $coverDays,
            'run_out_date' => $f['run_out_date'],
            'projected' => $projected,
            'period_start' => $f['period_start'],
            'period_end' => $f['period_end'],
            'thresholds' => $steps,
            'crossed_threshold' => $crossed === [] ? null : max($crossed),
            'complete' => (bool) $f['complete'],
            'last_spend_day' => $f['last_spend_day'],
            // PROJECT-LIFECYCLE-001 §7 — the parts the spend excludes (no rate) and the old rates it used, named (SpendLedger).
            'fx' => self::fxNote(isset($f['fx']) && is_array($f['fx']) ? [$f['fx']] : []),
        ];
    }

    /**
     * The exclusions and old rates of several budgets, merged: the same statement on every surface that adds them.
     *
     * @param  list<array<string, mixed>>  $notes  `FundingBalance::of()['fx']`
     * @return array{complete: bool, excluded: list<array<string, mixed>>, excluded_label: ?string, statement: ?string, statement_en: ?string, stale: list<array<string, mixed>>, rates_page: ?string}
     */
    public static function fxNote(array $notes): array
    {
        $excluded = array_values(array_merge(...array_map(static fn (array $n): array => array_values((array) ($n['excluded'] ?? [])), $notes ?: [[]])));
        $stale = array_values(array_merge(...array_map(static fn (array $n): array => array_values((array) ($n['stale'] ?? [])), $notes ?: [[]])));
        $statements = array_values(array_filter(array_map(static fn (array $n): ?string => $n['statement'] ?? null, $notes)));
        $statementsEn = array_values(array_filter(array_map(static fn (array $n): ?string => $n['statement_en'] ?? null, $notes)));

        return [
            'complete' => $excluded === [],
            'excluded' => $excluded,
            'excluded_label' => $excluded === [] ? null : SpendLedger::EXCLUDED_AR,
            'statement' => $statements === [] ? null : implode(' ', $statements),
            'statement_en' => $statementsEn === [] ? null : implode(' ', $statementsEn),
            'stale' => $stale,
            'rates_page' => $excluded === [] ? null : FxConversion::RATES_PAGE,
        ];
    }

    /**
     * Several projects' readings added together (a client, a dashboard). SAR only, so they always add; a remaining is
     * summed only from budgets that have one (a credit budget has none), and the count of those left out is stated.
     *
     * @param  list<array<string, mixed>>  $readings
     * @return array<string, mixed>
     */
    public static function total(array $readings): array
    {
        $with = array_values(array_filter($readings, static fn (array $r): bool => ($r['has_budget'] ?? false) === true));
        $balances = array_values(array_filter($with, static fn (array $r): bool => $r['remaining'] !== null));
        $approved = array_sum(array_map(static fn (array $r): float => (float) $r['approved'], $balances));
        $spent = array_sum(array_map(static fn (array $r): float => (float) $r['spent'], $balances));
        $remaining = $balances === [] ? null : round(array_sum(array_map(static fn (array $r): float => (float) $r['remaining'], $balances)), 2);
        $pace = array_sum(array_map(static fn (array $r): float => (float) ($r['pace_per_day'] ?? 0), $balances));

        return [
            'currency' => 'SAR',
            'budgeted' => count($with),
            'unbudgeted' => count($readings) - count($with),
            'without_balance' => count($with) - count($balances),
            'approved' => $balances === [] ? null : round($approved, 2),
            'spent' => $balances === [] ? null : round($spent, 2),
            'remaining' => $remaining,
            'used_ratio' => $approved > 0 ? round($spent / $approved, 4) : null,
            'pace_per_day' => $pace > 0 ? round($pace, 2) : null,
            'fx' => self::fxNote(array_values(array_filter(array_map(static fn (array $r): ?array => isset($r['fx']) && is_array($r['fx']) ? $r['fx'] : null, $with)))),
            'cover_days' => match (true) {
                $remaining === null => null,
                $remaining <= 0 => 0,
                $pace <= 0 => null,
                default => (int) floor($remaining / $pace),
            },
        ];
    }
}
