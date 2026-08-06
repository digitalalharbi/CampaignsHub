<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $data = $report->form === 'executive_summary'
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
            'form' => $report->form,
            'mode' => $share->isLive() ? 'live' : 'snapshot',
            'branding' => $this->branding($report),
            'settings' => [
                'allow_download' => $share->allow_download,
                'watermark' => $share->watermark,
            ],
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
        $sanitized->data = $this->shares->sanitize($report->data ?? [], $share);
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
