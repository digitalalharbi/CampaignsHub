<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — the unified files library and the Drive link routes honour the membership's client ceiling,
 * and a Drive link's scope id must be a real target in the caller's own tenant.
 */
final class DriveAndFilesLibraryClientCeilingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->buildCeilingFixture();
    }

    private function fileOf(string $clientId, string $name): void
    {
        $req = ExternalRequest::create([
            'tenant_id' => $this->agency->id, 'reference' => 'REQ-'.strtoupper(substr(md5($name), 0, 8)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'new')->firstOrFail()->id,
            'contact_name' => 'C', 'contact_email' => 'c@x.test', 'contact_phone' => '+966500000009',
            'client_id' => $clientId, 'submitted_at' => now(),
        ]);
        DB::table('request_files')->insert(['request_id' => $req->id, 'disk' => 'local', 'path' => 'x/'.$name, 'original_name' => $name,
            'mime' => 'application/pdf', 'size' => 10, 'is_client_visible' => true, 'created_at' => now()]);
    }

    public function test_the_files_library_lists_only_the_reachable_clients_files(): void
    {
        $this->fileOf((string) $this->clientA->id, 'a-secret.pdf');
        $this->fileOf((string) $this->clientB->id, 'b-brief.pdf');
        DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'client', 'scope_id' => $this->clientA->id, 'folder_id' => 'fa', 'folder_name' => 'A']);
        DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'client', 'scope_id' => $this->clientB->id, 'folder_id' => 'fb', 'folder_name' => 'B']);

        $op = $this->scopedTo($this->clientB, ['clients.manage_files']);
        $res = $this->actingAs($op, 'sanctum')->getJson('/api/v1/files/library')->assertOk();

        $this->assertSame(['b-brief.pdf'], array_column($res->json('data.files'), 'name'));
        $this->assertSame(1, $res->json('data.files_total'));
        $this->assertSame(1, $res->json('data.drive_links'));

        $all = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/files/library')->assertOk();
        $this->assertCount(2, $all->json('data.files'));
        $this->assertSame(2, $all->json('data.drive_links'));
    }

    public function test_a_scoped_member_lists_only_their_clients_drive_links(): void
    {
        DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'client', 'scope_id' => $this->clientA->id, 'folder_id' => 'fa', 'folder_name' => 'A-folder']);
        DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'project', 'scope_id' => $this->projectB->id, 'folder_id' => 'fb', 'folder_name' => 'B-folder']);
        DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'tenant', 'scope_id' => null, 'folder_id' => 'ft', 'folder_name' => 'Agency-root']);

        $op = $this->scopedTo($this->clientB, ['drive.view', 'drive.manage']);
        $names = array_column($this->actingAs($op, 'sanctum')->getJson('/api/v1/drive/links')->assertOk()->json('data'), 'folder_name');

        $this->assertSame(['B-folder'], $names);
        $this->assertCount(3, $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/drive/links')->json('data'));
    }

    public function test_a_scoped_member_cannot_repoint_unlink_or_browse_another_clients_link(): void
    {
        $linkA = DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'client', 'scope_id' => $this->clientA->id, 'folder_id' => 'fa', 'folder_name' => 'A-folder']);
        $op = $this->scopedTo($this->clientB, ['drive.view', 'drive.manage']);

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/drive/links', [
            'scope' => 'client', 'scope_id' => (string) $this->clientA->id, 'folder_id' => 'evil', 'folder_name' => 'Evil',
        ])->assertForbidden();
        $this->actingAs($op, 'sanctum')->getJson("/api/v1/drive/links/{$linkA->id}/files")->assertNotFound();
        $this->actingAs($op, 'sanctum')->deleteJson("/api/v1/drive/links/{$linkA->id}")->assertNotFound();
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/drive/links', [
            'scope' => 'tenant', 'folder_id' => 'evil', 'folder_name' => 'Evil',
        ])->assertForbidden();

        $this->assertSame('fa', $linkA->fresh()->folder_id);

        // Their own client still works.
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/drive/links', [
            'scope' => 'client', 'scope_id' => (string) $this->clientB->id, 'folder_id' => 'ok', 'folder_name' => 'Mine',
        ])->assertCreated();
    }

    public function test_a_link_scope_must_exist_in_the_callers_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $foreign = $this->makeClient('Foreign', $other);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/drive/links', [
            'scope' => 'client', 'scope_id' => (string) $foreign->id, 'folder_id' => 'x', 'folder_name' => 'X',
        ])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/drive/links', [
            'scope' => 'client', 'scope_id' => '11111111-1111-4111-8111-111111111111', 'folder_id' => 'x', 'folder_name' => 'X',
        ])->assertStatus(422);
        $this->assertSame(0, DriveLink::query()->count());

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/drive/links', [
            'scope' => 'client', 'scope_id' => (string) $this->clientA->id, 'folder_id' => 'x', 'folder_name' => 'X',
        ])->assertCreated();
    }

    public function test_a_file_cannot_be_attached_to_an_unverified_target_or_through_another_clients_link(): void
    {
        $linkA = DriveLink::create(['tenant_id' => $this->agency->id, 'scope' => 'client', 'scope_id' => $this->clientA->id, 'folder_id' => 'fa', 'folder_name' => 'A']);
        $file = DriveFile::create(['tenant_id' => $this->agency->id, 'drive_link_id' => $linkA->id, 'file_id' => 'f1', 'name' => 'a.png']);
        $op = $this->scopedTo($this->clientB, ['drive.view', 'drive.manage']);

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/drive/files/{$file->id}/attach", [
            'attached_to_type' => 'creative', 'attached_to_id' => '22222222-2222-4222-8222-222222222222',
        ])->assertNotFound();

        // The owner cannot attach it to an id that is no creative of this tenant either.
        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/drive/files/{$file->id}/attach", [
            'attached_to_type' => 'creative', 'attached_to_id' => '22222222-2222-4222-8222-222222222222',
        ])->assertStatus(422);
        $this->assertNull($file->fresh()->attached_to_id);
    }
}
