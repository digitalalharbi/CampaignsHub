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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CrmLeadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'email' => 'owner@agency.test',
            'password' => 'secret123',
        ]);
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

    public function test_lead_creation_is_validated(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/leads', ['source' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'source']);
    }

    public function test_can_show_a_lead_via_route_binding(): void
    {
        $lead = Lead::create(['name' => 'Bound', 'source' => 'manual']);

        // Force reliance on the ResolveTenant middleware (must run before route-model binding).
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
        $otherUser = User::create(['tenant_id' => $other->id, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'secret123']);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo('leads.view');
        $otherUser->assignRole($role);

        // The other tenant's user only sees their own lead.
        $this->actingAs($otherUser, 'sanctum')->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Theirs');
    }

    public function test_permission_is_required_to_create_a_lead(): void
    {
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@agency.test', 'password' => 'secret123']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $role->givePermissionTo('leads.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/leads', ['name' => 'X', 'source' => 'manual'])
            ->assertStatus(403);
    }
}
