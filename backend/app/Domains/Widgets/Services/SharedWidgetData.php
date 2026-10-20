<?php

declare(strict_types=1);

namespace App\Domains\Widgets\Services;

use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Metrics\Settings\AttributionSettings;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Services\TrackingSummary;
use Illuminate\Support\Carbon;

/**
 * WIDGET-REGISTRY-001 — what a shared client link may show of the widgets, decided and shaped on the server.
 *
 * The client never asks «which widgets?»: it is handed exactly the ones the project and the link both show, in the
 * order they chose, with data cut down to what a client may see. Everything else is absent from the response, not
 * merely hidden by the page — the same reason the attribution section has its own address.
 *
 * What is removed for a client, on purpose:
 *  - machine-open counts, devices per day, per-link slugs and event types — they are the agency's diagnostics, not
 *    the client's results;
 *  - every revenue figure — the widgets draw orders, and revenue stays under the link's own hide flags where it
 *    already lives;
 *  - the whole group on a link narrower than its project, because the store ledger and the touchpoints are
 *    project-wide and a figure bounded by one half of that would be a discrepancy invented by the filter.
 *
 * What is kept, because hiding it would falsify the picture: the share of orders with no verified evidence, the
 * orders left out as fully refunded, the orders whose revenue is withheld, and the sentence that a click is not a
 * purchase. A widget cannot be shown without those.
 */
final class SharedWidgetData
{
    public function __construct(
        private readonly WidgetLayouts $layouts,
        private readonly TrackingSummary $tracking,
        private readonly AttributionTransparency $transparency,
        private readonly AttributionSettings $attribution,
    ) {}

    /**
     * @return list<array{key: string, kind: string, size: string, title: array<string,string>, data: array<string,mixed>}>
     */
    public function forShare(ReportShare $share, Report $report): array
    {
        if ($share->narrowerThanItsProject()) {
            return [];
        }

        $tenant = Tenant::query()->find((string) $share->tenant_id);
        $project = Project::withoutGlobalScopes()->where('tenant_id', (string) $share->tenant_id)->whereKey((string) $report->project_id)->first();
        if ($tenant === null || $project === null) {
            return [];
        }

        $period = (array) (($report->data ?? [])['period'] ?? []);
        $to = isset($period['to']) ? Carbon::parse((string) $period['to']) : Carbon::today();
        $from = isset($period['from']) ? Carbon::parse((string) $period['from']) : $to->copy()->subDays(29);

        $out = [];
        foreach ($this->layouts->resolve($tenant, $project, 'report', (string) $share->getKey()) as $widget) {
            if (! $widget['visible']) {
                continue;
            }
            $data = match ($widget['key']) {
                'link_opens' => $this->linkOpens((string) $tenant->getKey(), (string) $project->getKey(), $from, $to),
                'attribution_credit' => $this->attributionCredit((string) $tenant->getKey(), (string) $project->getKey(), $from, $to),
                default => null,
            };
            if ($data === null) {
                continue;
            }
            $out[] = ['key' => $widget['key'], 'kind' => $widget['kind'], 'size' => $widget['size'], 'title' => $widget['title'], 'data' => $data];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function linkOpens(string $tenantId, string $projectId, Carbon $from, Carbon $to): ?array
    {
        $s = $this->tracking->build($tenantId, $projectId, $from, $to);
        if ($s['state'] === 'no_events') {
            return null;
        }

        return [
            'state' => $s['state'],
            'period' => $s['period'],
            'tracking_since' => $s['tracking_since'],
            'opens' => $s['opens'],
            'series' => array_map(static fn (array $p): array => ['period' => $p['period'], 'opens' => $p['opens']], $s['series']),
            'by_destination' => $s['by_destination'],
            // What the numbers are, and are not. The device estimate is the agency's diagnostic and is not here.
            'basis' => ['counts' => $s['basis']['counts'], 'includes_sales' => $s['basis']['includes_sales']],
        ];
    }

    /** @return array<string,mixed>|null */
    private function attributionCredit(string $tenantId, string $projectId, Carbon $from, Carbon $to): ?array
    {
        $config = $this->attribution->creditConfig($tenantId, $projectId);
        if ($config === null) {
            return null;
        }

        $credit = $this->transparency->build($tenantId, $projectId, $from, $to, [], $config)['model_comparison']['credit_models'] ?? null;
        if (! is_array($credit)) {
            return null;
        }

        return [
            'version' => $credit['version'],
            'evidence_basis' => $credit['evidence_basis'],
            'models' => $credit['models'],
            'lookback_days' => $credit['lookback_days'],
            'coverage' => $credit['coverage'],
            'channels' => array_map(static fn (array $c): array => [
                'channel' => $c['channel'],
                'kind' => $c['kind'],
                // Orders only: revenue stays where the link's hide flags govern it.
                'models' => array_map(static fn (array $m): array => ['orders' => $m['orders']], $c['models']),
            ], $credit['channels']),
        ];
    }
}
