<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareAnalytics;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\ReportColumns;
use App\Domains\Reports\Support\ReportPeriod;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Reports\Support\ShareSections;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Manage secure client links for a report (create/list/revoke + access logs). Requires reports.share. */
final class ReportShareController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    public function index(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        $shares = ReportShare::where('report_id', $model->id)->withCount('logs')->latest()->get()
            ->map(fn (ReportShare $s) => $this->shape($s));

        return ApiResponse::success($shares, 'Shares retrieved.');
    }

    public function store(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        abort_unless($model->status === 'completed', 409, 'Generate the report before sharing.');
        // An internal report is never shareable externally — a client version must be created first.
        abort_if(
            ($model->audience ?? 'client') === 'internal',
            422, 'This report must be converted to a client version before sharing.',
        );

        $opts = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allow_download' => ['boolean'],
            'hide_spend' => ['boolean'],
            'hide_revenue' => ['boolean'],
            'hide_campaign_names' => ['boolean'],
            'watermark' => ['boolean'],
            // LIVEREP-001 — asking for a live link.
            'live' => ['boolean'],
            'campaign_ids' => ['array'],
            'campaign_ids.*' => ['uuid'],
            'providers' => ['array'],
            'providers.*' => ['string', 'max:40'],
            'earliest' => ['nullable', 'date'],
            'latest' => ['nullable', 'date'],

            /*
             * §15.12 — the two facts that were entangled, now stated separately.
             *
             * `form` is how much of the report this link is; `mode` is where its numbers come from.
             * All four combinations are valid, and neither is derived from the other.
             */
            'form' => ['nullable', 'in:executive_summary,detailed'],
            'mode' => ['nullable', 'in:live,snapshot'],

            // The creative ceiling — an allow-list, a group allow-list, and a deny-list.
            'creative_ids' => ['array'],
            'creative_ids.*' => ['uuid'],
            'creative_group_ids' => ['array'],
            'creative_group_ids.*' => ['uuid'],
            'excluded_creative_ids' => ['array'],
            'excluded_creative_ids.*' => ['uuid'],
            'objectives' => ['array'],
            'objectives.*' => ['string', 'max:40'],
            'paths' => ['array'],
            'paths.*' => ['string', 'max:40'],

            // The visibility switches. Absent means OFF — see CreativeVisibility.
            'creatives' => ['array'],
            'creatives.*' => ['boolean'],
            'sections' => ['array'],
            'sections.*' => ['boolean'],
            // REPORT-TABLES-OWNER-COLUMNS-001 — the columns each table publishes; unknown keys are cut, absent means all.
            'columns' => ['array'],
            'columns.*' => ['array'],
            'columns.*.*' => ['string', 'max:40'],
        ]);

        /*
         * A live link needs a ceiling; a snapshot link may have one too.
         *
         * The scope used to be built only for live links, because it existed only to bound live
         * recomputation. It now also carries which creatives a link may show, which is a bound a
         * FROZEN report needs just as much — a snapshot whose creative section showed content the
         * operator excluded would be the same disclosure, arriving from a stored payload.
         */
        $wantsLive = ($opts['mode'] ?? null) === 'live' || ! empty($opts['live']);
        $bounded = $wantsLive
            || array_filter([
                $opts['creative_ids'] ?? [], $opts['creative_group_ids'] ?? [],
                $opts['excluded_creative_ids'] ?? [], $opts['objectives'] ?? [], $opts['paths'] ?? [],
            ]) !== [];

        if ($bounded) {
            $opts['scope'] = $this->scopeFor($model, $opts);
        }

        // Stated, never inferred. `ShareService` still falls back to the old «a scope means live»
        // reading for its other callers; from an operator's form the choice is always explicit, so a
        // link cannot become live because the operator picked which creatives it may show.
        $opts['mode'] = $wantsLive ? 'live' : 'snapshot';

        // Stored resolved, not as ticked: `roas` beside a hidden spend is a published spend, and the
        // value object closes that combination once rather than at every surface that renders it.
        $opts['settings'] = [
            'creatives' => CreativeVisibility::fromArray($opts['creatives'] ?? [])->toArray(),
            /*
             * Which optional sections this link may open — ATTRIB-VIS-001.
             *
             * Resolved the same way and for the same reason: an absent or malformed key becomes
             * «closed», so a link cannot acquire a section by omission.
             */
            /*
             * REPORT-CLIENT-NO-DELTAS-001 — «compared with the previous period» is OFF on a new client link
             * unless the Owner switched it on. It is the agency's own working view (it invites a question
             * about last week the client was never promised an answer to), so a link that did not ask for
             * it does not carry it. Links created before this keep what they were stored with.
             */
            'sections' => ShareSections::fromArray(($opts['sections'] ?? []) + ['previous_comparison' => false])->toArray(),
            'columns' => ReportColumns::fromArray($opts['columns'] ?? [])->toArray(),
        ];

        [$share, $raw] = $this->shares->create($model, $opts, $request->user()->id);
        $audit->log(action: 'report.shared_link_created', entityType: ReportShare::class, entityId: (string) $share->id);

        return ApiResponse::success(
            $this->shape($share) + ['url' => ShareService::urlFor($raw), 'path' => ShareService::pathFor($raw), 'token' => $raw],
            'Secure link created. Copy it now — it is shown only once.',
            status: 201,
        );
    }

    public function revoke(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findShare($this->findReport($report), $share);
        $model->update(['revoked_at' => Carbon::now()]);
        $audit->log(action: 'report.shared_link_revoked', entityType: ReportShare::class, entityId: (string) $model->id);

        return ApiResponse::success($this->shape($model), 'Link revoked.');
    }

    /**
     * Pause a link: the URL stops opening for everyone at once, and nothing about it is lost.
     *
     * REPORT-LINK-PAUSE-001. Revoking is permanent; this is the reversible switch — the settings, the URL and the
     * access history stay exactly as they are, and resuming brings the same link back. A revoked link cannot be
     * paused or resumed: a deliberate cut-off is not something a toggle may reverse.
     */
    public function pause(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findShare($this->findReport($report), $share);
        abort_if($model->revoked_at !== null, 409, 'A revoked link cannot be paused or resumed.');
        if ($model->paused_at === null) {
            $model->update(['paused_at' => Carbon::now()]);
            $audit->log(action: 'report.shared_link_paused', entityType: ReportShare::class, entityId: (string) $model->id);
        }

        return ApiResponse::success($this->shape($model), 'Link paused.');
    }

    /** Resume a paused link — the same URL, the same settings. Resuming an active link changes nothing. */
    public function resume(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findShare($this->findReport($report), $share);
        abort_if($model->revoked_at !== null, 409, 'A revoked link cannot be paused or resumed.');
        if ($model->paused_at !== null) {
            $model->update(['paused_at' => null]);
            $audit->log(action: 'report.shared_link_resumed', entityType: ReportShare::class, entityId: (string) $model->id);
        }

        return ApiResponse::success($this->shape($model), 'Link resumed.');
    }

    /**
     * Push a link's expiry out — the alternative to revoking one link and issuing another.
     *
     * Worth its own endpoint because the workaround is worse than it looks: re-issuing means the client
     * must be sent a new URL, the old one 404s in whatever email or chat it was pasted into, and the
     * access history of the relationship is split across two rows. Renewing keeps one link, one history,
     * one URL the client has already bookmarked.
     *
     * A revoked link is NOT renewable. Revocation is a decision someone made about a recipient, and
     * quietly reversing it by extending a date would turn a deliberate cut-off into a date change.
     */
    public function renew(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findShare($this->findReport($report), $share);
        abort_if($model->revoked_at !== null, 409, 'A revoked link cannot be renewed — create a new one.');

        $data = $request->validate(['expires_at' => ['required', 'date', 'after:now']]);
        $model->update(['expires_at' => $data['expires_at']]);
        $audit->log(action: 'report.shared_link_renewed', entityType: ReportShare::class, entityId: (string) $model->id);

        return ApiResponse::success($this->shape($model), 'Link renewed.');
    }

    /**
     * PATCH …/shares/{share} — REPORT-LINK-EDIT-IN-PLACE-001: the Owner changes a published link and the SAME
     * URL reflects it at once.
     *
     * The token, the URL and the mode (live or snapshot) are the link's identity and never change here; what
     * the Owner decided about it can: the hide switches, download, watermark, expiry, the password (set, or
     * cleared with `clear_password`), the form, the creative and section switches, and the ceiling. The values
     * go through the SAME value objects the creation path uses, so an edit can never store a combination that
     * creating the link would have refused. Everything is applied when the link is next opened — nothing is
     * copied into a second link, and nobody is asked to resend a URL.
     */
    public function update(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        $link = $this->findShare($model, $share);
        abort_unless((string) $link->report_id === (string) $model->id, 404);
        abort_if($link->revoked_at !== null, 409, 'A revoked link cannot be edited.');

        $opts = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'clear_password' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'clear_expiry' => ['boolean'],
            'allow_download' => ['boolean'],
            'hide_spend' => ['boolean'],
            'hide_revenue' => ['boolean'],
            'hide_campaign_names' => ['boolean'],
            'watermark' => ['boolean'],
            'form' => ['nullable', 'in:executive_summary,detailed'],
            'campaign_ids' => ['array'],
            'campaign_ids.*' => ['uuid'],
            'providers' => ['array'],
            'providers.*' => ['string', 'max:40'],
            'earliest' => ['nullable', 'date'],
            'latest' => ['nullable', 'date'],
            'creative_ids' => ['array'],
            'creative_ids.*' => ['uuid'],
            'creative_group_ids' => ['array'],
            'creative_group_ids.*' => ['uuid'],
            'excluded_creative_ids' => ['array'],
            'excluded_creative_ids.*' => ['uuid'],
            'objectives' => ['array'],
            'objectives.*' => ['string', 'max:40'],
            'paths' => ['array'],
            'paths.*' => ['string', 'max:40'],
            'creatives' => ['array'],
            'creatives.*' => ['boolean'],
            'sections' => ['array'],
            'sections.*' => ['boolean'],
            // REPORT-TABLES-OWNER-COLUMNS-001 — the published link's columns may be changed after creation.
            'columns' => ['array'],
            'columns.*' => ['array'],
            'columns.*.*' => ['string', 'max:40'],
        ]);

        $before = $link->only(['allow_download', 'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark', 'form', 'expires_at']);
        $fill = array_intersect_key($opts, array_flip(['allow_download', 'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark']));

        if (array_key_exists('form', $opts)) {
            $fill['form'] = $opts['form'];
        }
        if (! empty($opts['clear_password'])) {
            $fill['password_hash'] = null;
        } elseif (! empty($opts['password'])) {
            $fill['password_hash'] = Hash::make($opts['password']);
        }
        if (! empty($opts['clear_expiry'])) {
            $fill['expires_at'] = null;
        } elseif (! empty($opts['expires_at'])) {
            $fill['expires_at'] = $opts['expires_at'];
        }

        $settings = (array) ($link->settings ?? []);
        if (array_key_exists('creatives', $opts)) {
            $settings['creatives'] = CreativeVisibility::fromArray($opts['creatives'])->toArray();
        }
        if (array_key_exists('sections', $opts)) {
            // Merged over what the link already has: naming one switch must not reset the others to their default.
            $settings['sections'] = ShareSections::fromArray(array_replace((array) ($settings['sections'] ?? []), $opts['sections']))->toArray();
        }
        if (array_key_exists('columns', $opts)) {
            // The whole choice at once: a table named is replaced, a table absent means every column — exactly as at creation.
            $settings['columns'] = ReportColumns::fromArray($opts['columns'])->toArray();
        }
        $fill['settings'] = $settings;

        $ceilingKeys = ['campaign_ids', 'providers', 'earliest', 'latest', 'creative_ids', 'creative_group_ids', 'excluded_creative_ids', 'objectives', 'paths'];
        if (array_intersect_key($opts, array_flip($ceilingKeys)) !== []) {
            // Keep what was not mentioned: an edit that names one axis must not widen the others back to default.
            $existing = (array) ($link->scope ?? []);
            $merged = $opts + array_intersect_key($existing, array_flip($ceilingKeys));
            $merged['mode'] = $link->mode;
            $fill['scope'] = $this->scopeFor($model, $merged);
        }

        $link->fill($fill)->save();
        $audit->log(action: 'report.shared_link_updated', entityType: ReportShare::class, entityId: (string) $link->id, before: $before, after: array_diff_key($fill, ['password_hash' => 1]));

        return ApiResponse::success($this->shape($link->fresh()), 'Link updated — the same URL now reflects it.');
    }

    /**
     * GET …/shares/{share}/analytics — REPORT-LINK-ANALYTICS-001. Owner only: total views, privacy-safe unique
     * views, first and last opened. The public payload never carries these.
     */
    public function analytics(Request $request, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findShare($this->findReport($report), $share);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'granularity' => ['nullable', 'in:day,week,month'],
        ]);

        return ApiResponse::success(app(ShareAnalytics::class)->for(
            $model,
            $request->filled('from') ? Carbon::parse((string) $request->query('from')) : null,
            $request->filled('to') ? Carbon::parse((string) $request->query('to')) : null,
            (string) $request->query('granularity', 'day'),
        ), 'Link analytics.');
    }

    public function logs(Request $request, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findShare($this->findReport($report), $share);
        $logs = $model->logs()->latest()->limit(100)->get(['action', 'ip', 'user_agent', 'detail', 'created_at']);

        return ApiResponse::success($logs, 'Access logs.');
    }

    /**
     * IP-TRACE-001 — which link did this copy come from?
     *
     * The Owner reads a reference (REF XXXXX-XXXXX) off a leaked page, PDF or spreadsheet and asks here.
     * The answer is the link — its report, who issued it, when, whether it is still live and how it was
     * opened — which is enough to decide whom to ask, revoke it, or issue a replacement. It names no
     * recipient because the system never knew one: a link is not a person. Scoped to this project and
     * tenant by the same lookups every other share route uses.
     */
    public function trace(Request $request, string $project, string $mark): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        abort_unless(preg_match('/^[A-Z0-9]{5}-?[A-Z0-9]{5}$/i', $mark) === 1, 422, 'Not a link reference.');

        $normalised = strtoupper(strlen($mark) === 10 ? substr($mark, 0, 5).'-'.substr($mark, 5) : $mark);
        $share = ReportShare::query()->where('trace_mark', $normalised)->first();
        $report = $share === null ? null : Report::query()->find($share->report_id);
        abort_if($share === null || $report === null || (string) $report->project_id !== $project, 404, 'No link carries that reference.');

        $creator = $share->created_by === null ? null : User::query()->find($share->created_by);

        return ApiResponse::success($this->shape($share) + [
            'report_id' => (string) $report->id,
            'report_name' => $report->name,
            'created_by' => $creator?->name,
        ], 'Link traced.');
    }

    /**
     * The ceiling a live link may never exceed (LIVEREP-001).
     *
     * Built HERE, from the report and the operator's request — never from the client's later query
     * string. The operator chooses; the recipient may only narrow.
     *
     * Two decisions worth stating:
     *
     * - **An empty campaign set falls back to the report's own campaigns, not to «all».** If the
     *   operator sent no list, the defensible reading is «the campaigns this report is about», and the
     *   report knows them. Reading it as «everything in the project» would hand over campaigns nobody
     *   chose to share.
     * - **`latest` defaults to open-ended-until-today, not to the report's period end.** A live link
     *   whose window stopped where the document stopped would answer «how is it going?» with last
     *   month's answer, which is the exact failure this feature exists to remove. It still cannot reach
     *   BEFORE the report's period, because that data was never part of what was shared.
     *
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    private function scopeFor(Report $model, array $opts): array
    {
        $campaigns = array_values(array_filter((array) ($opts['campaign_ids'] ?? [])));
        if ($campaigns === []) {
            $campaigns = $this->campaignsOf($model);
        }

        $providers = array_values(array_filter((array) ($opts['providers'] ?? [])));
        if ($providers === []) {
            $providers = $this->providersOf($model);
        }

        $list = static fn (string $key): array => array_values(array_filter((array) ($opts[$key] ?? [])));

        return [
            'project_id' => (string) $model->project_id,
            'campaign_ids' => $campaigns,
            'providers' => $providers,
            /*
             * The creative axes (§15.12).
             *
             * Empty means «no bound on this axis» for the two allow-lists, and «nothing excluded» for
             * the deny-list — which are the same sentence read in opposite directions and both
             * correct: an operator who named no creatives shared the report's creatives, and an
             * operator who excluded none excluded none.
             */
            'creative_ids' => $list('creative_ids'),
            'creative_group_ids' => $list('creative_group_ids'),
            'excluded_creative_ids' => $list('excluded_creative_ids'),
            'objectives' => $list('objectives'),
            'paths' => $list('paths'),
            'earliest' => (string) ($opts['earliest'] ?? $model->period_start?->toDateString() ?? Carbon::now()->subDays(90)->toDateString()),
            /*
             * A LIVE link's window runs on; a SNAPSHOT link's stops where the document does.
             *
             * The creative section reads the pipeline in both cases — that is what makes it the same
             * figures the operator sees — so a snapshot link whose ceiling ran to «today + a year»
             * would show creative rows from weeks the rest of the document never covered, sitting
             * under a heading that says the report is fixed.
             */
            'latest' => (string) ($opts['latest'] ?? (
                ($opts['mode'] ?? null) === 'live' || ! empty($opts['live'])
                    // REPORT-PERIOD-MODE-001 — a FIXED report's live link stops at its saved end date.
                    ? (ReportPeriod::modeOfReport($model) === 'fixed' && $model->period_end !== null
                        ? $model->period_end->toDateString()
                        : Carbon::now()->addYear()->toDateString())
                    : ($model->period_end?->toDateString() ?? Carbon::now()->toDateString())
            )),
        ];
    }

    /**
     * The campaigns this report covers — its own if it is campaign-scoped, else the ones that SPENT.
     *
     * ## Why this reads the metrics and not the report's own payload
     *
     * It read `data['campaigns']`, and that list is a rendering of the document rather than a fact
     * about what the link may reach. CLIENT-REPORT-ENTITY-BOUNDARY-001 emptied it — a client report
     * does not carry the campaign roster — and this method quietly became «no campaigns», which
     * `MetricsAggregator::forCampaigns([])` reads as its fail-closed «match nothing». Every share
     * built from a project-level report would have opened on a page of zeros.
     *
     * The coupling was the defect, not the emptying. A share's ceiling is an AUTHORISATION fact: it
     * decides what a link may reach, and it must be derived from what exists in the period rather
     * than from what one document chose to print about it. Reading the metrics answers the same
     * question the old list answered — «which campaigns are in this report's scope and window» —
     * from the source both were derived from.
     *
     * The E2E gate is what caught it: four specs on the live link went to zero at once, and no unit
     * test could have, because each half was correct on its own.
     */
    private function campaignsOf(Report $model): array
    {
        if ($model->campaign_id !== null) {
            return [(string) $model->campaign_id];
        }

        $projectId = (string) ($model->project_id ?? '');
        if ($projectId === '') {
            return [];
        }

        // The report's own scope bounds the ceiling: a link may be narrower than its report, never wider.
        $scope = ReportScope::fromReport($model->scope);
        $named = $scope->resolvedCampaignIds();
        if ($named !== null && $named !== []) {
            return array_values(array_map(strval(...), $named));
        }

        // The report's own scope bounds the ceiling: a link may be narrower than its report, never wider.
        $scope = ReportScope::fromArray($model->scope);
        $named = $scope->resolvedCampaignIds();
        if ($named !== null && $named !== []) {
            return array_values(array_map(strval(...), $named));
        }

        return DB::table('daily_metrics')
            ->where('project_id', $projectId)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->when($scope->accountIds !== [], fn ($q) => $q->whereIn('external_account_id', $scope->accountIds))
            ->whereNotNull('unified_campaign_id')
            ->when(
                $model->period_start !== null && $model->period_end !== null,
                fn ($q) => $q->whereBetween('metric_date', [
                    $model->period_start->toDateString(),
                    $model->period_end->toDateString(),
                ]),
            )
            ->distinct()
            ->pluck('unified_campaign_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * The platforms this report covers — the honest default ceiling for a link built from it.
     *
     * REPORT-PLATFORM-RECONCILE-001 — read from the report's SCOPE and the metrics, like `campaignsOf()`, not from the platforms its
     * frozen payload happened to list. A saved report generated before Google Ads or ChatGPT Ads had reported kept a three-platform
     * payload, and every link built from it inherited a three-platform ceiling while a link built in the live builder (which reads the
     * metrics) showed them all. Two doors into the same report disagreed about what the report covers.
     */
    private function providersOf(Report $model): array
    {
        $scope = ReportScope::fromReport($model->scope);
        if ($scope->providers !== []) {
            return $scope->providers;
        }

        $projectId = (string) ($model->project_id ?? '');
        $fromMetrics = $projectId === '' ? [] : DB::table('daily_metrics')
            ->where('project_id', $projectId)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->when($scope->accountIds !== [], fn ($q) => $q->whereIn('external_account_id', $scope->accountIds))
            ->when(
                $model->period_start !== null && $model->period_end !== null,
                fn ($q) => $q->whereBetween('metric_date', [$model->period_start->toDateString(), $model->period_end->toDateString()]),
            )
            ->distinct()
            ->pluck('provider')
            ->map(static fn ($p): string => (string) $p)
            ->all();

        if ($fromMetrics !== []) {
            return array_values(array_unique($fromMetrics));
        }

        $scope = ReportScope::fromArray($model->scope);
        if ($scope->providers !== []) {
            return $scope->providers;
        }

        $projectId = (string) ($model->project_id ?? '');
        $fromMetrics = $projectId === '' ? [] : DB::table('daily_metrics')
            ->where('project_id', $projectId)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->when($scope->accountIds !== [], fn ($q) => $q->whereIn('external_account_id', $scope->accountIds))
            ->when(
                $model->period_start !== null && $model->period_end !== null,
                fn ($q) => $q->whereBetween('metric_date', [$model->period_start->toDateString(), $model->period_end->toDateString()]),
            )
            ->distinct()
            ->pluck('provider')
            ->map(static fn ($p): string => (string) $p)
            ->all();

        if ($fromMetrics !== []) {
            return array_values(array_unique($fromMetrics));
        }

        $rows = (array) (($model->data ?? [])['platforms'] ?? []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['provider'] ?? null) : null,
            $rows,
        ))));
    }

    /**
     * IP-AUDIT-001 — a link is looked up THROUGH its report.
     *
     * These routes carry both ids, and the share was read by its own id alone: tenant-scoped, but not
     * bound to the report (and so the project) the caller had just been authorised for. Anyone allowed
     * to manage reports in one project could revoke, renew, edit or read the access log of a link that
     * belongs to another report by presenting that link's id under their own report's address. Tying
     * the lookup to the report closes it; a mismatch is a 404, the same as an id that does not exist.
     */
    private function findShare(Report $report, string $id): ReportShare
    {
        return ReportShare::query()->where('report_id', $report->getKey())->findOrFail($id);
    }

    private function findReport(string $id): Report
    {
        $r = Report::query()->find($id);
        abort_if($r === null, 404, 'Report not found.');

        return $r;
    }

    private function shape(ReportShare $s): array
    {
        return [
            'id' => $s->id,
            'active' => $s->isActive(),
            'mode' => $s->isLive() ? 'live' : 'snapshot',
            // Two independent facts — see the store() validation. `form` is null when the link takes
            // the report's own, which is what every link made before §15.12 does.
            'form' => $s->form,
            'creatives' => $s->creativeVisibility()->toArray(),
            'sections' => $s->sectionVisibility()->toArray(),
            // REPORT-TABLES-OWNER-COLUMNS-001 — what the link publishes per table, so the edit dialog starts from it.
            'columns' => ReportColumns::forShare($s)->toArray(),
            'scope' => $s->scope,
            'allow_download' => $s->allow_download,
            'hide_spend' => $s->hide_spend,
            'hide_revenue' => $s->hide_revenue,
            'hide_campaign_names' => $s->hide_campaign_names,
            'watermark' => $s->watermark,
            // IP-TRACE-001 — the opaque reference the page, the PDF and the files of this link carry.
            'trace_mark' => $s->trace_mark,
            'password_protected' => $s->password_hash !== null,
            'view_count' => $s->view_count,
            'last_viewed_at' => $s->last_viewed_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'revoked_at' => $s->revoked_at?->toIso8601String(),
            'paused_at' => $s->paused_at?->toIso8601String(),
            'logs_count' => $s->logs_count ?? null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
