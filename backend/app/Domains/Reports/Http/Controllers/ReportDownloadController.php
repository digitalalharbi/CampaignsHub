<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Models\ReportExport;
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
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404, 'File missing.');

        $filename = basename($export->path);

        return Storage::disk($export->disk)->download($export->path, $filename, [
            'Content-Type' => self::MIME[$export->format] ?? 'application/octet-stream',
        ]);
    }
}
