<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\Tenancy\Context\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-wide unified Files library (مكتبة الملفات الموحدة) — a READ-ONLY aggregation over the platform's real
 * file stores (request_files + report_exports), each row attributed to its source, client, and related entity.
 * Never a new file engine, no fabricated rows. Downloads reuse the existing per-client streaming endpoints,
 * which re-verify ownership — so no new download surface is introduced.
 */
final class FilesLibraryController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('clients.manage_files'), 403);
        $tenantId = (string) $this->tenant->tenantId();

        $clientNames = DB::table('client_workspaces')->where('tenant_id', $tenantId)->pluck('name', 'id');

        // Request attachments across every client of this tenant.
        $requestFiles = DB::table('request_files as f')
            ->join('external_requests as r', 'r.id', '=', 'f.request_id')
            ->leftJoin('users as u', 'u.id', '=', 'f.uploaded_by')
            ->where('r.tenant_id', $tenantId)
            ->select('f.id', 'f.original_name', 'f.mime', 'f.size', 'f.is_client_visible',
                'f.created_at', 'u.name as uploader', 'r.reference as related', 'r.client_id')
            ->orderByDesc('f.created_at')->limit(500)
            ->get()->map(fn ($f) => [
                'source' => 'request',
                'id' => (string) $f->id,
                'name' => $f->original_name,
                'type' => $f->mime,
                'size' => (int) $f->size,
                'visibility' => $f->is_client_visible ? 'client_visible' : 'internal',
                'uploaded_at' => $f->created_at,
                'uploader' => $f->uploader,
                'client_id' => $f->client_id !== null ? (string) $f->client_id : null,
                'client_name' => $f->client_id !== null ? ($clientNames[$f->client_id] ?? null) : null,
                'related' => ['type' => 'request', 'label' => $f->related],
                'download_url' => $f->client_id !== null
                    ? "/api/v1/app/clients/{$f->client_id}/files/request/{$f->id}/download"
                    : null,
            ]);

        // Report exports across every project of this tenant (client resolved through the project).
        $reportFiles = DB::table('report_exports as e')
            ->join('reports as rp', 'rp.id', '=', 'e.report_id')
            ->join('projects as p', 'p.id', '=', 'rp.project_id')
            ->where('p.tenant_id', $tenantId)->whereNotNull('e.path')
            ->select('e.id', 'e.format', 'e.size', 'e.created_at', 'rp.name as report_name', 'rp.audience',
                'p.client_workspace_id as client_id')
            ->orderByDesc('e.created_at')->limit(500)
            ->get()->map(fn ($e) => [
                'source' => 'report',
                'id' => (string) $e->id,
                'name' => $e->report_name.'.'.$e->format,
                'type' => $e->format,
                'size' => $e->size !== null ? (int) $e->size : null,
                'visibility' => ($e->audience ?? 'client') === 'internal' ? 'internal' : 'client_visible',
                'uploaded_at' => $e->created_at,
                'uploader' => null,
                'client_id' => $e->client_id !== null ? (string) $e->client_id : null,
                'client_name' => $e->client_id !== null ? ($clientNames[$e->client_id] ?? null) : null,
                'related' => ['type' => 'report', 'label' => $e->report_name],
                'download_url' => $e->client_id !== null
                    ? "/api/v1/app/clients/{$e->client_id}/files/report/{$e->id}/download"
                    : null,
            ]);

        $driveLinks = DB::table('drive_links')->where('tenant_id', $tenantId)->count();

        return ApiResponse::success([
            'files' => $requestFiles->concat($reportFiles)->sortByDesc('uploaded_at')->values(),
            'drive_links' => $driveLinks,
        ], 'Files library.');
    }
}
