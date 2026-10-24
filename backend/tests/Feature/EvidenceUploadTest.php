<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\PlatformEvidence;
use App\Domains\Delivery\Services\PlatformEvidenceService;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UPLOAD-SCREENSHOT-001 — a platform screenshot attached to a periodic delivery.
 *
 * Production refused ordinary screenshots. The causes were in the runtime, not in the controller this file exercises:
 * nginx's 1 MB default request-body limit answered a bare HTML 413 before PHP saw the request, and PHP's 2 MB per-file
 * default failed the upload before Laravel validated it. The controller-level matrix below is what the application itself
 * guarantees (formats, size, refusal wording, access, privacy); `UploadRuntimeLimitsTest` pins the runtime half.
 * Neither is Production proof — that is the Owner's attach on the real address.
 */
final class EvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Store', 'status' => 'active']);
    }

    /** @return array<string,mixed> */
    private function body(UploadedFile $file, ?ClientWorkspace $ws = null): array
    {
        return [
            'client_workspace_id' => ($ws ?? $this->workspace)->id, 'project_id' => $this->project->id, 'provider' => 'snapchat',
            'period_start' => '2026-10-05', 'period_end' => '2026-10-11', 'file' => $file,
        ];
    }

    private function upload(UploadedFile $file, ?ClientWorkspace $ws = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson('/api/v1/delivery/evidence', $this->body($file, $ws));
    }

    public function test_png_jpeg_webp_and_pdf_screenshots_of_ordinary_size_are_accepted_stored_and_served_back_byte_for_byte(): void
    {
        $files = [
            UploadedFile::fake()->image('a.png', 1200, 800)->size(3000),
            UploadedFile::fake()->image('b.jpg', 1200, 800)->size(4500),
            UploadedFile::fake()->createWithContent('c.webp', $this->webp()),
            UploadedFile::fake()->createWithContent('d.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"),
        ];
        foreach ($files as $file) {
            $res = $this->upload($file)->assertCreated();
            $row = PlatformEvidence::withoutGlobalScopes()->findOrFail($res->json('data.id'));
            Storage::disk('local')->assertExists($row->path);
            $this->assertStringStartsWith("evidence/{$this->tenant->id}/{$this->workspace->id}/", $row->path);
            $served = $this->actingAs($this->owner, 'sanctum')->get("/api/v1/delivery/evidence/{$row->id}/file");
            $served->assertOk();
            $this->assertSame(Storage::disk('local')->get($row->path), $served->streamedContent());
            $this->assertSame('nosniff', $served->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_a_file_over_the_limit_is_refused_in_words_never_as_a_server_error(): void
    {
        $res = $this->upload(UploadedFile::fake()->image('big.png', 800, 600)->size(PlatformEvidenceService::MAX_KB + 1));

        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('errors.file'), 'the refusal names the file field');
        $this->assertNotSame(500, $res->status());
        $this->assertSame(0, PlatformEvidence::withoutGlobalScopes()->count(), 'nothing was stored');
        $this->upload(UploadedFile::fake()->image('edge.png', 800, 600)->size(PlatformEvidenceService::MAX_KB))->assertCreated();
    }

    public function test_formats_that_are_not_screenshots_are_refused_with_a_message_about_the_file(): void
    {
        foreach ([
            UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->createWithContent('notes.txt', 'plain text'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
            $this->spoofed('fake.png', '<?php echo 1; ?>'),
        ] as $file) {
            $res = $this->upload($file);
            $this->assertSame(422, $res->status(), $file->getClientOriginalName().' / '.$file->getMimeType());
            $this->assertNotEmpty($res->json('errors.file'), $file->getClientOriginalName().' is refused on the file field');
        }
        $this->assertSame(0, PlatformEvidence::withoutGlobalScopes()->count());
    }

    public function test_a_missing_file_and_a_member_without_approval_rights_are_refused_distinctly(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/delivery/evidence', ['file' => null] + $this->body(UploadedFile::fake()->image('a.png')))->assertStatus(422);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $role->givePermissionTo('clients.view', 'clients.view_all', 'reports.view');
        $viewer = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant, Portal::Agency);
        $viewer->assignRole($role);

        $this->upload(UploadedFile::fake()->image('a.png'), as: $viewer)->assertForbidden();
        $this->assertSame(0, PlatformEvidence::withoutGlobalScopes()->count());
    }

    public function test_another_tenants_client_cannot_receive_or_read_an_upload(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $foreign = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $res = $this->upload(UploadedFile::fake()->image('a.png'), $foreign);
        $this->assertContains($res->status(), [403, 404], 'a client of another tenant is not reachable: '.$res->getContent());
        $this->assertSame(0, PlatformEvidence::withoutGlobalScopes()->count());

        $mine = $this->upload(UploadedFile::fake()->image('a.png'))->assertCreated()->json('data.id');
        $rival = User::create(['name' => 'Rival', 'email' => 'r@x.test', 'password' => 'secret123']);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->grantMembership($rival, $other, Portal::Agency);
        app(TenantContext::class)->setTenantId($other->id);
        $rival->assignRole($role);
        app(TenantContext::class)->forget();

        $this->assertContains($this->actingAs($rival, 'sanctum')->get("/api/v1/delivery/evidence/{$mine}/file")->status(), [403, 404]);
        $this->assertContains($this->actingAs($rival, 'sanctum')->deleteJson("/api/v1/delivery/evidence/{$mine}")->status(), [403, 404]);
        $this->assertNotNull(PlatformEvidence::withoutGlobalScopes()->find($mine), 'the rival could not delete it');
    }

    /** A real file on disk, so the mime is read from its CONTENT as in production — `UploadedFile::fake()` reports it from the extension. */
    private function spoofed(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ev');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function webp(): string
    {
        return "RIFF\x1a\x00\x00\x00WEBPVP8L\x0d\x00\x00\x00\x2f\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\xfe\x07\x00";
    }
}
