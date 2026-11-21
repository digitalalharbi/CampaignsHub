<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Support\AnalyticsReadCache;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Support\ReportScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ANALYTICS-REPORT-LOGIC-001 — the analytics board reads the report's OWN payload.
 *
 * The owner's rule is that a figure on the board and the same figure on the /r/ report, for one period and one
 * project, can never differ. The only way to make that true rather than tested is to compute both with the same
 * code: this builds the live-report payload through {@see LiveReportService} for the route's own tenant and project,
 * over an in-memory (never stored) share whose ceiling is the whole project and which hides nothing — the board is
 * the operator's page, so it carries spend, revenue and campaigns exactly as the operator's own report does.
 *
 * Nothing is written. The answer is cached per tenant AND project AND the full filter set (see
 * {@see AnalyticsReadCache}), so one reader's filters are never served to another's and a repeat open costs nothing.
 */
final class AnalyticsReportView
{
    public function __construct(private readonly LiveReportService $live) {}

    /**
     * @param  list<string>  $providers  empty = every platform
     * @param  list<string>  $campaigns  empty = every campaign
     * @return array<string, mixed>
     */
    public function payload(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $providers = [], array $campaigns = [], string $currency = 'SAR'): array
    {
        sort($providers);
        sort($campaigns);
        $variant = $from->toDateString().'|'.$to->toDateString().'|'.implode(',', $providers).'|'.implode(',', $campaigns).'|'.$currency;

        return AnalyticsReadCache::remember('report-view', $tenantId, $projectId, hash('sha256', $variant), fn (): array => $this->compute($tenantId, $projectId, $from, $to, $providers, $campaigns, $currency));
    }

    /**
     * @param  list<string>  $providers
     * @param  list<string>  $campaigns
     * @return array<string, mixed>
     */
    private function compute(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $providers, array $campaigns, string $currency): array
    {
        $report = new Report([
            'tenant_id' => $tenantId, 'project_id' => $projectId, 'name' => 'analytics-view', 'type' => 'live', 'form' => 'detailed',
            'audience' => 'operator', 'status' => 'completed', 'currency' => $currency,
            'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
            'config' => ['live' => true, 'metrics' => [], 'period_kind' => 'custom', 'period_mode' => 'fixed'],
        ]);
        $report->setAttribute('id', (string) Str::uuid());

        /*
         * A campaign filter narrows the CEILING, not the request: the report's own query string cannot name campaigns
         * (a reader of a link never could), so the choice is made where the report makes it — in what the link grants —
         * and it is intersected with the project's own campaigns, so an id from another project can never get in.
         */
        $ceilingCampaigns = UnifiedCampaign::query()->where('project_id', $projectId)
            ->when($campaigns !== [], fn ($q) => $q->whereIn('id', $campaigns))
            ->pluck('id')->map(strval(...))->all();

        $share = new ReportShare([
            'tenant_id' => $tenantId, 'report_id' => (string) $report->getKey(), 'mode' => 'live', 'form' => 'detailed', 'allow_download' => false,
            'hide_spend' => false, 'hide_revenue' => false, 'hide_campaign_names' => false, 'watermark' => false, 'settings' => [],
            'scope' => [
                'project_id' => $projectId, 'campaign_ids' => $ceilingCampaigns === [] ? [ReportScope::IMPOSSIBLE] : $ceilingCampaigns, 'dynamic_campaigns' => false, 'account_ids' => [],
                'providers' => $providers, 'metrics' => [], 'earliest' => $from->toDateString(), 'latest' => $to->toDateString(),
            ],
        ]);
        $share->setAttribute('id', (string) Str::uuid());
        $share->setRelation('report', $report);

        $request = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        if ($providers !== []) {
            $request['providers'] = $providers;
        }

        return $this->live->build($share, $request, $currency);
    }
}
