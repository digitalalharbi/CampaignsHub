<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Http\Controllers\Internal\RequestsController;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP-REQ-ONE-001 — the agency inbox reads the SAME request the client reads, and shows which client and
 * project it is for and who the next move belongs to.
 */
final class AgencyRequestsInboxColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_a_request_is_waiting_on_is_derived_from_its_status_and_stage(): void
    {
        $this->assertSame('agency', RequestsController::waitingOn('under_review', 'under_review'));
        $this->assertSame('client', RequestsController::waitingOn('waiting_client', null));
        $this->assertSame('client', RequestsController::waitingOn('quoted', 'proposal_sent'));
        $this->assertSame('client', RequestsController::waitingOn('in_progress', 'client_review'));
        $this->assertSame('hold', RequestsController::waitingOn('on_hold', null));
        $this->assertNull(RequestsController::waitingOn('completed', 'completed'));
        $this->assertNull(RequestsController::waitingOn('rejected', 'rejected'));
    }

    public function test_the_inbox_row_names_the_client_the_project_and_who_it_waits_on(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'Op', 'email' => 'op@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant, Portal::Agency);
        $user->assignRole($role);

        $workspace = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'Razzah Avenue', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'Razzah Store', 'status' => 'active']);
        ExternalRequest::create([
            'tenant_id' => $tenant->id, 'reference' => 'REQ-2026-INBOX1',
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'waiting_client')->firstOrFail()->id,
            'contact_name' => 'Client', 'contact_email' => 'c@x.test', 'contact_phone' => '+966500000009',
            'client_id' => $workspace->id, 'project_id' => $project->id, 'submitted_at' => now(),
        ]);
        ExternalRequest::create([
            'tenant_id' => $tenant->id, 'reference' => 'REQ-2026-INBOX2',
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'new')->firstOrFail()->id,
            'contact_name' => 'Walk-in', 'contact_email' => 'w@x.test', 'contact_phone' => '+966500000010',
            'submitted_at' => now()->subMinute(),
        ]);

        $rows = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/app/requests')->assertOk()->json('data'))->keyBy('reference');
        // UX-COMPACT-CHARTS-001 — the same request query yields the daily inflow without an aggregate error (sorted, eager-loaded).
        $this->assertCount(14, $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/requests')->json('meta.inflow'));

        $this->assertSame('Razzah Avenue', $rows['REQ-2026-INBOX1']['client']);
        $this->assertSame('Razzah Store', $rows['REQ-2026-INBOX1']['project']);
        $this->assertSame('client', $rows['REQ-2026-INBOX1']['waiting_on']);
        $this->assertNull($rows['REQ-2026-INBOX2']['client'], 'an unconverted request belongs to no client yet — never a guess');
        $this->assertSame('agency', $rows['REQ-2026-INBOX2']['waiting_on']);
    }
}
