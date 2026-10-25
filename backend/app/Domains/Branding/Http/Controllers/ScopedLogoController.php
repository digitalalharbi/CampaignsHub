<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Controllers;

use App\Domains\Branding\BrandingSpec;
use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LOGO-UPLOAD-001 — a logo is a FILE the person gives the product, never an address they paste.
 *
 * One mark per layer — the agency, a client, a project — through the same Branding Center storage and the same resolver every
 * report, live link, PDF, share card, portal header and digest already read. This controller only adds the missing door: upload,
 * replace, remove and preview from where the person is working (the client's settings, the project's), with the permission of that
 * place rather than the whole Branding Center's.
 *
 * Inheritance, nearest first: report → project → client → agency. A project with no logo of its own shows the client's; a client with
 * none shows the agency's. `effective` says which one is in force, so the screen never shows a logo that is not the one being used.
 *
 * Isolation: the client or project is resolved through the signed-in user's own reach (`ClientAccess`), so another tenant's or an
 * unreachable client's id is a 404, and bytes are streamed from storage only for the layer being asked about.
 */
final class ScopedLogoController extends Controller
{
    public const MAX_KB = 2048;

    public function __construct(private readonly BrandingService $branding, private readonly ClientAccess $access) {}

    // ── agency ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function showAgency(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.view'), 403);

        return ApiResponse::success($this->shape('/api/v1/branding/logo/file', $this->branding->ownLogo('tenant', null), null), 'Logo.');
    }

    public function fileAgency(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('branding.view'), 403);

        return $this->stream($this->branding->ownLogo('tenant', null));
    }

    public function storeAgency(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);
        $this->branding->setLogo('tenant', null, $this->file($request));

        return ApiResponse::success($this->shape('/api/v1/branding/logo/file', $this->branding->ownLogo('tenant', null), null), 'Logo saved.', status: 201);
    }

    public function destroyAgency(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);
        $this->branding->removeLogo('tenant', null);

        return ApiResponse::success($this->shape('/api/v1/branding/logo/file', null, null), 'Logo removed.');
    }

    // ── client ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function showClient(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client, 'clients.view');

        return ApiResponse::success($this->clientShape($c), 'Logo.');
    }

    public function fileClient(Request $request, string $client): StreamedResponse
    {
        $c = $this->client($request, $client, 'clients.view');

        return $this->stream($this->effective('client', (string) $c->id));
    }

    public function storeClient(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client, 'clients.manage_settings');
        $this->branding->setLogo('client', (string) $c->id, $this->file($request));

        return ApiResponse::success($this->clientShape($c), 'Logo saved.', status: 201);
    }

    public function destroyClient(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client, 'clients.manage_settings');
        $this->branding->removeLogo('client', (string) $c->id);

        return ApiResponse::success($this->clientShape($c), 'Logo removed.');
    }

    // ── project ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function showProject(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        return ApiResponse::success($this->projectShape($project), 'Logo.');
    }

    public function fileProject(Request $request, Project $project): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        return $this->stream($this->effective('project', (string) $project->id, (string) $project->client_workspace_id));
    }

    public function storeProject(Request $request, Project $project): JsonResponse
    {
        $this->projectWriter($request, $project);
        $this->branding->setLogo('project', (string) $project->id, $this->file($request));

        return ApiResponse::success($this->projectShape($project), 'Logo saved.', status: 201);
    }

    public function destroyProject(Request $request, Project $project): JsonResponse
    {
        $this->projectWriter($request, $project);
        $this->branding->removeLogo('project', (string) $project->id);

        return ApiResponse::success($this->projectShape($project), 'Logo removed.');
    }

    // ── shared ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function client(Request $request, string $id, string $permission): ClientWorkspace
    {
        $c = $this->access->resolve($id);
        $this->access->assert($request->user(), $permission, $c);

        return $c;
    }

    private function projectWriter(Request $request, Project $project): void
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);
        $client = ClientWorkspace::query()->whereKey($project->client_workspace_id)->first();
        abort_if($client === null, 404);
        abort_unless($this->access->canAccessClient($request->user(), $client), 404);
    }

    private function file(Request $request): UploadedFile
    {
        $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:'.implode(',', BrandingSpec::ALLOWED_MIME)]]);

        return $request->file('file');
    }

    /** The logo in force for a layer: its own, else the next layer up. */
    private function effective(string $scope, string $id, ?string $clientId = null): ?BrandingAsset
    {
        $own = $this->branding->ownLogo($scope, $id);
        if ($own !== null) {
            return $own;
        }
        if ($scope === 'project' && $clientId !== null && ($c = $this->branding->ownLogo('client', $clientId)) !== null) {
            return $c;
        }

        return $this->branding->ownLogo('tenant', null);
    }

    /** @return array<string,mixed> */
    private function clientShape(ClientWorkspace $c): array
    {
        $own = $this->branding->ownLogo('client', (string) $c->id);
        $agency = $own === null ? $this->branding->ownLogo('tenant', null) : null;

        return $this->shape("/api/v1/app/clients/{$c->id}/logo/file", $own, $agency === null ? null : 'agency');
    }

    /** @return array<string,mixed> */
    private function projectShape(Project $p): array
    {
        $own = $this->branding->ownLogo('project', (string) $p->id);
        $fromClient = $own === null ? $this->branding->ownLogo('client', (string) $p->client_workspace_id) : null;
        $fromAgency = $own === null && $fromClient === null ? $this->branding->ownLogo('tenant', null) : null;

        return $this->shape("/api/v1/projects/{$p->id}/logo/file", $own, $fromClient !== null ? 'client' : ($fromAgency !== null ? 'agency' : null), $fromClient ?? $fromAgency);
    }

    /**
     * @param  string|null  $inheritedFrom  `client` | `agency` when the layer has no logo of its own but one is inherited
     * @return array<string,mixed>
     */
    private function shape(string $fileUrl, ?BrandingAsset $own, ?string $inheritedFrom, ?BrandingAsset $inherited = null): array
    {
        $shown = $own ?? $inherited ?? ($inheritedFrom === 'agency' ? $this->branding->ownLogo('tenant', null) : null);

        return [
            'own' => $own !== null,
            'effective_source' => $own !== null ? 'own' : $inheritedFrom,
            'url' => $shown === null ? null : $fileUrl.'?v='.substr((string) $shown->checksum, 0, 12),
            'mime' => $shown?->mime,
            'bytes' => $shown?->bytes,
            'limits' => ['mimes' => BrandingSpec::ALLOWED_MIME, 'max_bytes' => BrandingSpec::MAX_BYTES],
        ];
    }

    private function stream(?BrandingAsset $asset): StreamedResponse
    {
        abort_if($asset === null || ! Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->response($asset->path, 'logo', ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }
}
