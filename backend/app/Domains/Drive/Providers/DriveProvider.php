<?php

declare(strict_types=1);

namespace App\Domains\Drive\Providers;

/**
 * A Google Drive content adapter. The Drive domain never talks to a concrete Drive client — it asks the
 * registry for the configured provider. A real integration is plugged in by binding a configured
 * implementation (backed by Google OAuth); until then the Null adapter reports "not configured" and no file
 * metadata is ever fetched.
 *
 * HONESTY: a provider may only return file metadata it can actually reach. When isConfigured() is false the
 * caller records `awaiting_credentials` and stores nothing — it never claims a live Drive connection.
 *
 * A file-metadata array has the shape:
 *   array{
 *     file_id: string, name: string, mime: ?string, size: ?int,
 *     thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string
 *   }
 */
interface DriveProvider
{
    public function name(): string;

    /** True only when real Google OAuth credentials are wired. When false, callers record awaiting_credentials. */
    public function isConfigured(): bool;

    /**
     * List the file metadata inside a Drive folder. An unconfigured adapter MUST return [] — it can reach
     * nothing.
     *
     * @return list<array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}>
     */
    public function listFolder(string $folderId): array;

    /**
     * Fetch metadata for a single Drive file. An unconfigured adapter MUST return [].
     *
     * @return array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}|array{}
     */
    public function fileMetadata(string $fileId): array;

    /** The OAuth consent URL to establish a real connection, or null when the adapter cannot authenticate. */
    public function authUrl(): ?string;
}
