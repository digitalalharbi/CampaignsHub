<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Controllers;

use App\Domains\Branding\BrandingSpec;
use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\Branding\Services\BrandingService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Branding Center: manage brand assets and settings across scopes. Tenant isolation comes from the models'
 * global scope (route-model binding 404s cross-tenant). Reads need branding.view; mutations branding.manage.
 *
 * The API NEVER returns a filesystem path — assets are presented as an opaque id plus a download URL only.
 */
final class BrandingCenterController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    /** GET /branding/assets — list assets, optionally filtered to a scope (+ scope_id). */
    public function assets(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.view'), 403);

        $query = BrandingAsset::query()->latest('created_at');
        if (($scope = $request->string('scope')->toString()) !== '' && BrandingSpec::isScope($scope)) {
            $query->where('scope', $scope);
        }
        if (($scopeId = $request->string('scope_id')->toString()) !== '') {
            $query->where('scope_id', $scopeId);
        }

        return ApiResponse::success(
            array_map($this->present(...), $query->limit(200)->get()->all()),
            'Branding assets.',
        );
    }

    /** POST /branding/assets — validate + store a brand file, upserting its (scope, scope_id, kind, theme) slot. */
    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);

        $data = $request->validate([
            'scope' => ['required', 'string', 'in:'.implode(',', BrandingSpec::SCOPES)],
            'scope_id' => ['nullable', 'uuid'],
            'kind' => ['required', 'string', 'in:'.implode(',', BrandingSpec::KINDS)],
            'theme' => ['nullable', 'string', 'in:'.implode(',', BrandingSpec::THEMES)],
            'file' => ['required', 'file', 'max:2048', 'mimetypes:image/png,image/svg+xml'],
        ]);

        $asset = $this->branding->storeAsset(
            $data['scope'],
            $data['scope_id'] ?? null,
            $data['kind'],
            $data['theme'] ?? BrandingSpec::THEME_ANY,
            $request->file('file'),
        );

        return ApiResponse::success($this->present($asset), 'Branding asset stored.', status: 201);
    }

    /** GET /branding/assets/{brandingAsset}/file — stream the private bytes (tenant-scoped route binding). */
    public function file(Request $request, BrandingAsset $brandingAsset): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('branding.view'), 403);
        abort_unless(Storage::disk($brandingAsset->disk)->exists($brandingAsset->path), 404);

        $ext = $brandingAsset->mime === 'image/svg+xml' ? 'svg' : 'png';

        return Storage::disk($brandingAsset->disk)->download(
            $brandingAsset->path,
            "{$brandingAsset->kind}-{$brandingAsset->theme}.{$ext}",
        );
    }

    /** DELETE /branding/assets/{brandingAsset} — remove an asset and its private bytes. */
    public function destroy(Request $request, BrandingAsset $brandingAsset): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);

        $this->branding->removeAsset($brandingAsset);

        return ApiResponse::success(['status' => 'deleted'], 'Branding asset removed.');
    }

    /** GET /branding/settings — the stored brand settings for a scope (defaults when none saved yet). */
    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.view'), 403);

        $scope = $request->string('scope')->toString() ?: 'tenant';
        abort_unless(BrandingSpec::isScope($scope), 422, 'Unknown branding scope.');
        $scopeId = $request->string('scope_id')->toString() ?: null;

        $setting = BrandingSetting::query()
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->first();

        return ApiResponse::success([
            'scope' => $scope,
            'scope_id' => $scopeId,
            'colors' => $setting?->colors,
            'fonts' => $setting?->fonts,
            'white_label' => $setting !== null && $setting->white_label,
        ], 'Branding settings.');
    }

    /** PUT /branding/settings — upsert brand settings for a scope. */
    public function saveSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('branding.manage'), 403);

        $data = $request->validate([
            'scope' => ['required', 'string', 'in:'.implode(',', BrandingSpec::SCOPES)],
            'scope_id' => ['nullable', 'uuid'],
            'colors' => ['nullable', 'array'],
            'fonts' => ['nullable', 'array'],
            'white_label' => ['nullable', 'boolean'],
        ]);

        $setting = $this->branding->saveSettings($data['scope'], $data['scope_id'] ?? null, [
            'colors' => $data['colors'] ?? null,
            'fonts' => $data['fonts'] ?? null,
            'white_label' => (bool) ($data['white_label'] ?? false),
        ]);

        return ApiResponse::success([
            'scope' => $setting->scope,
            'scope_id' => $setting->scope_id,
            'colors' => $setting->colors,
            'fonts' => $setting->fonts,
            'white_label' => $setting->white_label,
        ], 'Branding settings saved.');
    }

    /**
     * The public, path-free shape of an asset: an opaque id + a download URL, plus safe metadata. The disk and
     * filesystem path are deliberately omitted.
     *
     * @return array<string,mixed>
     */
    private function present(BrandingAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'scope' => $asset->scope,
            'scope_id' => $asset->scope_id,
            'kind' => $asset->kind,
            'theme' => $asset->theme,
            'mime' => $asset->mime,
            'width' => $asset->width,
            'height' => $asset->height,
            'bytes' => $asset->bytes,
            'checksum' => $asset->checksum,
            'url' => route('branding.assets.file', ['brandingAsset' => $asset->id]),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
