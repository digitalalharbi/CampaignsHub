<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportSourceContainment;
use App\Domains\Reports\Support\ReportScope;
use Illuminate\Console\Command;

/**
 * REPORT-SOURCE-ISOLATION-001 — which stored reports hold content outside the ad accounts they name. READ-ONLY.
 *
 * The generator bounds the content section by the report's accounts now; copies stored before that may hold the creatives of
 * accounts the report never named. Those copies are served contained ({@see ReportSourceContainment}) and are not rewritten.
 * This lists them, with the same rule the served document is cut by, so the count is the count of what a reader no longer sees.
 *
 * It writes nothing: no report, link, version or export is touched, and there is no option that makes it. Ids are printed as
 * their first eight characters; no name, token or figure is printed. A rebuild is a separate, confirmed act per report.
 */
final class ReportSourceIsolationAuditCommand extends Command
{
    protected $signature = 'reports:source-isolation-audit
        {--json : Machine-readable output}
        {--tenant= : Only this tenant id}';

    protected $description = 'Read-only: list stored reports whose content names creatives outside the ad accounts the report was made for.';

    public function handle(ReportSourceContainment $containment): int
    {
        $scanned = 0;
        $affected = [];

        Report::withoutGlobalScopes()
            ->whereNotNull('scope')
            ->whereNotNull('data')
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', (string) $tenant))
            ->orderBy('id')
            ->chunkById(20, function ($reports) use ($containment, &$scanned, &$affected): void {
                foreach ($reports as $report) {
                    $accounts = ReportScope::fromReport(is_array($report->scope) ? $report->scope : null, (string) $report->project_id)->accountIds;
                    $data = is_array($report->data) ? $report->data : [];
                    if ($accounts === [] || $data === []) {
                        continue;
                    }

                    $scanned++;
                    $outside = (int) ($containment->apply($data, $report)['creatives_source_contained'] ?? 0);
                    if ($outside === 0) {
                        continue;
                    }

                    $links = ReportShare::withoutGlobalScopes()->where('report_id', $report->getKey())->whereNull('revoked_at');
                    $affected[] = [
                        'report' => substr((string) $report->getKey(), 0, 8),
                        'tenant' => substr((string) $report->tenant_id, 0, 8),
                        'project' => substr((string) $report->project_id, 0, 8),
                        'generated_at' => $report->generated_at?->toDateString(),
                        'accounts_named' => count($accounts),
                        'content_rows' => is_array($data['ads_roster'] ?? null) ? count($data['ads_roster']) : 0,
                        'outside' => $outside,
                        'links' => (clone $links)->count(),
                        'snapshot_links' => (clone $links)->where('mode', 'snapshot')->count(),
                    ];
                }
            });

        $summary = [
            'reports_with_account_sources' => $scanned,
            'reports_holding_outside_content' => count($affected),
            'creatives_outside' => array_sum(array_column($affected, 'outside')),
            'links_on_them' => array_sum(array_column($affected, 'links')),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'reports' => $affected]));

            return self::SUCCESS;
        }

        $this->table(array_keys($summary), [$summary]);
        if ($affected !== []) {
            $this->table(array_keys($affected[0]), $affected);
        }
        $this->line('Nothing was written. Stored copies are served without the outside content and are not rewritten.');

        return self::SUCCESS;
    }
}
