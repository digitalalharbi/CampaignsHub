<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Drive\Providers\DriveProvider;
use App\Domains\Drive\Providers\DriveProviderRegistry;
use App\Domains\Drive\Providers\NullDriveProvider;
use App\Domains\Drive\Providers\SandboxDriveProvider;
use App\Domains\Drive\Services\DriveService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Drive domain is honest by construction: linking a folder stores a REFERENCE (never file bytes), browsing
 * with the shipped Null provider claims no connection (awaiting_credentials, no files), and only a configured
 * provider (Sandbox / a real credentialed adapter) populates file metadata — idempotently by file_id.
 */
final class DriveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function drive(): DriveService
    {
        return app(DriveService::class);
    }

    private const PROJECT_ID = '11111111-1111-4111-8111-111111111111';

    private function link(): DriveLink
    {
        return $this->drive()->linkFolder('project', self::PROJECT_ID, 'folder-xyz', 'Campaign Assets');
    }

    public function test_default_provider_is_the_null_adapter_and_is_not_configured(): void
    {
        $registry = app(DriveProviderRegistry::class);
        $this->assertSame('null', $registry->defaultKey());
        $provider = $registry->default();
        $this->assertInstanceOf(NullDriveProvider::class, $provider);
        $this->assertFalse($provider->isConfigured());
        $this->assertSame([], $provider->listFolder('any'));
        $this->assertNull($provider->authUrl());
    }

    public function test_link_folder_stores_a_reference_scoped_to_the_tenant(): void
    {
        $link = $this->link();

        $this->assertSame('project', $link->scope);
        $this->assertSame('folder-xyz', $link->folder_id);
        $this->assertSame('Campaign Assets', $link->folder_name);
        $this->assertSame($this->tenant->id, $link->tenant_id);

        // Re-linking the same scope is idempotent — it re-points, never duplicates.
        $again = $this->drive()->linkFolder('project', self::PROJECT_ID, 'folder-new', 'Renamed');
        $this->assertSame($link->id, $again->id);
        $this->assertSame('folder-new', $again->folder_id);
        $this->assertSame(1, DriveLink::count());
    }

    public function test_list_files_with_null_provider_is_awaiting_credentials_and_stores_nothing(): void
    {
        $link = $this->link();

        $result = $this->drive()->listFiles($link);

        $this->assertSame('awaiting_credentials', $result['state']);
        $this->assertFalse($result['configured']);
        $this->assertSame([], $result['files']);
        $this->assertSame(0, DriveFile::count());
    }

    public function test_sandbox_flag_selects_sandbox_and_upserts_demo_files_idempotently(): void
    {
        // The off-production sandbox flag selects the deterministic demo provider.
        config()->set('drive.sandbox', true);
        $this->assertSame('sandbox', app(DriveProviderRegistry::class)->defaultKey());
        $this->assertInstanceOf(SandboxDriveProvider::class, app(DriveProviderRegistry::class)->default());

        $link = $this->link();

        $first = $this->drive()->listFiles($link);
        $this->assertSame('sandbox_verified', $first['state']);
        $this->assertTrue($first['configured']);
        $this->assertCount(3, $first['files']);
        $this->assertSame(3, DriveFile::count());

        // Every demo file carries a thumbnail + web view link so the UI is exercisable.
        foreach ($first['files'] as $file) {
            $this->assertNotNull($file->thumbnail_link);
            $this->assertNotNull($file->web_view_link);
        }

        // Running again upserts by file_id — no duplication.
        $second = $this->drive()->listFiles($link);
        $this->assertCount(3, $second['files']);
        $this->assertSame(3, DriveFile::count());
    }

    public function test_a_configured_provider_populates_files_and_marks_synced(): void
    {
        // Bind a fake *configured* provider to prove the honest configured path (not sandbox, not null).
        config()->set('drive.providers.fake', ConfiguredFakeDriveProvider::class);
        config()->set('drive.default', 'fake');

        $this->assertTrue(app(DriveProviderRegistry::class)->isConfigured('fake'));

        $link = $this->link();
        $result = $this->drive()->listFiles($link);

        $this->assertSame('synced', $result['state']);
        $this->assertTrue($result['configured']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('fake-file-1', $result['files'][0]->file_id);
        $this->assertSame('Real Doc.pdf', $result['files'][0]->name);
    }

    public function test_refresh_file_updates_metadata_from_a_configured_provider(): void
    {
        config()->set('drive.providers.fake', ConfiguredFakeDriveProvider::class);
        config()->set('drive.default', 'fake');

        $link = $this->link();
        $file = $this->drive()->listFiles($link)['files'][0];
        $this->assertSame('7', $file->version);

        $refreshed = $this->drive()->refreshFile($file);
        $this->assertSame('8', $refreshed->version); // provider bumps version on refresh
        $this->assertSame(1, DriveFile::count()); // upsert, not insert
    }

    public function test_attach_file_links_a_file_to_a_target(): void
    {
        config()->set('drive.sandbox', true);
        $link = $this->link();
        $file = $this->drive()->listFiles($link)['files'][0];

        $targetId = '22222222-2222-4222-8222-222222222222';
        $attached = $this->drive()->attachFile($file, 'creative', $targetId);

        $this->assertSame('creative', $attached->attached_to_type);
        $this->assertSame($targetId, $attached->attached_to_id);
        $this->assertDatabaseHas('drive_files', [
            'id' => $file->id, 'attached_to_type' => 'creative', 'attached_to_id' => $targetId,
        ]);
    }

    public function test_unlink_removes_the_link_and_cascades_its_files(): void
    {
        config()->set('drive.sandbox', true);
        $link = $this->link();
        $this->drive()->listFiles($link);
        $this->assertSame(3, DriveFile::count());

        $this->drive()->unlink($link);

        $this->assertSame(0, DriveLink::count());
        $this->assertSame(0, DriveFile::count());
    }

    public function test_tenant_isolation_hides_cross_tenant_links_and_files(): void
    {
        // Tenant A links a folder and (via sandbox) caches files.
        config()->set('drive.sandbox', true);
        $linkA = $this->link();
        $this->drive()->listFiles($linkA);
        $this->assertSame(1, DriveLink::count());
        $this->assertSame(3, DriveFile::count());

        // Switch to Tenant B — A's records are invisible under the tenant global scope.
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenantB->id);

        $this->assertSame(0, DriveLink::count());
        $this->assertSame(0, DriveFile::count());
        $this->assertNull(DriveLink::find($linkA->id));

        // The rows still exist globally (proving the scope hides, not deletes).
        $this->assertSame(1, DriveLink::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(3, DriveFile::withoutGlobalScope(TenantScope::class)->count());
    }
}

/**
 * A stand-in for a real, credentialed Drive adapter — used only to prove the honest configured path. It returns
 * a single deterministic file and bumps its version on refresh so refreshFile() has an observable effect.
 */
final class ConfiguredFakeDriveProvider implements DriveProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @return list<array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string}> */
    public function listFolder(string $folderId): array
    {
        return [$this->doc(7)];
    }

    /** @return array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string} */
    public function fileMetadata(string $fileId): array
    {
        return $this->doc(8);
    }

    public function authUrl(): ?string
    {
        return 'https://drive.example.test/oauth/authorize';
    }

    /** @return array{file_id: string, name: string, mime: ?string, size: ?int, thumbnail_link: ?string, web_view_link: ?string, modified_time: ?string, version: ?string} */
    private function doc(int $version): array
    {
        return [
            'file_id' => 'fake-file-1',
            'name' => 'Real Doc.pdf',
            'mime' => 'application/pdf',
            'size' => 12_345,
            'thumbnail_link' => 'https://drive.example.test/fake-file-1/thumb.png',
            'web_view_link' => 'https://drive.example.test/fake-file-1/view',
            'modified_time' => '2026-07-28T09:00:00+00:00',
            'version' => (string) $version,
        ];
    }
}
