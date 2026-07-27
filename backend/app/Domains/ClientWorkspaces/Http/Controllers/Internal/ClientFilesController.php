<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client Files tab — a READ-ONLY view over the existing private file stores (request_files, report_exports),
 * scoped to this client's own entities. Never a new file engine. Storage paths/disks are NEVER exposed to the
 * client; downloads stream through a controller that re-verifies client ownership (no cross-client access),
 * and every row carries its visibility (internal vs client-visible), checksum, source and related entity.
 */
final class ClientFilesController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /app/clients/{client}/files */
    public function index(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_files', $c);
        $tenantId = (string) $this->tenant->tenantId();

        // Request attachments (linked through the client's external requests).
        $requestFiles = DB::table('request_files as f')
            ->join('external_requests as r', 'r.id', '=', 'f.request_id')
            ->leftJoin('users as u', 'u.id', '=', 'f.uploaded_by')
            ->where('r.tenant_id', $tenantId)->where('r.client_id', $c->id)
            ->select('f.id', 'f.original_name', 'f.mime', 'f.size', 'f.is_client_visible', 'f.checksum',
                'f.created_at', 'u.name as uploader', 'r.reference as related')
            ->get()->map(fn ($f) => [
                'source' => 'request',
                'id' => (string) $f->id,
                'name' => $f->original_name,
                'type' => $f->mime,
                'size' => (int) $f->size,
                'visibility' => $f->is_client_visible ? 'client_visible' : 'internal',
                'checksum' => $f->checksum,
                'uploaded_at' => $f->created_at,
                'uploader' => $f->uploader,
                'related_entity' => ['type' => 'request', 'label' => $f->related],
                'download_url' => "/api/v1/app/clients/{$c->id}/files/request/{$f->id}/download",
            ]);

        // Report exports (linked through the client's projects → reports).
        $projectIds = DB::table('projects')->where('tenant_id', $tenantId)->where('client_workspace_id', $c->id)->pluck('id')->all();
        $reportFiles = $projectIds === [] ? collect() : DB::table('report_exports as e')
            ->join('reports as rp', 'rp.id', '=', 'e.report_id')
            ->whereIn('rp.project_id', $projectIds)->whereNotNull('e.path')
            ->select('e.id', 'e.format', 'e.size', 'rp.name as report_name', 'rp.audience', 'e.created_at')
            ->get()->map(fn ($e) => [
                'source' => 'report',
                'id' => (string) $e->id,
                'name' => $e->report_name.'.'.$e->format,
                'type' => $e->format,
                'size' => $e->size ? (int) $e->size : null,
                'visibility' => ($e->audience ?? 'client') === 'internal' ? 'internal' : 'client_visible',
                'checksum' => null,
                'uploaded_at' => $e->created_at,
                'uploader' => null,
                'related_entity' => ['type' => 'report', 'label' => $e->report_name],
                'download_url' => "/api/v1/app/clients/{$c->id}/files/report/{$e->id}/download",
            ]);

        $files = $requestFiles->concat($reportFiles)->sortByDesc('uploaded_at')->values();

        return response()->json(['data' => ['files' => $files]]);
    }

    /** GET /app/clients/{client}/files/{source}/{id}/download — streamed; storage path never exposed. */
    public function download(Request $request, string $client, string $source, string $id): StreamedResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_files', $c);
        $tenantId = (string) $this->tenant->tenantId();

        [$disk, $path, $name] = match ($source) {
            'request' => $this->resolveRequestFile($tenantId, $c, $id),
            'report' => $this->resolveReportFile($tenantId, $c, $id),
            default => abort(404),
        };

        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path, $name);
    }

    /** @return array{0:string,1:string,2:string} */
    private function resolveRequestFile(string $tenantId, ClientWorkspace $c, string $id): array
    {
        $f = DB::table('request_files as f')
            ->join('external_requests as r', 'r.id', '=', 'f.request_id')
            ->where('r.tenant_id', $tenantId)->where('r.client_id', $c->id)->where('f.id', $id)
            ->select('f.disk', 'f.path', 'f.original_name')->first();
        abort_if($f === null, 404); // cross-client / cross-tenant → 404

        return [$f->disk, $f->path, $f->original_name];
    }

    /** @return array{0:string,1:string,2:string} */
    private function resolveReportFile(string $tenantId, ClientWorkspace $c, string $id): array
    {
        $projectIds = DB::table('projects')->where('tenant_id', $tenantId)->where('client_workspace_id', $c->id)->pluck('id')->all();
        $e = $projectIds === [] ? null : DB::table('report_exports as e')
            ->join('reports as rp', 'rp.id', '=', 'e.report_id')
            ->whereIn('rp.project_id', $projectIds)->where('e.id', $id)->whereNotNull('e.path')
            ->select('e.disk', 'e.path', 'rp.name as report_name', 'e.format')->first();
        abort_if($e === null, 404);

        return [$e->disk, $e->path, $e->report_name.'.'.$e->format];
    }
}
