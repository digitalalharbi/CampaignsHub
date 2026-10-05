<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * REPORT-IDENTITY-CONTROLS-001 — the two marks are configurable from the screen that uses them.
 *
 * The owner's report of the builder's identity block: the company upload «appears ineffective», the
 * client upload gives no sign it saved, and there is no way back to no logo at all. Three separate
 * facts, and the first is not an upload defect — it is a RESOLUTION one, held here.
 *
 * ## The three things that must not bleed
 *
 *   CampaignsHub product identity  the application's own chrome. A report mark NEVER touches it.
 *   Company report identity        the tenant layer. The preparer.
 *   Client report identity         one client workspace. Never another's.
 */
final class ReportIdentityControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $client;

    private ClientWorkspace $otherClient;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create([
            'name' => 'Op', 'email' => 'ric-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->otherClient = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'Nova', 'slug' => 'nova-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(), 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
    }

    /**
     * REPORT-MARK-THEME-001 — «شعار الشركة لا يعمل».
     *
     * A company mark uploaded through the Branding Center's LIGHT slot was invisible to every report:
     * the resolver asked for the theme-agnostic slot and matched only that one, so an operator who
     * had configured a logo, and could see it in the Branding Center, was told the company had none.
     * The asset was stored correctly the whole time.
     */
    public function test_a_company_mark_stored_under_a_theme_still_reaches_the_report(): void
    {
        $this->mark('tenant', null, 'report_logo', 'light');

        $identity = $this->identity();

        $this->assertNotNull($identity['agency']['logo_url'], 'a configured company mark was invisible to the report');
    }

    /** The same rule for the client's own mark, in its own layer. */
    public function test_a_client_mark_stored_under_a_theme_still_reaches_the_report(): void
    {
        $this->mark('client', (string) $this->client->getKey(), 'client_logo', 'dark');

        $this->assertNotNull($this->identity()['client']['logo_url']);
    }

    /** And the theme fallback never lets one client answer for another. */
    public function test_the_theme_fallback_does_not_let_one_client_answer_for_another(): void
    {
        $this->mark('client', (string) $this->otherClient->getKey(), 'client_logo', 'light');

        $this->assertNull($this->identity()['client']['logo_url'], "another client's mark reached this report");
    }

    /** Removing the company mark puts the company back to its NAME, and leaves the client alone. */
    public function test_removing_the_company_mark_falls_back_to_the_company_name(): void
    {
        $this->mark('tenant', null, 'report_logo', 'any');
        $this->mark('client', (string) $this->client->getKey(), 'client_logo', 'any');

        $this->remove('agency')->assertOk();

        $identity = $this->identity();

        $this->assertNull($identity['agency']['logo_url']);
        $this->assertSame('Agency', $identity['agency']['name'], 'the name is the fallback, and it is still there');
        $this->assertNotNull($identity['client']['logo_url'], "removing the company's mark took the client's with it");
    }

    /** And the other way round. */
    public function test_removing_the_client_mark_falls_back_to_the_client_name(): void
    {
        $this->mark('tenant', null, 'report_logo', 'any');
        $this->mark('client', (string) $this->client->getKey(), 'client_logo', 'any');

        $this->remove('client')->assertOk();

        $identity = $this->identity();

        $this->assertNull($identity['client']['logo_url']);
        $this->assertSame('Acme', $identity['client']['name']);
        $this->assertNotNull($identity['agency']['logo_url']);
    }

    /** A removal reaches exactly one slot: another client's mark is not this project's to delete. */
    public function test_removing_this_clients_mark_leaves_another_clients_alone(): void
    {
        $mine = $this->mark('client', (string) $this->client->getKey(), 'client_logo', 'any');
        $theirs = $this->mark('client', (string) $this->otherClient->getKey(), 'client_logo', 'any');

        $this->remove('client')->assertOk();

        $this->assertNull(BrandingAsset::withoutGlobalScopes()->find($mine->getKey()));
        $this->assertNotNull(
            BrandingAsset::withoutGlobalScopes()->find($theirs->getKey()),
            "another client's stored mark was deleted",
        );
    }

    /**
     * A report mark is NOT the product's mark — the isolation the owner named explicitly.
     *
     * «A report logo must not unintentionally become the logo of the entire CampaignsHub product.»
     * The product layer carries `scope = platform` and belongs to no tenant; nothing a report screen
     * does may write it or delete it.
     */
    public function test_a_report_mark_never_becomes_the_product_mark(): void
    {
        $platform = BrandingAsset::withoutGlobalScopes()->create([
            'tenant_id' => null, 'scope' => 'platform', 'scope_id' => null, 'kind' => 'report_logo', 'theme' => 'any',
            'disk' => 'local', 'path' => 'branding/_platform/product.svg', 'original_path' => 'branding/_platform/product.svg',
            'mime' => 'image/svg+xml', 'bytes' => 10, 'checksum' => hash('sha256', 'product'),
        ]);

        $this->mark('tenant', null, 'report_logo', 'any');
        $this->remove('agency')->assertOk();

        $survivor = BrandingAsset::withoutGlobalScopes()->find($platform->getKey());

        $this->assertNotNull($survivor, 'a report removal deleted the product mark');
        $this->assertNull($survivor->tenant_id, 'the product mark is still nobody tenant’s');
        $this->assertSame('platform', $survivor->scope);
    }

    /** Unrelated branding — a favicon, an email header — is not a report mark and is not touched. */
    public function test_removal_leaves_unrelated_branding_assets_alone(): void
    {
        $favicon = $this->mark('tenant', null, 'favicon', 'any');
        $this->mark('tenant', null, 'report_logo', 'any');

        $this->remove('agency')->assertOk();

        $this->assertNotNull(BrandingAsset::withoutGlobalScopes()->find($favicon->getKey()), 'the favicon was deleted');
    }

    /** Seeing a client's reports is not permission to change that client's brand. */
    public function test_removal_requires_branding_management(): void
    {
        $reader = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $reader->givePermissionTo('projects.view', 'projects.view.all', 'reports.view');
        $viewer = User::create([
            'name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($reader);

        $this->mark('tenant', null, 'report_logo', 'any');

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->getKey()}/report-identity/logo/agency")
            ->assertForbidden();
    }

    private function mark(string $scope, ?string $scopeId, string $kind, string $theme): BrandingAsset
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $xml = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        $tmp = sys_get_temp_dir().'/'.Str::random(8).'.svg';
        file_put_contents($tmp, $xml.Str::random(4));

        return app(BrandingService::class)->storeAsset(
            $scope, $scopeId, $kind, $theme, new UploadedFile($tmp, 'm.svg', 'image/svg+xml', null, true),
        );
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/report-identity")
            ->assertOk()
            ->json('data');
    }

    private function remove(string $role): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->getKey()}/report-identity/logo/{$role}");
    }
}
