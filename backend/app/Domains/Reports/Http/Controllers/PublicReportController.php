<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\SharedCreativeView;
use App\Domains\Reports\Services\ShareService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public (unauthenticated) access to a shared report via its raw token. The token hash + expiry +
 * revocation + optional password are the gate; the payload is sanitized to the share's hide-settings
 * and every access is logged. Rate-limited to blunt token guessing.
 */
final class PublicReportController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $this->throttle($request);
        $share = $this->shares->resolveActive($token);
        if (! $share) {
            return ApiResponse::error('الرابط غير صالح أو انتهت صلاحيته أو أُلغي.', status: 404);
        }
        if ($share->password_hash !== null) {
            $provided = (string) ($request->header('X-Report-Password') ?? $request->query('password', ''));
            if (! Hash::check($provided, $share->password_hash)) {
                $this->shares->log($share, 'denied', $request, 'bad password');

                return ApiResponse::error('كلمة المرور مطلوبة أو غير صحيحة.', status: 401, errors: ['password_required' => [true]]);
            }
        }

        $report = Report::withoutGlobalScopes()->find($share->report_id);
        if (! $report || $report->status !== 'completed') {
            return ApiResponse::error('التقرير غير متاح.', status: 404);
        }

        $share->increment('view_count');
        $share->update(['last_viewed_at' => now()]);
        $this->shares->log($share, 'view', $request);

        /*
         * Shared links are CLIENT-facing: approved recommendations only, client campaign names, no
         * internal/technical fields — then the per-share hide flags (spend/revenue/names).
         *
         * The FORM is applied here too, and it was not before (REPORT-LINKS-13). Every shared link
         * ran the plain client filter, so a report an operator had deliberately built as an
         * executive summary arrived at the client in full detail — the one setting that says «this
         * is five pages, not thirty» was honoured in the PDF export and dropped on the link, which
         * is the copy most clients actually open.
         */
        $view = app(ClientReportView::class);
        /*
         * The FORM is the LINK's, falling back to the report's (§15.12).
         *
         * One generated report is legitimately two documents — the board reads the summary, the
         * performance manager reads the detail — and before this the only way to send both was to
         * generate the report twice, which is two snapshots and, a fortnight later, two different
         * answers to the same question.
         */
        $form = $share->formOr($report->form);
        $data = $form === 'executive_summary'
            ? $view->executive($report->data ?? [])
            : $view->filter($report->data ?? []);
        $data = $this->shares->sanitize($data, $share);

        return ApiResponse::success([
            'name' => $report->name,
            'currency' => $report->currency,
            'is_demo' => $report->is_demo,
            'generated_at' => $report->generated_at?->toIso8601String(),
            /*
             * Two independent facts, and the client page needs both: `form` is what the report is,
             * `mode` is where its numbers come from. A summary can be live and a detailed report can
             * be a snapshot — the contract asks for all four combinations.
             */
            'form' => $form,
            'mode' => $share->isLive() ? 'live' : 'snapshot',
            'branding' => $this->branding($report),
            'settings' => [
                'allow_download' => $share->allow_download,
                'watermark' => $share->watermark,
            ],
            /*
             * What this link may show about the content, stated up front.
             *
             * The page needs it before it renders anything: a creative section it is not permitted
             * to open should never appear as a control that then refuses, and a metric column that
             * is withheld should not be drawn as an empty column with a name on it.
             */
            'creatives' => $share->creativeVisibility()->toArray(),
            /*
             * Which optional sections this link may open — ATTRIB-VIS-001.
             *
             * The page needs it before it renders: a section it may not open must never appear as a
             * control that then refuses, and a client should not be shown a tab that answers 403.
             */
            'sections' => $share->sectionVisibility()->toArray(),
            'data' => $data,
        ], 'Shared report.');
    }

    /**
     * LIVEREP-001 — the figures for a live link, recomputed now, within the link's own ceiling.
     *
     * Split from `show()` rather than folded into it because the two answer different questions and the
     * client's page asks them at different times: `show()` once, for the document's identity and its
     * branding; this on every filter change. Keeping them apart is what lets a filter re-render without
     * re-fetching the shell — which is the whole point of «updates without reloading the page».
     *
     * A snapshot link calling this gets 409 rather than an empty live payload: the caller asked for
     * something this link is not, and saying so is more useful than returning zeroes it would render.
     */
    public function live(Request $request, string $token, LiveReportService $live): JsonResponse
    {
        $this->throttle($request);
        $share = $this->shares->resolveActive($token);
        if (! $share) {
            return ApiResponse::error('الرابط غير صالح أو انتهت صلاحيته أو أُلغي.', status: 404);
        }
        if ($share->password_hash !== null) {
            $provided = (string) ($request->header('X-Report-Password') ?? $request->query('password', ''));
            if (! Hash::check($provided, $share->password_hash)) {
                $this->shares->log($share, 'denied', $request, 'bad password');

                return ApiResponse::error('كلمة المرور مطلوبة أو غير صحيحة.', status: 401, errors: ['password_required' => [true]]);
            }
        }
        if (! $share->isLive()) {
            return ApiResponse::error('هذا الرابط يعرض تقريرًا ثابتًا وليس بيانات لحظية.', status: 409);
        }

        $report = Report::withoutGlobalScopes()->find($share->report_id);
        if (! $report) {
            return ApiResponse::error('التقرير غير متاح.', status: 404);
        }

        $payload = $live->build($share, $request->query(), (string) $report->currency);

        // Sanitised with the same hide-flags as the snapshot path: a live link that leaks spend a
        // snapshot link would have hidden is the same disclosure, arriving by a newer route.
        $payload = $this->shares->sanitizeLive($payload, $share);

        $this->shares->log($share, 'view', $request, 'live');

        return ApiResponse::success(
            $payload + ['is_demo' => $report->is_demo, 'form' => $report->form],
            'Live figures.',
        );
    }

    /**
     * §15.12 — the creative sections, for a reader with no session.
     *
     * Four addresses rather than one payload, because the report asks them at different times: the
     * summary once, the library on every filter change, the detail when a card is opened, the
     * comparison when two are selected. Folding them together would make opening one creative
     * re-fetch the whole section.
     *
     * Each is gated the same way the rest of this controller is — throttle, active link, password —
     * and then by {@see SharedCreativeView}, which owns the ceiling. **404 is deliberate for a
     * creative the link does not carry.** A 403 would confirm that the id exists and is simply not
     * shared, which is the fact a reader guessing ids is trying to establish.
     */
    /**
     * ATTRIB-VIS-001 — the Platform-Reported vs Store-Confirmed panel, for links that carry it.
     *
     * A separate address rather than a block inside `show()`, and that is the point rather than a
     * convenience: with the section off there is no endpoint to answer and nothing in the document
     * payload to strip. A client link that may not show attribution cannot leak it through the
     * export either, because the export renders the document — which never held it.
     *
     * §14.9's figures are computed for the report's own window and its own project, never for
     * «now»: a link opened in October must show what the September report said.
     */
    public function attribution(Request $request, string $token, AttributionTransparency $transparency): JsonResponse
    {
        $share = $this->shares->resolveActive($token);
        if (! $share) {
            return ApiResponse::error('الرابط غير صالح أو انتهت صلاحيته أو أُلغي.', status: 404);
        }

        // Fail-closed: an older link, a link that never asked, and a malformed settings blob all
        // land here.
        if (! $share->sectionVisibility()->attribution) {
            return ApiResponse::error('هذا القسم غير متاح في هذا الرابط.', status: 404);
        }

        $report = Report::withoutGlobalScopes()->find($share->report_id);
        if (! $report || $report->status !== 'completed') {
            return ApiResponse::error('التقرير غير متاح.', status: 404);
        }

        $period = (array) (($report->data ?? [])['period'] ?? []);
        $to = isset($period['to']) ? Carbon::parse((string) $period['to']) : Carbon::today();
        $from = isset($period['from']) ? Carbon::parse((string) $period['from']) : $to->copy()->subDays(29);

        $this->shares->log($share, 'view', $request, 'attribution');

        return ApiResponse::success(
            $transparency->build((string) $report->tenant_id, (string) $report->project_id, $from, $to),
            'Shared attribution.',
        );
    }

    public function creatives(Request $request, string $token, SharedCreativeView $creatives): JsonResponse
    {
        [$share, $error] = $this->open($request, $token);
        if ($error !== null) {
            return $error;
        }

        if (! $share->creativeVisibility()->creatives) {
            return ApiResponse::error('لا يعرض هذا الرابط تفاصيل المحتوى.', status: 404);
        }

        $this->shares->log($share, 'view', $request, 'creatives');

        return ApiResponse::success($creatives->library($share, $request->query()), 'Shared creatives.');
    }

    public function creativeSummary(Request $request, string $token, SharedCreativeView $creatives): JsonResponse
    {
        [$share, $error] = $this->open($request, $token);
        if ($error !== null) {
            return $error;
        }

        if (! $share->creativeVisibility()->creatives) {
            return ApiResponse::error('لا يعرض هذا الرابط تفاصيل المحتوى.', status: 404);
        }

        $this->shares->log($share, 'view', $request, 'creative-summary');

        return ApiResponse::success($creatives->summary($share, $request->query()), 'Shared creative summary.');
    }

    public function creative(Request $request, string $token, string $creative, SharedCreativeView $creatives): JsonResponse
    {
        [$share, $error] = $this->open($request, $token);
        if ($error !== null) {
            return $error;
        }

        $payload = $creatives->detail($share, $creative, $request->query());

        if ($payload === null) {
            $this->shares->log($share, 'denied', $request, 'creative out of scope');

            return ApiResponse::error('هذا المحتوى غير متاح ضمن هذا الرابط.', status: 404);
        }

        return ApiResponse::success($payload, 'Shared creative.');
    }

    public function creativeComparison(Request $request, string $token, SharedCreativeView $creatives): JsonResponse
    {
        [$share, $error] = $this->open($request, $token);
        if ($error !== null) {
            return $error;
        }

        $ids = array_values(array_filter(array_map(
            static fn ($v): string => is_scalar($v) ? (string) $v : '',
            (array) $request->query('creative_ids', []),
        )));

        $payload = $creatives->compare($share, $ids, $request->query());

        if ($payload === null) {
            $this->shares->log($share, 'denied', $request, 'comparison out of scope');

            return ApiResponse::error('المقارنة غير متاحة ضمن هذا الرابط.', status: 404);
        }

        return ApiResponse::success($payload, 'Shared creative comparison.');
    }

    /**
     * Throttle, resolve, password — the three gates every public endpoint here shares.
     *
     * Extracted when the creative endpoints arrived, because four more copies of the same twelve
     * lines is four more places for one of them to be left out. The one that would be left out is
     * the password check, and it would be left out of exactly the endpoint that carries the ad copy
     * and the asset URLs.
     *
     * @return array{0: ReportShare|null, 1: JsonResponse|null}
     */
    private function open(Request $request, string $token): array
    {
        $this->throttle($request);

        $share = $this->shares->resolveActive($token);
        if (! $share) {
            return [null, ApiResponse::error('الرابط غير صالح أو انتهت صلاحيته أو أُلغي.', status: 404)];
        }

        if ($share->password_hash !== null) {
            $provided = (string) ($request->header('X-Report-Password') ?? $request->query('password', ''));
            if (! Hash::check($provided, $share->password_hash)) {
                $this->shares->log($share, 'denied', $request, 'bad password');

                return [null, ApiResponse::error('كلمة المرور مطلوبة أو غير صحيحة.', status: 401, errors: ['password_required' => [true]])];
            }
        }

        return [$share, null];
    }

    /**
     * Whose report this is, as the client should see it — the agency's identity, or the client's own.
     *
     * Read from the report's stored config rather than resolved live, so a link keeps the identity it
     * was created with even if the agency later rebrands.
     *
     * @return array<string, mixed>
     */
    private function branding(Report $report): array
    {
        $config = (array) ($report->config ?? []);
        $branding = (array) ($config['branding'] ?? []);

        return [
            'name' => $branding['name'] ?? null,
            'logo_url' => $branding['logo_url'] ?? null,
            'accent' => $branding['accent'] ?? null,
        ];
    }

    public function download(Request $request, string $token, string $format): StreamedResponse
    {
        $this->throttle($request);
        $share = $this->shares->resolveActive($token);
        abort_if($share === null, 404, 'Link not found.');
        abort_unless($share->allow_download, 403, 'Downloads are disabled for this link.');
        abort_unless(in_array($format, ['pdf', 'xlsx', 'csv'], true), 404);

        $report = Report::withoutGlobalScopes()->find($share->report_id);
        abort_if($report === null || $report->status !== 'completed', 404, 'Report not available.');

        // ReportExporter applies the audience filter (client-safe); here we add the per-share hide flags
        // (spend/revenue/names). replicate() keeps the audience so the exporter filters correctly.
        $sanitized = $report->replicate();
        $sanitized->setAttribute('id', $report->id); // replicate() drops the key; the gate needs it
        $data = $this->shares->sanitize($report->data ?? [], $share);

        /*
         * §15.12 — the creative rows reach the file only if the link may show them.
         *
         * Attached HERE rather than stored on the report, so the same generated report exports with
         * creatives for one recipient and without them for another. The rows come back already
         * redacted by `SharedCreativeView`, which is the only place the visibility rules live: an
         * exporter that re-derived «may this show ROAS?» would be a second opinion, and the first
         * time the two disagreed the disagreement would be sitting in a file somebody had already
         * been sent.
         */
        if ($share->creativeVisibility()->creatives) {
            $creatives = app(SharedCreativeView::class)->library($share, ['per_page' => 48]);
            $data['creatives'] = $creatives['creatives'];
        }

        $sanitized->data = $data;
        $content = app(ReportExporter::class)->render($sanitized, $format);
        $this->shares->log($share, 'download', $request, $format);

        $mime = ['pdf' => 'application/pdf', 'csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$format];

        return response()->streamDownload(fn () => print ($content), "report.{$format}", ['Content-Type' => $mime]);
    }

    private function throttle(Request $request): void
    {
        $key = 'share:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 60), 429, 'Too many requests.');
        RateLimiter::hit($key, 60);
    }
}
