<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOGO-UPLOAD-001 — a logo is uploaded as a file, replaced, removed and previewed; it is the same file every surface draws.
 *
 * Inheritance, nearest first: report → project → client → agency. Isolation: another tenant's client or project is a 404 and its
 * bytes are never streamed. Nothing here accepts an address to a picture.
 */
final class LogoUploadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    private ClientWorkspace $client;

    private ClientWorkspace $other;

    private Project $project;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['branding.disk' => 'local']);
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $view = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $view->givePermissionTo('clients.view', 'clients.view_all', 'reports.view', 'branding.view');
        $this->owner = $this->member('Owner', 'o@a.test', $owner);
        $this->viewer = $this->member('Viewer', 'v@a.test', $view);
        $this->client = $this->workspace('Razzah');
        $this->other = $this->workspace('Henka');
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Store', 'status' => 'active']);
        $report = new Report;
        $report->forceFill(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'R', 'type' => 'weekly', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed', 'period_start' => '2026-10-05', 'period_end' => '2026-10-11', 'currency' => 'USD', 'config' => []])->save();
        $this->report = $report;
    }

    private function member(string $name, string $email, Role $role): User
    {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant, Portal::Agency);
        $u->assignRole($role);

        return $u;
    }

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => strtolower($name), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 200);
    }

    private function up(string $url, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->post($url, ['file' => $file], ['Accept' => 'application/json']);
    }

    /** @return array{0:string,1:string|null} source and the scope of the logo the resolver would draw for this report */
    private function drawn(): array
    {
        $b = app(SharedLinkBranding::class)->forReport($this->report->fresh(), (string) $this->tenant->id, fn (?string $role = null): string => '/logo/'.($role ?? 'auto'));

        return [(string) $b['logo_source'], $b['client']['logo_url'] ?? null];
    }

    public function test_a_client_logo_of_every_accepted_format_is_uploaded_previewed_and_served_back(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#123456"/></svg>';
        foreach ([$this->png(), UploadedFile::fake()->image('l.jpg', 300, 300), UploadedFile::fake()->createWithContent('l.svg', $svg)] as $file) {
            $r = $this->up("/api/v1/app/clients/{$this->client->id}/logo", $file)->assertCreated();
            $r->assertJsonPath('data.own', true)->assertJsonPath('data.effective_source', 'own');
            $url = $r->json('data.url');
            $this->assertStringContainsString("/api/v1/app/clients/{$this->client->id}/logo/file?v=", $url);
            $this->actingAs($this->owner, 'sanctum')->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->assertSame(1, BrandingAsset::withoutGlobalScopes()->where('scope', 'client')->where('scope_id', $this->client->id)->count(), 'replacing leaves one logo, not a pile');
    }

    public function test_a_file_that_is_not_a_logo_is_refused_in_words_and_nothing_is_stored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lg');
        file_put_contents($path, '<?php echo 1; ?>');
        foreach ([UploadedFile::fake()->createWithContent('n.txt', 'text'), new UploadedFile($path, 'sneaky.png', 'image/png', null, true), UploadedFile::fake()->image('big.png')->size(2049), UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')] as $file) {
            $r = $this->up("/api/v1/app/clients/{$this->client->id}/logo", $file);
            $this->assertSame(422, $r->status(), $file->getClientOriginalName());
            $this->assertNotEmpty($r->json('errors.file'));
        }
        $this->assertSame(0, BrandingAsset::withoutGlobalScopes()->count());
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$this->client->id}/logo", ['file' => 'https://example.com/logo.png'])->assertStatus(422);
    }

    public function test_replacing_removes_an_older_logo_slot_so_the_new_picture_is_the_one_drawn(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(BrandingService::class)->storeAsset('client', (string) $this->client->id, 'report_logo', 'any', $this->png('old.png'));
        $this->assertSame(1, BrandingAsset::withoutGlobalScopes()->where('kind', 'report_logo')->count());

        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png('new.png'))->assertCreated();

        $rows = BrandingAsset::withoutGlobalScopes()->where('scope', 'client')->where('scope_id', $this->client->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('client_logo', $rows[0]->kind);
    }

    public function test_removal_leaves_the_agency_logo_in_force_and_says_so(): void
    {
        $this->up('/api/v1/branding/logo', $this->png('agency.png'))->assertCreated();
        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png())->assertCreated();

        $r = $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/app/clients/{$this->client->id}/logo")->assertOk();
        $r->assertJsonPath('data.own', false)->assertJsonPath('data.effective_source', 'agency');
        $this->assertNotNull($r->json('data.url'), 'the agency logo is what is drawn now');
        $this->assertSame(0, BrandingAsset::withoutGlobalScopes()->where('scope', 'client')->count());
        $this->assertSame(1, BrandingAsset::withoutGlobalScopes()->where('scope', 'tenant')->count(), 'the agency logo is untouched');
    }

    public function test_the_logo_inherits_report_then_project_then_client_then_agency(): void
    {
        $this->assertSame('none', $this->drawn()[0]);

        $this->up('/api/v1/branding/logo', $this->png())->assertCreated();
        $this->assertSame('tenant', $this->drawn()[0]);

        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png())->assertCreated();
        $this->assertSame('client', $this->drawn()[0]);

        $this->up("/api/v1/projects/{$this->project->id}/logo", $this->png())->assertCreated()->assertJsonPath('data.effective_source', 'own');
        $this->assertSame('project', $this->drawn()[0]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(BrandingService::class)->setLogo('report', (string) $this->report->id, $this->png());
        $this->assertSame('report', $this->drawn()[0]);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/logo")->assertOk()->assertJsonPath('data.effective_source', 'client');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(BrandingService::class)->removeLogo('report', (string) $this->report->id);
        $this->assertSame('client', $this->drawn()[0], 'with the project and report marks gone the client\'s is drawn again');
    }

    public function test_a_projects_preview_names_where_its_logo_comes_from(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/logo")->assertOk()->assertJsonPath('data.effective_source', null)->assertJsonPath('data.url', null);
        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png())->assertCreated();
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/logo")->assertJsonPath('data.effective_source', 'client');
        $this->actingAs($this->owner, 'sanctum')->get("/api/v1/projects/{$this->project->id}/logo/file")->assertOk();
    }

    public function test_only_someone_who_may_manage_changes_a_logo_but_a_reader_can_see_it(): void
    {
        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png(), $this->viewer)->assertForbidden();
        $this->up("/api/v1/projects/{$this->project->id}/logo", $this->png(), $this->viewer)->assertForbidden();
        $this->up('/api/v1/branding/logo', $this->png(), $this->viewer)->assertForbidden();
        $this->assertSame(0, BrandingAsset::withoutGlobalScopes()->count());

        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png())->assertCreated();
        $this->actingAs($this->viewer, 'sanctum')->getJson("/api/v1/app/clients/{$this->client->id}/logo")->assertOk()->assertJsonPath('data.own', true);
        $this->actingAs($this->viewer, 'sanctum')->deleteJson("/api/v1/app/clients/{$this->client->id}/logo")->assertForbidden();
    }

    public function test_another_tenants_client_and_project_are_never_readable_writable_or_streamed(): void
    {
        $this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png())->assertCreated();
        $this->up("/api/v1/projects/{$this->project->id}/logo", $this->png())->assertCreated();

        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($rival->id);
        $role = Role::create(['tenant_id' => $rival->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $spy = User::create(['name' => 'Spy', 'email' => 'spy@x.test', 'password' => 'secret123']);
        $this->grantMembership($spy, $rival, Portal::Agency);
        $spy->assignRole($role);
        app(TenantContext::class)->forget();

        foreach (["/api/v1/app/clients/{$this->client->id}/logo", "/api/v1/app/clients/{$this->client->id}/logo/file", "/api/v1/projects/{$this->project->id}/logo", "/api/v1/projects/{$this->project->id}/logo/file"] as $url) {
            $this->assertContains($this->actingAs($spy, 'sanctum')->get($url)->status(), [403, 404], $url);
        }
        $this->assertContains($this->up("/api/v1/app/clients/{$this->client->id}/logo", $this->png(), $spy)->status(), [403, 404]);
        $this->assertContains($this->actingAs($spy, 'sanctum')->deleteJson("/api/v1/app/clients/{$this->client->id}/logo")->status(), [403, 404]);
        $this->assertSame(2, BrandingAsset::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count(), 'nothing of this tenant changed');
    }

    public function test_one_clients_logo_is_never_drawn_for_another_client(): void
    {
        $this->up("/api/v1/app/clients/{$this->other->id}/logo", $this->png())->assertCreated();
        $this->assertSame('none', $this->drawn()[0], 'the other client\'s logo does not appear on this client\'s report');
    }

    public function test_the_old_logo_address_is_no_longer_stored(): void
    {
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$this->client->id}/settings", ['branding' => ['logo_url' => 'https://evil.example/x.png'], 'name' => 'Razzah'])->assertOk();
        $this->assertNull($this->client->fresh()->branding['logo_url'] ?? null);
    }
}
