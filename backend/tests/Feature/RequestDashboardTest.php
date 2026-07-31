<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

final class RequestDashboardTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesContact;

    private Tenant $tenant;

    private User $owner;   // full permissions

    private User $viewer;  // no request permissions

    protected function setUp(): void
    {
        parent::setUp();

        // Scope is request-scoped since ADR 0002; these tests assert on persisted rows,
        // not on what one tenant can see, so they read across tenants deliberately.
        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->viewer, $this->tenant, Portal::Agency);
        $this->viewer->assignRole($viewerRole);
    }

    /** Submit an external request (public) — it attaches to the first tenant (our agency). */
    private function submitExternal(): array
    {
        app(TenantContext::class)->forget();
        $res = $this->postJson('/api/v1/requests', $this->withVerifiedContact([
            'type' => 'paid_campaign_launch', 'contact_name' => 'Client Co', 'contact_email' => 'c@co.test', 'company_name' => 'Client Co',
        ]))->assertCreated();
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return ['token' => $res->json('data.tracking_token'), 'reference' => $res->json('data.reference')];
    }

    public function test_full_internal_vertical_flow_with_isolation_and_sla(): void
    {
        ['token' => $token, 'reference' => $ref] = $this->submitExternal();
        $req = ExternalRequest::where('reference', $ref)->firstOrFail();
        $this->assertEquals($this->tenant->id, $req->tenant_id); // portal request owned by the agency

        // Viewer without requests.view cannot list.
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/app/requests')->assertForbidden();

        // Owner sees the request in the dashboard.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/requests')
            ->assertOk()->assertJsonPath('data.0.reference', $ref)->assertJsonPath('meta.total', 1);

        // Assign — assignee must be in the same tenant (an outside id is rejected).
        $outsider = User::create(['name' => 'X', 'email' => 'x@ex.test', 'password' => 'secret123']);
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/requests/{$req->id}/assign", ['assignee_id' => $outsider->id])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/requests/{$req->id}/assign", ['assignee_id' => $this->owner->id])->assertOk();

        // Status: forbidden jump rejected; valid transition allowed.
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/requests/{$req->id}/status", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/requests/{$req->id}/status", ['status' => 'under_review'])->assertOk();

        // Request information → waiting_client + SLA paused + a client-visible message.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/request-information", ['message' => 'Please share your ad account access.'])->assertOk();
        $req->refresh();
        $this->assertEquals('waiting_client', $req->status->key);
        $this->assertNotNull($req->sla_paused_at);

        // Internal note is added but must NOT be visible on the public tracking link.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/internal-note", ['body' => 'TOP SECRET internal assessment'])->assertOk();
        $tracking = $this->getJson("/api/v1/requests/track/{$token}")->assertOk()->getContent();
        $this->assertStringNotContainsString('TOP SECRET internal assessment', $tracking);
        $this->assertStringContainsString('Please share your ad account access.', $tracking); // the client-facing message shows

        // Client replies → SLA resumes.
        $this->postJson("/api/v1/requests/track/{$token}/reply", ['message' => 'Here is our access.'])->assertCreated();
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/requests/{$req->id}/status", ['status' => 'under_review'])->assertOk();
        $req->refresh();
        $this->assertNull($req->sla_paused_at);
        $this->assertNotNull($req->sla_resumed_at);

        // A notification was created for the tenant.
        $this->assertTrue(AppNotification::where('tenant_id', $this->tenant->id)->where('source', 'requests')->exists());
    }

    public function test_dashboard_is_tenant_isolated(): void
    {
        // Our agency's request.
        ['reference' => $ref] = $this->submitExternal();

        // A different tenant + owner must NOT see it.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherRole = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $otherRole->givePermissionTo(...Permission::pluck('key')->all());
        $otherOwner = User::create(['name' => 'O2', 'email' => 'o2@other.test', 'password' => 'secret123']);
        $this->grantMembership($otherOwner, $other, Portal::Agency);
        $otherOwner->assignRole($otherRole);

        app(TenantContext::class)->setTenantId($other->id);
        $this->actingAs($otherOwner, 'sanctum')->getJson('/api/v1/app/requests')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_action_without_permission_is_forbidden_even_via_direct_api(): void
    {
        ['reference' => $ref] = $this->submitExternal();
        $req = ExternalRequest::where('reference', $ref)->firstOrFail();

        // Viewer (no requests.change_status) calling the endpoint directly is blocked.
        $this->actingAs($this->viewer, 'sanctum')
            ->patchJson("/api/v1/app/requests/{$req->id}/status", ['status' => 'under_review'])
            ->assertForbidden();
    }
}
