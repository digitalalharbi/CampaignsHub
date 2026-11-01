<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CrmLeadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Scope is request-scoped since ADR 0002; these tests assert on persisted rows,
        // not on what one tenant can see, so they read across tenants deliberately.
        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@agency.test',
            'password' => 'secret123',
        ]);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    public function test_can_create_and_list_leads(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/leads', [
            'name' => 'Big Client',
            'source' => 'website',
            'estimated_value' => 9000,
        ])->assertCreated()->assertJsonPath('data.name', 'Big Client');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Big Client');
    }

    /** UX-COMPACT-CHARTS-001 — leads that arrived per day, real zeros included; another workspace's never counted. */
    public function test_the_list_reports_leads_received_per_day_over_its_own_rows(): void
    {
        $at = fn (int $daysAgo) => ['created_at' => Carbon::now()->utc()->subDays($daysAgo)];
        foreach ([0, 0, 2, 20] as $i => $ago) {
            Lead::create(['name' => "L{$i}", 'source' => 'manual'])->forceFill($at($ago))->save();
        }

        $inflow = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/leads')->assertOk()->json('meta.inflow'));

        $this->assertCount(14, $inflow, 'every day of the window is present');
        $this->assertSame(2, $inflow->last()['count'], 'today');
        $this->assertSame(1, $inflow[11]['count'], 'two days ago');
        $this->assertSame(0, $inflow[12]['count'], 'a day nobody arrived is a real zero');
        $this->assertSame(3, $inflow->sum('count'), 'the lead 20 days ago is outside the window');
    }

    public function test_lead_creation_is_validated(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/leads', ['source' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'source']);
    }

    public function test_can_show_a_lead_via_route_binding(): void
    {
        $lead = Lead::create(['name' => 'Bound', 'source' => 'manual']);

        // Force reliance on the ResolveMembership middleware (must run before route-model binding).
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Bound');
    }

    public function test_converting_a_lead_creates_company_and_opportunity(): void
    {
        $lead = Lead::create(['name' => 'Convert Me', 'source' => 'referral', 'estimated_value' => 5000]);

        // Clear context so the request must resolve the tenant via middleware before binding.
        app(TenantContext::class)->forget();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertCreated()
            ->assertJsonPath('data.amount', 5000);

        $opportunityId = $response->json('data.id');
        $this->assertDatabaseHas('opportunities', ['id' => $opportunityId, 'lead_id' => $lead->id]);
        $this->assertDatabaseHas('companies', ['name' => 'Convert Me']);
        $this->assertTrue(Lead::find($lead->id)->isConverted());
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.converted']);
    }

    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $lead = Lead::create(['name' => 'Once', 'source' => 'manual', 'estimated_value' => 100]);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/leads/{$lead->id}/convert")->assertCreated();

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertStatus(500); // RuntimeException → generic error envelope

        $this->assertSame(1, Opportunity::where('lead_id', $lead->id)->count());
    }

    public function test_leads_are_isolated_between_tenants(): void
    {
        // A lead in our tenant.
        Lead::create(['name' => 'Ours', 'source' => 'manual']);

        // Another tenant with its own lead + user.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        Lead::create(['name' => 'Theirs', 'source' => 'manual']);
        $otherUser = User::create(['name' => 'O', 'email' => 'o@other.test', 'password' => 'secret123']);
        $this->grantMembership($otherUser, $other);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        /*
         * `leads.pii.view` as well as `leads.view` — this test is about TENANTS, and it reads a name.
         * Since LEAD-OPERATIONS-001 the two are separate permissions, so an owner who holds only the
         * first sees their own tenant's lead with its identity withheld, and the isolation assertion
         * below would pass for the wrong reason.
         */
        $role->givePermissionTo('leads.view', 'leads.pii.view');
        $otherUser->assignRole($role);

        // The other tenant's user only sees their own lead.
        $this->actingAs($otherUser, 'sanctum')->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Theirs');
    }

    public function test_permission_is_required_to_create_a_lead(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v@agency.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $role->givePermissionTo('leads.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/leads', ['name' => 'X', 'source' => 'manual'])
            ->assertStatus(403);
    }
}
