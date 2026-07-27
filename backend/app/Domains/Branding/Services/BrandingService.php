<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Domains\Branding\BrandingSpec;
use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The Branding Center's write/read core: it validates an upload against BrandingSpec, stores the bytes on a
 * PRIVATE disk (preserving the pristine original), and upserts the unique (scope, scope_id, kind, theme) slot.
 * Reads resolve the *effective* asset set by falling back client → tenant → platform.
 *
 * This class enforces the spec (MIME/size) and never trusts the caller's claimed dimensions — width/height are
 * measured from the file. White-label availability is a subscription concern decided upstream; saveSettings()
 * simply records the boolean it is handed.
 */
final class BrandingService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Validate + store one brand file and upsert its slot. A second upload to the same
     * (scope, scope_id, kind, theme) replaces the file and deletes the superseded bytes.
     *
     * @throws InvalidArgumentException when the scope/theme is unknown, the theme is not supported by the kind,
     *                                  or the file fails BrandingSpec (MIME/size).
     */
    public function storeAsset(string $scope, ?string $scopeId, string $kind, string $theme, UploadedFile $file): BrandingAsset
    {
        $theme = $theme !== '' ? $theme : BrandingSpec::THEME_ANY;

        if (! BrandingSpec::isScope($scope)) {
            throw new InvalidArgumentException("Unknown branding scope [{$scope}].");
        }
        if (! BrandingSpec::isTheme($theme)) {
            throw new InvalidArgumentException("Unknown branding theme [{$theme}].");
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $bytes = (int) $file->getSize();

        $verdict = BrandingSpec::validateUpload($kind, $mime, $bytes);
        if (! ($verdict['ok'] ?? false)) {
            throw new InvalidArgumentException($verdict['error'] ?? 'Invalid branding upload.');
        }

        // A light/dark variant only makes sense for kinds that ship a themed pair.
        if (in_array($theme, [BrandingSpec::THEME_LIGHT, BrandingSpec::THEME_DARK], true) && ! BrandingSpec::supportsThemes($kind)) {
            throw new InvalidArgumentException("Branding kind [{$kind}] does not support light/dark themes.");
        }

        $tenantId = $this->tenant->tenantId();
        $disk = (string) config('branding.disk', 'local');

        // Read everything we need from the temp file BEFORE it is moved by the store.
        $realPath = (string) $file->getRealPath();
        $checksum = hash_file('sha256', $realPath) ?: '';
        [$width, $height] = $this->measure($realPath, $mime);
        $contents = (string) file_get_contents($realPath);

        $ext = $mime === 'image/svg+xml' ? 'svg' : 'png';
        $dir = $this->directory($tenantId, $scope, $scopeId, $kind);
        $base = (string) Str::uuid();
        $path = "{$dir}/{$base}.{$ext}";
        $originalPath = "{$dir}/originals/{$base}.{$ext}"; // pristine copy, preserved untouched

        Storage::disk($disk)->put($path, $contents);
        Storage::disk($disk)->put($originalPath, $contents);

        // Capture the file(s) an existing slot points at, so we can drop them once the row is repointed.
        $superseded = BrandingAsset::query()
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->where('kind', $kind)
            ->where('theme', $theme)
            ->first();

        $asset = BrandingAsset::updateOrCreate(
            ['tenant_id' => $tenantId, 'scope' => $scope, 'scope_id' => $scopeId, 'kind' => $kind, 'theme' => $theme],
            [
                'disk' => $disk,
                'path' => $path,
                'original_path' => $originalPath,
                'mime' => $mime,
                'width' => $width,
                'height' => $height,
                'bytes' => $bytes,
                'checksum' => $checksum,
                'created_by' => Auth::id(),
            ],
        );

        if ($superseded !== null) {
            $this->deleteFiles($superseded->disk, [$superseded->path, $superseded->original_path], [$path, $originalPath]);
        }

        return $asset;
    }

    /** Delete a brand asset and the private bytes behind it. */
    public function removeAsset(BrandingAsset $asset): void
    {
        $this->deleteFiles($asset->disk, [$asset->path, $asset->original_path], []);
        $asset->delete();
    }

    /**
     * The effective brand asset set for a scope: for every kind, the nearest asset walking the fallback chain
     * (the requested scope → tenant → platform), preferring the requested theme and falling back to `any`.
     *
     * @return array<string, BrandingAsset> keyed by kind
     */
    public function resolve(string $scope, ?string $scopeId, string $theme = BrandingSpec::THEME_ANY): array
    {
        $layers = $this->fallbackLayers($scope, $scopeId);

        $candidates = BrandingAsset::query()
            ->where(function ($query) use ($layers): void {
                foreach ($layers as [$layerScope, $layerId]) {
                    $query->orWhere(function ($inner) use ($layerScope, $layerId): void {
                        $inner->where('scope', $layerScope);
                        $layerId === null ? $inner->whereNull('scope_id') : $inner->where('scope_id', $layerId);
                    });
                }
            })
            ->get();

        $resolved = [];
        foreach (BrandingSpec::KINDS as $kind) {
            $asset = $this->pick($candidates, $layers, $kind, $theme);
            if ($asset !== null) {
                $resolved[$kind] = $asset;
            }
        }

        return $resolved;
    }

    /**
     * Upsert non-file brand settings for a scope. white_label is recorded verbatim — permission to use it is
     * decided upstream (subscription plan), never here.
     *
     * @param  array{colors?: array<string,mixed>|null, fonts?: array<string,mixed>|null, white_label?: bool}  $data
     */
    public function saveSettings(string $scope, ?string $scopeId, array $data): BrandingSetting
    {
        if (! BrandingSpec::isScope($scope)) {
            throw new InvalidArgumentException("Unknown branding scope [{$scope}].");
        }

        return BrandingSetting::updateOrCreate(
            ['tenant_id' => $this->tenant->tenantId(), 'scope' => $scope, 'scope_id' => $scopeId],
            [
                'colors' => $data['colors'] ?? null,
                'fonts' => $data['fonts'] ?? null,
                'white_label' => (bool) ($data['white_label'] ?? false),
            ],
        );
    }

    /**
     * The ownership layers to search, nearest first. A concrete scope (client/project/report/…) falls back to
     * the tenant-wide brand and then the platform default.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function fallbackLayers(string $scope, ?string $scopeId): array
    {
        if ($scope === 'platform') {
            return [['platform', null]];
        }

        if ($scope === 'tenant') {
            return [['tenant', null], ['platform', null]];
        }

        return [[$scope, $scopeId], ['tenant', null], ['platform', null]];
    }

    /**
     * From the pre-loaded candidate set, choose the asset for a kind: the nearest layer wins, and within a
     * layer the requested theme is preferred over the theme-agnostic `any`.
     *
     * @param  Collection<int, BrandingAsset>  $candidates
     * @param  list<array{0: string, 1: ?string}>  $layers
     */
    private function pick($candidates, array $layers, string $kind, string $theme): ?BrandingAsset
    {
        foreach ($layers as [$layerScope, $layerId]) {
            $inLayer = $candidates->filter(fn (BrandingAsset $a): bool => $a->scope === $layerScope
                && $a->scope_id === $layerId
                && $a->kind === $kind);

            if ($inLayer->isEmpty()) {
                continue;
            }

            $preferred = $inLayer->firstWhere('theme', $theme);
            if ($preferred instanceof BrandingAsset) {
                return $preferred;
            }

            $any = $inLayer->firstWhere('theme', BrandingSpec::THEME_ANY);
            if ($any instanceof BrandingAsset) {
                return $any;
            }
        }

        return null;
    }

    /** Measure a raster's pixel size; vectors (SVG) have no intrinsic size and report null. */
    private function measure(string $realPath, string $mime): array
    {
        if ($mime === 'image/svg+xml' || $realPath === '') {
            return [null, null];
        }

        $info = @getimagesize($realPath);

        return is_array($info) ? [(int) $info[0], (int) $info[1]] : [null, null];
    }

    private function directory(string $tenantId, string $scope, ?string $scopeId, string $kind): string
    {
        $root = trim((string) config('branding.root', 'branding'), '/');
        $segment = $scopeId !== null ? "{$scope}/{$scopeId}" : $scope;

        return "{$root}/{$tenantId}/{$segment}/{$kind}";
    }

    /**
     * Delete disk files, skipping any path that is still referenced (e.g. an idempotent re-store that reused
     * the same path).
     *
     * @param  list<string|null>  $paths
     * @param  list<string|null>  $keep
     */
    private function deleteFiles(string $disk, array $paths, array $keep): void
    {
        $keep = array_filter($keep);
        foreach (array_filter($paths) as $path) {
            if (in_array($path, $keep, true)) {
                continue;
            }
            Storage::disk($disk)->delete($path);
        }
    }
}
