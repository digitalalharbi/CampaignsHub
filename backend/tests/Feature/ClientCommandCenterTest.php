<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->viewer = User::create(['name' => 'Viewer', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->viewer, $this->tenant, Portal::Agency);
        $this->viewer->assignRole($viewerRole);
    }

    private function makeClient(string $tenantId, string $name): ClientWorkspace
    {
        $c = ClientWorkspace::create([
            'tenant_id' => $tenantId, 'name' => $name, 'slug' => Str::slug($name.'-'.uniqid()),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'onboarding', 'service_level' => 'managed_service',
        ]);
        $project = Project::create(['tenant_id' => $tenantId, 'client_workspace_id' => $c->id, 'name' => "{$name} Project", 'status' => 'setup']);
        UnifiedCampaign::create(['tenant_id' => $tenantId, 'client_workspace_id' => $c->id, 'project_id' => $project->id, 'name' => "{$name} Campaign", 'objective' => 'sales', 'status' => 'draft']);

        return $c;
    }

    public function test_portfolio_lists_tenant_clients_with_counts(): void
    {
        $this->makeClient($this->tenant->id, 'Acme');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/clients')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Acme')
            ->assertJsonPath('data.0.projects', 1)
            ->assertJsonPath('data.0.client_status', 'onboarding');
    }

    public function test_command_center_is_scoped_to_the_single_client(): void
    {
        $a = $this->makeClient($this->tenant->id, 'Client A');
        $this->makeClient($this->tenant->id, 'Client B'); // must not appear in A's command center

        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/app/clients/{$a->id}")->assertOk();
        $res->assertJsonPath('data.name', 'Client A')
            ->assertJsonPath('data.overview.projects', 1)
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonCount(1, 'data.campaigns');
        $this->assertStringNotContainsString('Client B', $res->getContent()); // no agency-wide bleed
    }

    public function test_other_tenants_client_is_not_accessible(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $foreign = $this->makeClient($other->id, 'Foreign');
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/app/clients/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/clients')->assertJsonPath('meta.total', 0);
    }

    public function test_clients_require_permission(): void
    {
        $this->makeClient($this->tenant->id, 'Acme');
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/app/clients')->assertForbidden();
    }

    /**
     * A request row carries the WRITTEN name of its service and its stage, in both languages.
     *
     * This row sent `type->name_en` and the raw `status->key`, so an Arabic command centre listed
     * «Launch a paid campaign» beside «under_review». Both taxonomy tables have carried `name_ar`
     * next to `name_en` since the requests module was built — the payload simply never asked. The
     * assertion names both languages because sending only Arabic would trade one broken reader for
     * the other, and it names the raw key as absent because that is the actual defect.
     */
    public function test_a_request_row_names_its_service_and_stage_in_both_languages(): void
    {
        $c = $this->makeClient($this->tenant->id, 'Acme');
        $type = RequestType::create([
            'key' => 'paid_campaign_launch', 'module' => 'paid_media',
            'name_ar' => 'إطلاق حملة إعلانية مدفوعة', 'name_en' => 'Launch a paid campaign',
        ]);
        $status = RequestStatus::create([
            'key' => 'under_review', 'name_ar' => 'تحت المراجعة', 'name_en' => 'Under Review',
        ]);
        ExternalRequest::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $c->id, 'reference' => 'REQ-2026-000042',
            'type_id' => $type->id, 'status_id' => $status->id,
            'contact_name' => 'Buyer', 'contact_email' => 'buyer@acme.test',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.requests.0.service', 'إطلاق حملة إعلانية مدفوعة')
            ->assertJsonPath('data.requests.0.service_en', 'Launch a paid campaign')
            ->assertJsonPath('data.requests.0.status_label', 'تحت المراجعة')
            ->assertJsonPath('data.requests.0.status_label_en', 'Under Review');
    }
}
