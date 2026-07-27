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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Client classification MANAGEMENT: editable, validated, permission-gated, audited, isolated. */
final class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $readonly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $viewRole->givePermissionTo('clients.view', 'clients.view_all');

        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->owner->assignRole($ownerRole);
        $this->readonly = User::create(['tenant_id' => $this->tenant->id, 'name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->readonly->assignRole($viewRole);
    }

    private function client(?string $tenantId = null, string $name = 'Acme'): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $tenantId ?? $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name.'-'.uniqid()),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'onboarding',
        ]);
    }

    public function test_owner_can_update_classification_and_it_persists_and_audits(): void
    {
        $c = $this->client();

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/classification", [
            'client_status' => 'active',
            'service_level' => 'managed_service',
            'industry' => 'e_commerce',
            'priority' => 'high',
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'language' => 'ar',
        ])->assertOk()->assertJsonPath('data.classification.client_status', 'active')
            ->assertJsonPath('data.classification.industry', 'e_commerce');

        $this->assertDatabaseHas('client_workspaces', ['id' => $c->id, 'client_status' => 'active', 'industry' => 'e_commerce', 'priority' => 'high']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.classification_updated', 'entity_type' => 'client_workspace', 'entity_id' => $c->id]);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $c = $this->client();
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/classification", [
            'client_status' => 'awareness', // an objective, not a status
        ])->assertStatus(422);
    }

    public function test_awareness_is_not_a_valid_industry(): void
    {
        $c = $this->client();
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/classification", [
            'industry' => 'awareness',
        ])->assertStatus(422);
    }

    public function test_owner_must_belong_to_same_tenant(): void
    {
        $c = $this->client();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $foreignUser = User::create(['tenant_id' => $other->id, 'name' => 'X', 'email' => 'x@other.test', 'password' => 'secret123']);

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/classification", [
            'owner_id' => $foreignUser->id,
        ])->assertStatus(422);
    }

    public function test_update_requires_permission(): void
    {
        $c = $this->client();
        $this->actingAs($this->readonly, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/classification", [
            'client_status' => 'active',
        ])->assertForbidden();
    }

    public function test_settings_persist(): void
    {
        $c = $this->client();
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$c->id}/settings", [
            'name' => 'Acme Corp',
            'settings' => ['week_start' => 'monday', 'report_prefs' => ['default_format' => 'pdf']],
        ])->assertOk()->assertJsonPath('data.name', 'Acme Corp')
            ->assertJsonPath('data.settings.week_start', 'monday');

        $this->assertDatabaseHas('client_workspaces', ['id' => $c->id, 'name' => 'Acme Corp']);
    }

    public function test_archive_pauses_without_deleting_then_restore(): void
    {
        $c = $this->client();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$c->id}/archive")
            ->assertOk()->assertJsonPath('data.is_archived', true);
        $this->assertDatabaseHas('client_workspaces', ['id' => $c->id, 'client_status' => 'archived']);
        $this->assertNotNull($c->fresh()->archived_at);
        // Not soft-deleted — the row and its relations remain.
        $this->assertNull($c->fresh()->deleted_at);

        // Hidden from the default portfolio, visible with include_archived.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/clients')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/app/clients?include_archived=1')->assertJsonPath('meta.total', 1);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$c->id}/restore")
            ->assertOk()->assertJsonPath('data.is_archived', false);
        $this->assertNull($c->fresh()->archived_at);
    }

    public function test_cross_tenant_client_is_not_manageable(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $foreign = $this->client($other->id, 'Foreign');
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/app/clients/{$foreign->id}/classification", [
            'client_status' => 'active',
        ])->assertNotFound();
    }
}
