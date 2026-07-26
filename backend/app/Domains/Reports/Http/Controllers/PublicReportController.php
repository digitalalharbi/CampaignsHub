<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Models\Report;
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

        $data = $this->shares->sanitize($report->data ?? [], $share);

        return ApiResponse::success([
            'name' => $report->name,
            'currency' => $report->currency,
            'is_demo' => $report->is_demo,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'settings' => [
                'allow_download' => $share->allow_download,
                'watermark' => $share->watermark,
            ],
            'data' => $data,
        ], 'Shared report.');
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

        // Export a sanitized copy so hidden figures never leak into the file.
        $sanitized = $report->replicate();
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
