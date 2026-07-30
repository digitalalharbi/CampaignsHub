<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Client Files (visibility, isolation, secure download, no path leak) + Activity (real event timeline). */
final class ClientFilesActivityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($ownerRole);
    }

    private function client(string $name = 'Acme'): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name.'-'.uniqid()),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    private function request(ClientWorkspace $c): string
    {
        $id = (string) Str::ulid();
        DB::table('external_requests')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'reference' => 'REQ-2026-'.strtoupper(Str::random(6)),
            'type_id' => DB::table('request_types')->value('id'), 'status_id' => DB::table('request_statuses')->value('id'),
            'client_id' => $c->id, 'contact_name' => 'C', 'contact_email' => 'c@x.test',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function file(string $requestId, string $name, bool $clientVisible): int
    {
        $path = "requests/tmp/{$requestId}/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, 'PDFDATA');

        return (int) DB::table('request_files')->insertGetId([
            'request_id' => $requestId, 'disk' => 'local', 'path' => $path, 'original_name' => $name,
            'mime' => 'application/pdf', 'size' => 7, 'is_client_visible' => $clientVisible,
            'checksum' => hash('sha256', 'PDFDATA'), 'created_at' => now(),
        ]);
    }

    public function test_files_list_shows_visibility_and_never_leaks_storage_path(): void
    {
        Storage::fake('local');
        $c = $this->client();
        $r = $this->request($c);
        $this->file($r, 'brief.pdf', true);
        $this->file($r, 'internal-notes.pdf', false);

        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}/files")->assertOk();
        $res->assertJsonCount(2, 'data.files');
        $body = $res->getContent();
        $this->assertStringContainsString('client_visible', $body);
        $this->assertStringContainsString('internal', $body);
        // Storage path / disk are never present in the payload.
        $this->assertStringNotContainsString('requests/tmp', $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringContainsString('checksum', $body);
    }

    public function test_client_visible_file_downloads_and_cross_client_file_is_blocked(): void
    {
        Storage::fake('local');
        $a = $this->client('A');
        $ra = $this->request($a);
        $fileA = $this->file($ra, 'a.pdf', true);

        $b = $this->client('B');
        $rb = $this->request($b);
        $fileB = $this->file($rb, 'b.pdf', true);

        // A's own client-visible file downloads.
        $this->actingAs($this->owner, 'sanctum')
            ->get("/api/v1/app/clients/{$a->id}/files/request/{$fileA}/download")->assertOk();

        // B's file is NOT reachable through client A's files endpoint (cross-client → 404).
        $this->actingAs($this->owner, 'sanctum')
            ->get("/api/v1/app/clients/{$a->id}/files/request/{$fileB}/download")->assertNotFound();
    }

    public function test_files_require_permission(): void
    {
        $c = $this->client();
        $limited = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Ltd', 'slug' => 'ltd']);
        $limited->givePermissionTo('clients.view', 'clients.view_all');
        $u = User::create(['name' => 'L', 'email' => 'l@a.test', 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant);
        $u->assignRole($limited);

        $this->actingAs($u, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}/files")->assertForbidden();
    }

    public function test_activity_timeline_is_built_from_real_events(): void
    {
        $c = $this->client();

        // A real classification change writes an audit entry...
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/classification", [
            'client_status' => 'active', 'industry' => 'e_commerce',
        ])->assertOk();

        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}/activity")->assertOk();
        $res->assertJsonPath('data.timeline.0.action', 'client.classification_updated')
            ->assertJsonPath('data.timeline.0.source', 'audit');
        // The change carries old/new values (not a fabricated array).
        $this->assertNotNull($res->json('data.timeline.0.new'));
    }
}
