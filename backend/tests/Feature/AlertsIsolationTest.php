<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Alerts are fail-closed across tenants: a tenant never sees or mutates another tenant's rules/events. */
final class AlertsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenantWithOwner(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => "o@{$slug}.test", 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    public function test_a_tenant_cannot_see_or_resolve_another_tenants_alerts(): void
    {
        // Tenant A: a rule + an open event.
        [$tenantA] = $this->tenantWithOwner('alpha');
        $rule = AlertRule::create(['tenant_id' => $tenantA->id, 'type' => 'sync_failure', 'name' => 'A rule', 'active' => true]);
        $event = AlertEvent::create(['tenant_id' => $tenantA->id, 'rule_id' => $rule->id, 'type' => 'sync_failure',
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => 'open', 'severity' => 'warning', 'last_triggered_at' => Carbon::now()]);

        // Tenant B's owner.
        [, $ownerB] = $this->tenantWithOwner('bravo');

        // B cannot see A's rule in the list.
        $rules = $this->actingAs($ownerB, 'sanctum')->getJson('/api/v1/alerts/rules')->assertOk();
        $this->assertNotContains('A rule', array_column((array) $rules->json('data'), 'name'));

        // B cannot see A's event.
        $events = $this->actingAs($ownerB, 'sanctum')->getJson('/api/v1/alerts/events')->assertOk();
        $this->assertNotContains((string) $event->id, array_column((array) $events->json('data'), 'id'));

        // B cannot resolve or snooze A's event — fail-closed route-model binding → 404.
        $this->actingAs($ownerB, 'sanctum')->postJson("/api/v1/alerts/events/{$event->id}/resolve")->assertNotFound();
        $this->actingAs($ownerB, 'sanctum')->postJson("/api/v1/alerts/events/{$event->id}/snooze", ['minutes' => 60])->assertNotFound();

        // The event is untouched in A.
        app(TenantContext::class)->setTenantId($tenantA->id);
        $this->assertSame('open', $event->refresh()->status);
    }
}
