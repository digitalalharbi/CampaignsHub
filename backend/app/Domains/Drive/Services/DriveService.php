<?php

declare(strict_types=1);

namespace App\Domains\Drive\Services;

use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Drive\Providers\DriveProviderRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use InvalidArgumentException;

/**
 * Google Drive content-linking: link a folder at a scope, browse its file METADATA, attach files to a target.
 *
 * Honesty rules enforced here (not by convention):
 *   - listFiles() NEVER invents a connection. When the resolved provider is not configured it returns
 *     `awaiting_credentials` with zero files and stores nothing — it never claims a live Drive connection.
 *   - Only a configured provider (Sandbox off-prod, or a real credentialed adapter) populates file rows, and
 *     it does so idempotently: re-running listFiles() upserts by (drive_link_id, file_id), never duplicating.
 *   - This class fetches METADATA only. It never downloads or duplicates file bytes.
 *
 * There is NO real Google Drive integration wired here — that is a provider binding. Out of the box the Null
 * adapter is resolved and every browse is Awaiting External Dependency (Google OAuth).
 */
final class DriveService
{
    /** @var list<string> */
    private const SCOPES = ['tenant', 'client', 'project', 'campaign'];

    public function __construct(
        private readonly DriveProviderRegistry $providers,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Link (or re-point) a Drive folder for a scope. Idempotent per (tenant, scope, scope_id): re-linking the
     * same scope updates the folder in place rather than creating a duplicate.
     *
     * @param  array<string,mixed>  $options  connection_id?: ?string, created_by?: ?int
     */
    public function linkFolder(string $scope, ?string $scopeId, string $folderId, string $folderName, array $options = []): DriveLink
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException("Unsupported Drive link scope [{$scope}].");
        }

        $tenantId = $this->tenant->hasTenant() ? $this->tenant->tenantId() : null;

        /** @var DriveLink $link */
        $link = DriveLink::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'scope' => $scope, 'scope_id' => $scopeId],
            [
                'folder_id' => $folderId,
                'folder_name' => $folderName,
                'connection_id' => $options['connection_id'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ],
        );

        return $link;
    }

    /**
     * Browse the files under a link. Resolves the default provider; when it is not configured the result is an
     * honest `awaiting_credentials` state with no files and no writes. Otherwise the provider's folder listing
     * is upserted into drive_files (idempotent by file_id) and returned.
     *
     * @return array{state: string, provider: string, configured: bool, files: list<DriveFile>}
     */
    public function listFiles(DriveLink $link): array
    {
        $provider = $this->providers->default();

        if (! $provider->isConfigured()) {
            return [
                'state' => 'awaiting_credentials',
                'provider' => $provider->name(),
                'configured' => false,
                'files' => [],
            ];
        }

        $files = [];
        foreach ($provider->listFolder($link->folder_id) as $meta) {
            $files[] = $this->upsertFile($link, $meta);
        }

        return [
            'state' => $provider->name() === 'sandbox' ? 'sandbox_verified' : 'synced',
            'provider' => $provider->name(),
            'configured' => true,
            'files' => $files,
        ];
    }

    /** Attach a browsed file to a target (e.g. a creative or a report). Returns the updated row. */
    public function attachFile(DriveFile $file, string $attachToType, string $attachToId): DriveFile
    {
        $file->forceFill([
            'attached_to_type' => $attachToType,
            'attached_to_id' => $attachToId,
        ])->save();

        return $file;
    }

    /**
     * Refresh a single file's metadata from the provider. With an unconfigured provider the row is left
     * untouched (nothing to fetch). Returns the (possibly updated) row.
     */
    public function refreshFile(DriveFile $file): DriveFile
    {
        $provider = $this->providers->default();
        if (! $provider->isConfigured()) {
            return $file;
        }

        $meta = $provider->fileMetadata($file->file_id);
        if ($meta === []) {
            return $file;
        }

        $link = $file->driveLink()->first();
        if ($link === null) {
            return $file;
        }

        return $this->upsertFile($link, $meta);
    }

    /** Remove a link and (via cascade) its cached file metadata. */
    public function unlink(DriveLink $link): void
    {
        $link->delete();
    }

    /**
     * Upsert one file-metadata row under a link, keyed by (drive_link_id, file_id) so a repeated browse never
     * duplicates. Returns the persisted model.
     *
     * @param  array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}  $meta
     */
    private function upsertFile(DriveLink $link, array $meta): DriveFile
    {
        /** @var DriveFile $file */
        $file = DriveFile::query()->updateOrCreate(
            ['drive_link_id' => $link->getKey(), 'file_id' => $meta['file_id']],
            [
                'tenant_id' => $link->tenant_id,
                'name' => $meta['name'],
                'mime' => $meta['mime'] ?? null,
                'size' => $meta['size'] ?? null,
                'thumbnail_link' => $meta['thumbnail_link'] ?? null,
                'web_view_link' => $meta['web_view_link'] ?? null,
                'modified_time' => $meta['modified_time'] ?? null,
                'version' => $meta['version'] ?? null,
            ],
        );

        return $file;
    }
}
