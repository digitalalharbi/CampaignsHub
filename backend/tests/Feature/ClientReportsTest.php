<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Client Reports tab: client isolation, create (queued), internal-not-shareable, permission. */
final class ClientReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $limited;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // report generation is queued — don't run the engine in this test
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $limitedRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Ltd', 'slug' => 'ltd']);
        $limitedRole->givePermissionTo('clients.view', 'clients.view_all');

        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->limited = User::create(['name' => 'L', 'email' => 'l@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->limited, $this->tenant, Portal::Agency);
        $this->limited->assignRole($limitedRole);
    }

    private function clientWithProject(string $name): array
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name.'-'.uniqid()),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $p = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => "$name proj", 'status' => 'active']);

        return [$c, $p];
    }

    public function test_reports_are_isolated_to_the_client(): void
    {
        [$a, $pa] = $this->clientWithProject('A');
        [$b, $pb] = $this->clientWithProject('B');
        Report::create(['tenant_id' => $this->tenant->id, 'project_id' => $pa->id, 'name' => 'A Report', 'type' => 'executive', 'audience' => 'client', 'status' => 'completed']);
        Report::create(['tenant_id' => $this->tenant->id, 'project_id' => $pb->id, 'name' => 'B Report', 'type' => 'executive', 'audience' => 'client', 'status' => 'completed']);

        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/app/clients/{$a->id}/reports")->assertOk();
        $res->assertJsonCount(1, 'data.reports')->assertJsonPath('data.reports.0.name', 'A Report');
        $this->assertStringNotContainsString('B Report', $res->getContent());
    }

    public function test_owner_can_create_a_client_report_which_is_queued(): void
    {
        [$a, $pa] = $this->clientWithProject('A');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$a->id}/reports", [
            'project_id' => $pa->id, 'name' => 'Monthly', 'type' => 'monthly', 'audience' => 'client',
        ])->assertCreated()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.project_id', $pa->id);

        $this->assertDatabaseHas('reports', ['project_id' => $pa->id, 'name' => 'Monthly', 'audience' => 'client']);
    }

    public function test_cannot_create_report_for_a_project_of_another_client(): void
    {
        [$a] = $this->clientWithProject('A');
        [, $pb] = $this->clientWithProject('B');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$a->id}/reports", [
            'project_id' => $pb->id, 'name' => 'X', 'type' => 'monthly',
        ])->assertStatus(422); // pb is not in client A's projects
    }

    public function test_internal_report_cannot_be_shared_externally(): void
    {
        [$a, $pa] = $this->clientWithProject('A');
        $internal = Report::create(['tenant_id' => $this->tenant->id, 'project_id' => $pa->id, 'name' => 'Internal', 'type' => 'executive', 'audience' => 'internal', 'status' => 'completed']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/app/clients/{$a->id}/reports/{$internal->id}/share", [])
            ->assertStatus(422);
    }

    public function test_client_report_can_be_shared_and_link_is_returned_once(): void
    {
        [$a, $pa] = $this->clientWithProject('A');
        $client = Report::create(['tenant_id' => $this->tenant->id, 'project_id' => $pa->id, 'name' => 'Client', 'type' => 'executive', 'audience' => 'client', 'status' => 'completed']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/app/clients/{$a->id}/reports/{$client->id}/share", ['allow_download' => true])
            ->assertCreated()->assertJsonPath('data.revoked', null)
            ->assertJsonStructure(['data' => ['id', 'url', 'token']]);
    }

    public function test_reports_require_permission(): void
    {
        [$a] = $this->clientWithProject('A');
        $this->actingAs($this->limited, 'sanctum')->getJson("/api/v1/app/clients/{$a->id}/reports")->assertForbidden();
    }
}
