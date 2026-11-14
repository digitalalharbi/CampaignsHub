<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — «ملخص الإنفاق»: what each project spent in the period, and what is left of the spend
 * limit the Budgets section sets for it (the limit finance follows), as figures only.
 *
 * Nothing here is a second budget concept or a second pipeline:
 *   - the spend of the period is `MetricsAggregator::totals()`, the engine behind every report;
 *   - the limit, its spend to date and what remains are `SpendLimitGovernor::read()` over the project's active project-scope
 *     `SpendLimit` — the same reading the Budgets screen and the alert evaluator show.
 *
 * Honest states, never a zero standing in for a missing figure: no limit set (`none`); spend the contract withheld or in another
 * currency than the limit (`unconverted` — «غير محوّل»); otherwise the remaining amount and share, and the days of spend left at
 * the period's own average pace when — and only when — both are measured.
 */
final class ProjectSpendSummary
{
    /** At or above this share used, the limit is «near»; past 100% it is «over». */
    public const NEAR = 0.8;

    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly SpendLimitGovernor $limits,
    ) {}

    /**
     * @param  list<string>  $projectIds  already narrowed to what the reader may see
     * @return array{from: string, to: string, days: int, projects: list<array<string,mixed>>, total: array<string,mixed>}
     */
    public function build(string $tenantId, array $projectIds, Carbon $from, Carbon $to, Carbon $today): array
    {
        $days = (int) max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $projects = $projectIds === [] ? collect() : Project::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('id', $projectIds)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);

        $rows = [];
        foreach ($projects as $project) {
            $rows[] = $this->project($tenantId, (string) $project->id, (string) $project->name, $from, $to, $today, $days);
        }

        // Closest to running out first; a project whose limit cannot be stated comes before the healthy ones.
        $rank = ['over' => 0, 'near' => 1, 'unconverted' => 2, 'ok' => 3, 'none' => 4];
        usort($rows, static fn (array $a, array $b): int => ($rank[$a['state']] <=> $rank[$b['state']])
            ?: (($b['used'] ?? -1) <=> ($a['used'] ?? -1)) ?: strcmp($a['name'], $b['name']));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'projects' => $rows,
            'total' => $this->total($rows),
        ];
    }

    /** @return array<string,mixed> */
    private function project(string $tenantId, string $projectId, string $name, Carbon $from, Carbon $to, Carbon $today, int $days): array
    {
        $scoped = $this->metrics->acrossProjects()->forProjects([$projectId]);
        $totals = $scoped->totals($from, $to);
        $basis = $scoped->currencyBasis($from, $to);
        // Withheld spend, or spend in more than one currency, cannot be stated as one amount: «غير محوّل», never a sum or a 0.
        $withheld = (int) ($totals['spend_withheld_rows'] ?? 0) > 0 || (int) ($basis['bases'] ?? 0) > 1;
        $spend = $withheld ? null : round((float) ($totals['spend'] ?? 0), 2);
        $spendCurrency = is_string($basis['currency'] ?? null) ? (string) $basis['currency'] : null;

        $row = [
            'id' => $projectId, 'name' => $name,
            'spend' => $spend, 'spend_currency' => $spendCurrency, 'spend_withheld' => $withheld,
            'cap' => null, 'cap_currency' => null, 'consumed' => null, 'remaining' => null, 'used' => null,
            'limit_from' => null, 'limit_to' => null, 'days_left' => null, 'state' => 'none',
        ];

        $limit = SpendLimit::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('scope', SpendLimitScope::Project->value)->where('active', true)
            ->whereDate('starts_on', '<=', $today->toDateString())->whereDate('ends_on', '>=', $today->toDateString())
            ->orderByDesc('starts_on')->first();
        if ($limit === null) {
            return $row;
        }

        $reading = $this->limits->read($limit, $today->copy()->startOfDay());
        $row['cap'] = (float) $reading['amount'];
        $row['cap_currency'] = (string) $reading['currency'];
        $row['limit_from'] = $reading['period']['from'];
        $row['limit_to'] = $reading['period']['to'];

        // `remaining` is null exactly when the governor refused the comparison (withheld spend, another currency).
        if ($reading['remaining'] === null || $reading['utilisation'] === null) {
            $row['state'] = 'unconverted';

            return $row;
        }

        $row['consumed'] = (float) $reading['consumed'];
        $row['remaining'] = (float) $reading['remaining'];
        $row['used'] = (float) $reading['utilisation'];
        $row['state'] = $row['used'] >= 1.0 ? 'over' : ($row['used'] >= self::NEAR ? 'near' : 'ok');

        // Days of spend left at THIS period's average daily spend — only when that spend is measured, in the limit's currency, and above zero.
        if ($spend !== null && $spend > 0 && $row['remaining'] > 0 && $spendCurrency !== null && strcasecmp($spendCurrency, $row['cap_currency']) === 0) {
            $row['days_left'] = (int) floor($row['remaining'] / ($spend / $days));
        }

        return $row;
    }

    /**
     * The overall line: spend summed only when every project's spend is measured in one currency; the limits summed only across
     * comparable limits in one currency. Otherwise null, with the count of what was left out.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function total(array $rows): array
    {
        $spendRows = array_values(array_filter($rows, static fn (array $r): bool => $r['spend'] !== null && (float) $r['spend'] > 0));
        $spendCurrencies = array_values(array_unique(array_map(static fn (array $r): string => strtoupper((string) $r['spend_currency']), $spendRows)));
        $withheld = count(array_filter($rows, static fn (array $r): bool => $r['spend_withheld']));

        $capped = array_values(array_filter($rows, static fn (array $r): bool => $r['remaining'] !== null));
        $capCurrencies = array_values(array_unique(array_map(static fn (array $r): string => strtoupper((string) $r['cap_currency']), $capped)));

        $cap = count($capCurrencies) === 1 ? round(array_sum(array_column($capped, 'cap')), 2) : null;
        $remaining = count($capCurrencies) === 1 ? round(array_sum(array_column($capped, 'remaining')), 2) : null;

        return [
            'spend' => count($spendCurrencies) <= 1 && $withheld === 0 ? round(array_sum(array_column($spendRows, 'spend')), 2) : null,
            'spend_currency' => $spendCurrencies[0] ?? null,
            'spend_left_out' => $withheld + (count($spendCurrencies) > 1 ? count($spendRows) : 0),
            'cap' => $cap,
            'remaining' => $remaining,
            'cap_currency' => count($capCurrencies) === 1 ? $capCurrencies[0] : null,
            // Limits whose remainder could not be computed (unconverted) are outside the total — said, never silently dropped.
            'cap_left_out' => count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'unconverted')),
            'used' => $cap !== null && $cap > 0 && $remaining !== null ? round(($cap - $remaining) / $cap, 4) : null,
            'near' => count(array_filter($rows, static fn (array $r): bool => in_array($r['state'], ['near', 'over'], true))),
            'projects' => count($rows),
        ];
    }
}
