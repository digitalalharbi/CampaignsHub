<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Agents\Models\ClientAgentRule;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

/**
 * AGENTS-TOOLS-READ-001 — what an agent may READ, through the same engine every report reads.
 *
 * No query here touches a metrics table directly: `MetricsAggregator` carries the tenant scope, the
 * bound-account visibility, the currency truth (withheld is never zero) and the derived ratios, so an
 * agent sees exactly the figures the client's report shows and nothing a report would refuse.
 *
 * Every answer states its window and currency basis, because a model that is handed a number without its
 * period will compare it with the wrong one.
 */
final class AgentReadTools
{
    public function __construct(private readonly MetricsAggregator $metrics) {}

    /**
     * list_campaigns — the project's campaigns over a window, with the previous window for trend.
     *
     * @return array{window: array{from: string, to: string}, previous: array{from: string, to: string}, campaigns: list<array<string, mixed>>}
     */
    public function listCampaigns(Project $project, int $days = 7): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($days);

        $rows = $this->engine($project)->byCampaign($from, $to, $prevFrom, $prevTo);

        // The platform's own ids and current daily budget: what propose_change needs to name a target.
        $platform = ExternalCampaign::query()->where('project_id', $project->getKey())
            ->whereIn('unified_campaign_id', array_filter(array_column($rows, 'campaign_id')))
            ->get(['unified_campaign_id', 'provider', 'external_account_id', 'external_id', 'daily_budget'])
            ->groupBy('unified_campaign_id');

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'campaigns' => array_values(array_map(fn (array $r): array => $this->slim($r) + [
                'on_platform' => ($platform[$r['campaign_id'] ?? ''] ?? collect())->map(fn (ExternalCampaign $e): array => [
                    'provider' => $e->provider, 'external_account_id' => (string) $e->external_account_id,
                    'target_external_id' => $e->external_id, 'daily_budget' => $e->daily_budget,
                ])->values()->all(),
            ], $rows)),
        ];
    }

    /**
     * get_history — one campaign's daily series.
     *
     * @return array{campaign_id: string, from: string, to: string, days: list<array<string, mixed>>}
     */
    public function getHistory(Project $project, string $campaignId, int $days = 30): array
    {
        $to = Carbon::yesterday()->endOfDay();
        $from = $to->copy()->subDays(max(1, min($days, 90)) - 1)->startOfDay();

        return [
            'campaign_id' => $campaignId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => array_values($this->engine($project)->forCampaign($campaignId)->timeseries($from, $to)),
        ];
    }

    /**
     * compare_targets — every campaign against the client's own rules, judged only when it has spent enough.
     *
     * @return array{window: array{from: string, to: string}, rules: int, verdicts: list<array<string, mixed>>}
     */
    public function compareTargets(Project $project, int $days = 7): array
    {
        $listing = $this->listCampaigns($project, $days);
        $rules = ClientAgentRule::query()->where('project_id', $project->getKey())->get();

        $verdicts = [];
        foreach ($listing['campaigns'] as $c) {
            foreach ($rules as $rule) {
                if ($rule->platform !== null && ($c['provider'] ?? null) !== $rule->platform) {
                    continue;
                }

                $verdicts[] = [
                    'campaign_id' => $c['campaign_id'],
                    'campaign_name' => $c['campaign_name'],
                    'kpi' => $rule->kpi,
                    'target' => $rule->target_value,
                    'direction' => $rule->direction,
                    'value' => $c[$rule->kpi] ?? null,
                    'spend' => $c['spend'] ?? null,
                    'verdict' => $this->verdict($rule, $c),
                ];
            }
        }

        return ['window' => $listing['window'], 'rules' => $rules->count(), 'verdicts' => $verdicts];
    }

    /**
     * on_target | off_target | not_judged (too little spend) | no_figure (the KPI is not measured — never read as zero)
     *
     * @param  array<string, mixed>  $row
     */
    public function verdict(ClientAgentRule $rule, array $row): string
    {
        $spend = $row['spend'] ?? null;
        if ($rule->min_spend_to_judge !== null && (! is_numeric($spend) || (float) $spend < $rule->min_spend_to_judge)) {
            return 'not_judged';
        }

        $value = $row[$rule->kpi] ?? null;
        if (! is_numeric($value)) {
            return 'no_figure';
        }

        $ok = $rule->direction === 'max' ? (float) $value <= $rule->target_value : (float) $value >= $rule->target_value;

        return $ok ? 'on_target' : 'off_target';
    }

    private function engine(Project $project): MetricsAggregator
    {
        return $this->metrics->forProjects([(string) $project->getKey()]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} */
    private function windows(int $days): array
    {
        $days = max(1, min($days, 90));
        $to = Carbon::yesterday()->endOfDay();
        $from = $to->copy()->subDays($days - 1)->startOfDay();
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [$from, $to, $prevFrom, $prevTo];
    }

    /**
     * The figures an agent needs, and nothing that names our internals.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function slim(array $r): array
    {
        $keep = ['campaign_id', 'campaign_name', 'provider', 'status', 'objective', 'outcome', 'spend', 'previous_spend', 'spend_change', 'impressions', 'clicks', 'conversions', 'revenue', 'leads', 'purchases', 'cpa', 'roas', 'ctr', 'cpc', 'cpm', 'cpl', 'cpi', 'conversion_rate', 'spend_withheld_rows', 'last_active_on'];

        return array_intersect_key($r, array_flip($keep));
    }
}
