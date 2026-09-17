<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Domains\Reports\Support\ReportComposition;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * REPORT-DRILLDOWN-001 — the PDF's optional platform drill-down section.
 *
 * Off unless the operator enabled it on the report ({@see ReportBreakdowns::forReport()}). When on,
 * one block per platform in the report's own scope and period, built by the same
 * {@see PlatformDrilldownBuilder} the live drawer uses, so the file and the link cannot disagree about
 * a platform's share.
 *
 * Figures are the report's PERIOD, recomputed at print time over the report's scope — the same
 * aggregator, bindings and all, the generator used. Content carries its media resolved NOW
 * (`liveMedia`), for the reason `ReportCreativeMedia` gives: a stored signed URL has expired by the
 * time anyone prints; the renderer prints the picture or the absence wording, never a fabricated one.
 *
 * The client path is platform → content. Every block passes the campaign-entity guard, and the whole
 * section is dropped — not partially printed — if one fails it.
 */
final class ReportPlatformDrilldowns
{
    private const CONTENT_PER_LIST = 4;

    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly ReportAds $ads,
        private readonly PlatformDrilldownBuilder $builder,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the client-filtered document the print route is about to send
     * @return list<array<string, mixed>>
     */
    public function forPrint(Report $report, array $data): array
    {
        $breakdowns = ReportBreakdowns::forReport($report, 'pdf');
        if (! $breakdowns[ReportBreakdowns::PLATFORM]) {
            return [];
        }

        app(TenantContext::class)->setTenantId((string) $report->tenant_id);
        app(ProjectContext::class)->setProjectId((string) $report->project_id);

        $period = (array) ($data['period'] ?? []);
        $to = Carbon::parse((string) ($period['to'] ?? $report->period_end ?? Carbon::today()));
        $from = Carbon::parse((string) ($period['from'] ?? $report->period_start ?? $to->copy()->subDays(29)));

        $scope = ReportScope::fromArray($report->scope);
        $engine = $scope->applyTo($this->metrics);
        $whole = $engine->byProvider($from, $to);

        $projectIds = $scope->projectIds !== [] ? $scope->projectIds : [(string) $report->project_id];
        $lens = new ReportObjectiveLens((string) ($report->campaign_objective ?? $data['objective'] ?? 'custom'));
        $form = ReportComposition::for($report->form);

        $blocks = [];
        foreach ($whole as $row) {
            $provider = (string) ($row['provider'] ?? '');
            if ($provider === '') {
                continue;
            }

            // The section's own content lists: part of the platform drill-down on paper, and gone with a
            // hidden ads section — a document never prints content its operator took out.
            $content = ! ReportBreakdowns::reportSlideHidden($report, 'ads')
                ? $this->ads->for($lens->value(), $from, $to, [
                    'project_ids' => $projectIds,
                    'providers' => [$provider],
                    'campaign_ids' => $scope->resolvedCampaignIds() ?? [],
                    'creative_ids' => $scope->creativeIds,
                ], $form->form, liveMedia: true)
                : null;

            $blocks[] = $form->apply($this->builder->build(
                provider: $provider,
                whole: $whole,
                engine: $engine->forProviders([$provider]),
                objectives: new ObjectivePerformance(
                    projectIds: $projectIds,
                    campaignIds: $scope->resolvedCampaignIds(),
                    providers: [$provider],
                    accountIds: $scope->accountIds === [] ? null : $scope->accountIds,
                ),
                lens: $lens,
                from: $from,
                to: $to,
                hideSpend: false,
                hideRevenue: false,
            ) + [
                'ads' => ClientEntityBoundary::ads(array_slice($content['ads'] ?? [], 0, self::CONTENT_PER_LIST)),
                'ads_weakest' => ClientEntityBoundary::ads(array_slice($content['worst'] ?? [], 0, self::CONTENT_PER_LIST)),
            ]);
        }

        $violations = ClientReportContentValidator::campaignEntities(['platform_drilldowns' => $blocks]);
        if ($violations !== []) {
            Log::error('pdf drill-down section dropped: campaign entity in a client document', [
                'report_id' => (string) $report->id,
                'paths' => array_column($violations, 'path'),
            ]);

            return [];
        }

        return $blocks;
    }
}
