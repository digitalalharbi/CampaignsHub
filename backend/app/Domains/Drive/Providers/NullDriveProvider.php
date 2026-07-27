<?php

declare(strict_types=1);

namespace App\Domains\Drive\Providers;

/**
 * Placeholder Drive adapter used until real Google OAuth credentials are wired. It NEVER reaches Drive, NEVER
 * returns file metadata, and can NEVER claim a connection — callers record `awaiting_credentials`. Swapping in
 * a real, configured provider is a single config binding; no call site changes.
 */
final class NullDriveProvider implements DriveProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    /** @return list<array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}> */
    public function listFolder(string $folderId): array
    {
        return [];
    }

    /** @return array{} */
    public function fileMetadata(string $fileId): array
    {
        return [];
    }

    public function authUrl(): ?string
    {
        return null;
    }
}
