<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Support\ExportStaleness;
use App\Domains\Reports\Support\ReportIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, token-gated, expiring download for a finished report export — the "secure link" a report
 * can be shared with. No session required; the random token + expiry are the guard.
 */
final class ReportDownloadController extends Controller
{
    private const MIME = ['pdf' => 'application/pdf', 'csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    public function __invoke(Request $request, string $token): StreamedResponse
    {
        $export = ReportExport::withoutGlobalScopes()->where('signed_token', $token)->first();
        abort_if($export === null || $export->status !== 'completed' || ! $export->path, 404, 'Export not found.');
        abort_if($export->expires_at !== null && Carbon::now()->greaterThan($export->expires_at), 410, 'Download link expired.');

        // Never serve a stale file: an export produced by an older engine/template, or one that predates
        // the provenance columns, is refused so a fresh (correct) export must be generated. This is the
        // backstop that stops an old Dompdf/cached file from ever reaching a client — checked before the
        // file-existence probe so staleness always wins.
        abort_if($this->isStale($export), 409, 'This export is stale — regenerate it before downloading.');

        abort_unless(Storage::disk($export->disk)->exists($export->path), 404, 'File missing.');

        /*
         * REPORT-TITLE-METADATA-001 — the stored path names a blob; the DOWNLOAD names the report.
         *
         * `basename($export->path)` is a uuid with an extension on it. It is the right name for a
         * file on a disk and the wrong one for a file a client is about to keep.
         */
        $filename = $export->report === null
            ? basename($export->path)
            : ReportIdentity::filename($export->report, $export->format);

        return Storage::disk($export->disk)->download($export->path, $filename, [
            'Content-Type' => self::MIME[$export->format] ?? 'application/octet-stream',
        ]);
    }

    /**
     * Delegated to `ExportStaleness` — REPORT-EXPORT-STALE-DEADEND-001.
     *
     * This rule used to live here as a private method, which is exactly why the reports LIST could
     * not ask it and ended up drawing a download link for a file this endpoint would refuse. Two
     * callers need one answer; a second copy is how they would come to disagree about which files
     * are downloadable.
     */
    private function isStale(ReportExport $export): bool
    {
        return ExportStaleness::isStale($export);
    }
}
