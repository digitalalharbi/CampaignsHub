<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * LIVEREP-002 — build a live client link from a CHOICE, not from a document.
 *
 * ## Why this exists alongside `ReportShareController`
 *
 * Sharing used to start from a report that had already been generated: pick a finished document, then
 * decide who may see it. That is the right order for a signed-off monthly PDF and the wrong one for
 * the question this product is actually for — «show this client how their campaigns are doing». An
 * operator answering that does not have a document in mind; they have a client, a project, some
 * campaigns and a date range. Forcing them to generate a report first is a step that exists only
 * because of how the storage happened to be arranged.
 *
 * So this endpoint takes the choice and produces the link. It still creates a `Report` row, because
 * that row is what carries currency, period and ownership, and because everything downstream —
 * download, export, audit, access logs — is already keyed to it. What it does NOT do is generate a
 * snapshot: the report is a record of what was shared, and the figures are computed live on each view.
 *
 * ## What is verified before a link exists
 *
 * The campaigns must belong to the chosen project, checked against the database rather than trusted
 * from the request. That is the same fail-closed rule `LiveReportService` enforces at read time, and
 * it is enforced here as well because the two protect different things: this stops a bad ceiling from
 * ever being STORED, and that stops a stored ceiling from being exceeded. A ceiling written wrong
 * would otherwise pass every read-time check, because it would be exactly what the share said.
 */
final class LiveReportBuilderController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    /**
     * The choices available to build a link with: this project's campaigns and the platforms it has
     * data for. Served rather than guessed, so the picker can never offer something the ceiling would
     * then reject.
     */
    public function options(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.share'), 403);

        $campaigns = UnifiedCampaign::query()
            ->where('project_id', $project)
            ->orderBy('name')
            ->get(['id', 'name', 'client_display_name', 'status'])
            ->map(fn (UnifiedCampaign $c): array => [
                'id' => (string) $c->id,
                'name' => (string) ($c->client_display_name ?: $c->name),
                'status' => $c->status,
            ])->all();

        /*
         * Platforms are read from the METRICS, not from the integrations list.
         *
         * A platform that is connected but has never returned a row would appear in a picker built
         * from integrations, and a link scoped to it would show the client an empty chart with no
         * explanation. This offers what there is data for; the freshness strip on the client's page is
         * what reports the connected-but-silent case honestly.
         */
        $providers = DailyMetric::query()
            ->where('project_id', $project)
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();

        return ApiResponse::success([
            'campaigns' => $campaigns,
            'providers' => $providers,
            'metrics' => self::METRICS,
        ], 'Builder options.');
    }

    /**
     * The metrics a link may show, in the order a reader wants them.
     *
     * A fixed list rather than the whole metric catalogue: this is the set that means something to a
     * CLIENT. `cpm` and `frequency` are real and useful to an operator and would be noise on a page
     * whose job is to answer «is my money working?».
     */
    private const METRICS = [
        ['key' => 'spend', 'ar' => 'الإنفاق', 'en' => 'Spend'],
        ['key' => 'impressions', 'ar' => 'الظهور', 'en' => 'Impressions'],
        ['key' => 'clicks', 'ar' => 'النقرات', 'en' => 'Clicks'],
        ['key' => 'ctr', 'ar' => 'نسبة النقر', 'en' => 'CTR'],
        ['key' => 'conversions', 'ar' => 'النتائج', 'en' => 'Results'],
        ['key' => 'add_to_cart', 'ar' => 'الإضافات للسلة', 'en' => 'Add to cart'],
        ['key' => 'purchases', 'ar' => 'المشتريات', 'en' => 'Purchases'],
        ['key' => 'revenue', 'ar' => 'الإيرادات', 'en' => 'Revenue'],
        ['key' => 'roas', 'ar' => 'العائد على الإنفاق', 'en' => 'ROAS'],
        ['key' => 'cpa', 'ar' => 'تكلفة النتيجة', 'en' => 'Cost per result'],
    ];

    /** Build the report row + its live share, and return the link once. */
    public function store(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.share'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'campaign_ids' => ['array'],
            'campaign_ids.*' => ['uuid'],
            'providers' => ['array'],
            'providers.*' => ['string', 'max:40'],
            'metrics' => ['array'],
            'metrics.*' => ['string', 'max:40'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'hide_spend' => ['boolean'],
            'hide_revenue' => ['boolean'],
            'allow_download' => ['boolean'],
        ]);

        /*
         * Every campaign must actually be in this project.
         *
         * Checked against the database rather than trusted: an id pasted from another client's project
         * would otherwise be written into the ceiling, and from then on every read-time check would
         * approve it — because it would be exactly what the share was granted.
         */
        $requested = array_values(array_unique((array) ($data['campaign_ids'] ?? [])));
        $campaignIds = UnifiedCampaign::query()
            ->where('project_id', $project)
            ->when($requested !== [], fn ($q) => $q->whereIn('id', $requested))
            ->pluck('id')
            ->map(strval(...))
            ->all();

        abort_if($campaignIds === [], 422, 'No campaigns in this project to share.');

        $providers = array_values(array_unique((array) ($data['providers'] ?? [])));
        if ($providers === []) {
            $providers = DailyMetric::query()->where('project_id', $project)->distinct()->pluck('provider')->all();
        }

        $metrics = array_values(array_intersect(
            (array) ($data['metrics'] ?? []),
            array_column(self::METRICS, 'key'),
        ));

        $report = Report::create([
            'project_id' => $project,
            'name' => $data['name'],
            'type' => 'live',
            'audience' => 'client',
            'status' => 'completed',
            'period_start' => $data['from'],
            'period_end' => $data['to'],
            'currency' => ReportingCurrency::DEFAULT,
            'created_by' => $request->user()->id,
            'generated_at' => Carbon::now(),
            /*
             * No `data` snapshot on purpose — this report IS the live definition, and a stale copy
             * beside it would be a second answer nobody asked for.
             */
            'config' => ['live' => true, 'metrics' => $metrics],
        ]);

        [$share, $raw] = $this->shares->create($report, [
            'password' => $data['password'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'allow_download' => $data['allow_download'] ?? false,
            'hide_spend' => $data['hide_spend'] ?? false,
            'hide_revenue' => $data['hide_revenue'] ?? false,
            'scope' => [
                'project_id' => (string) $project,
                'campaign_ids' => $campaignIds,
                'providers' => $providers,
                'metrics' => $metrics,
                'earliest' => Carbon::parse($data['from'])->toDateString(),
                // Open-ended forward, because «how is it going?» is a question about today.
                'latest' => Carbon::now()->addYear()->toDateString(),
            ],
        ], $request->user()->id);

        $audit->log(
            action: 'report.live_link_created',
            entityType: ReportShare::class,
            entityId: (string) $share->id,
            after: ['project_id' => $project, 'campaigns' => count($campaignIds), 'providers' => $providers],
        );

        return ApiResponse::success([
            'report_id' => (string) $report->id,
            'share_id' => (string) $share->id,
            'url' => ShareService::urlFor($raw), 'path' => ShareService::pathFor($raw),
            'token' => $raw,
        ], 'Live link created. Copy it now — it is shown only once.', status: 201);
    }
}
