<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRICING-MIGRATION-001 — moving a launch-catalogue (USD) subscriber onto the 2026-11 SAR catalogue.
 *
 * Never silent, never mid-term, never a charge: the console previews each workspace's move (usage against the new caps, both
 * prices in their own currencies), and a move is a typed, reasoned, audited act that is SCHEDULED for the end of the period the
 * customer already paid for. Self-service cannot cross currencies — that quote would credit dollars against riyals.
 */
final class PlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $admin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = $this->workspace('Legacy Co', 'legacy-co');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@legacy.test', 'password' => 'secret1234']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        app(TenantContext::class)->forget();

        $this->subscription = $this->onLaunchPlan($this->tenant, 'growth');
        $this->projects($this->tenant, 3);

        $this->admin = User::create(['name' => 'Operator', 'email' => 'op@platform.test', 'password' => 'secret1234']);
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    private function workspace(string $name, string $slug): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => $slug, 'status' => 'active']);
    }

    private function onLaunchPlan(Tenant $tenant, string $code): Subscription
    {
        $sub = app(SubscriptionService::class)->assignPlan($tenant, SubscriptionPlan::where('code', $code)->firstOrFail(), 'active', Carbon::now()->addDays(12));
        $sub->forceFill(['current_period_start' => Carbon::now()->subDays(18)])->save();

        return $sub;
    }

    private function projects(Tenant $tenant, int $n): void
    {
        $client = (string) Str::uuid();
        DB::table('client_workspaces')->insert([
            'id' => $client, 'tenant_id' => (string) $tenant->id, 'name' => 'Client of '.$tenant->slug, 'slug' => 'client-'.$tenant->slug,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $n; $i++) {
            DB::table('projects')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => (string) $tenant->id, 'client_workspace_id' => $client, 'name' => "P{$i}",
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_self_service_cannot_change_into_another_currency(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change/quote', ['plan_code' => 'team', 'billing_interval' => 'monthly'])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change', ['plan_code' => 'team', 'billing_interval' => 'monthly'])
            ->assertStatus(422);

        $sub = $this->subscription->refresh();
        $this->assertSame('growth', $sub->plan?->code);
        $this->assertNull($sub->scheduled_plan_id);
        $this->assertSame(0, SubscriptionPayment::withoutGlobalScopes()->count(), 'no charge was opened');
    }

    public function test_applying_a_plan_takes_the_new_plans_currency_with_its_price(): void
    {
        $team = SubscriptionPlan::where('code', 'team')->firstOrFail();
        $this->subscription->forceFill([
            'scheduled_plan_id' => $team->id, 'scheduled_billing_interval' => 'monthly', 'scheduled_unit_amount' => '699.00',
            'scheduled_change_at' => Carbon::now()->subMinute(),
        ])->save();

        app(SubscriptionLifecycle::class)->applyDueScheduledChanges();

        $sub = $this->subscription->refresh();
        $this->assertSame('team', $sub->plan?->code);
        $this->assertSame('699.00', (string) $sub->unit_amount);
        $this->assertSame('SAR', $sub->currency, 'a SAR price is never left labelled USD');
    }

    public function test_the_preview_lists_launch_subscribers_with_the_smallest_new_plan_that_fits_their_usage(): void
    {
        $current = $this->workspace('Already New', 'already-new');
        app(SubscriptionService::class)->assignPlan($current, SubscriptionPlan::where('code', 'solo')->firstOrFail());

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/plan-migration')->assertOk()->json('data.workspaces'));

        $this->assertSame(['legacy-co'], $rows->pluck('tenant_slug')->all(), 'only launch-catalogue subscribers are listed');
        $row = $rows->first();
        $this->assertSame('growth', $row['from']['plan']);
        $this->assertSame(['amount' => '49.00', 'currency' => 'USD', 'interval' => 'monthly'], array_intersect_key($row['from'], array_flip(['amount', 'currency', 'interval'])));
        $this->assertSame('team', $row['suggested']['plan'], 'Solo caps projects at 1; three projects need Team');
        $this->assertSame(['amount' => '699.00', 'currency' => 'SAR'], array_intersect_key($row['suggested'], array_flip(['amount', 'currency'])));
        $projects = collect($row['suggested']['metrics'])->firstWhere('metric', 'projects');
        $this->assertSame(['metric' => 'projects', 'usage' => 3, 'current_limit' => 25, 'new_limit' => 5, 'fits' => true], $projects);
        $this->assertTrue($row['suggested']['fits']);
        $this->assertNull($row['scheduled']);
    }

    public function test_a_move_is_typed_reasoned_audited_and_scheduled_for_period_end_without_a_charge(): void
    {
        $url = "/api/v1/admin/tenants/{$this->tenant->id}/plan-migration";

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['plan_code' => 'team', 'reason' => 'Catalogue 2026-11 move'])
            ->assertStatus(422)->assertJsonValidationErrors(['confirm']);
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['plan_code' => 'team', 'reason' => 'Catalogue 2026-11 move', 'confirm' => 'wrong'])
            ->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['plan_code' => 'team', 'confirm' => 'legacy-co'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($url, ['plan_code' => 'team', 'reason' => 'Catalogue 2026-11 move', 'confirm' => 'legacy-co'])
            ->assertCreated();

        $sub = $this->subscription->refresh();
        $this->assertSame('growth', $sub->plan?->code, 'nothing changes before the paid period ends');
        $this->assertSame('49.00', (string) $sub->unit_amount);
        $this->assertSame('USD', $sub->currency);
        $this->assertSame('team', $sub->scheduledPlan?->code);
        $this->assertSame('699.00', (string) $sub->scheduled_unit_amount);
        $this->assertTrue($sub->scheduled_change_at?->equalTo($sub->current_period_end));
        $this->assertSame(0, SubscriptionPayment::withoutGlobalScopes()->count(), 'a migration never opens a charge');

        $log = AuditLog::withoutGlobalScopes()->where('action', 'platform.plan_migration.scheduled')->sole();
        $this->assertSame('Catalogue 2026-11 move', $log->reason);
        $this->assertSame((string) $this->tenant->id, (string) $log->tenant_id);
        $this->assertSame((string) $this->admin->id, (string) $log->user_id);

        $row = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/plan-migration')->json('data.workspaces'))->first();
        $this->assertSame('team', $row['scheduled']['plan']);
    }

    public function test_a_move_onto_a_plan_the_workspace_has_outgrown_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/plan-migration", ['plan_code' => 'solo', 'reason' => 'Try the small one', 'confirm' => 'legacy-co'])
            ->assertStatus(422);

        $this->assertNull($this->subscription->refresh()->scheduled_plan_id);
    }

    public function test_a_move_onto_a_withdrawn_or_contact_sales_plan_is_refused(): void
    {
        foreach (['agency', 'enterprise_2026'] as $code) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/plan-migration", ['plan_code' => $code, 'reason' => 'Not on sale', 'confirm' => 'legacy-co'])
                ->assertStatus(422);
        }
        $this->assertNull($this->subscription->refresh()->scheduled_plan_id);
    }

    public function test_a_scheduled_move_can_be_withdrawn_with_a_reason(): void
    {
        $url = "/api/v1/admin/tenants/{$this->tenant->id}/plan-migration";
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['plan_code' => 'team', 'reason' => 'Catalogue move', 'confirm' => 'legacy-co'])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->deleteJson($url, ['reason' => 'Customer asked to wait'])->assertOk();

        $this->assertNull($this->subscription->refresh()->scheduled_plan_id);
        $this->assertSame('Customer asked to wait', AuditLog::withoutGlobalScopes()->where('action', 'platform.plan_migration.withdrawn')->sole()->reason);
    }

    public function test_bulk_schedules_the_fitting_suggestions_and_reports_the_rest(): void
    {
        $big = $this->workspace('Big Co', 'big-co');
        $this->onLaunchPlan($big, 'agency');
        $this->projects($big, 25); // more than Agency 2026 allows (20): no plan on sale fits

        $ids = [(string) $this->tenant->id, (string) $big->id];

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/plan-migration/bulk', ['tenant_ids' => $ids, 'reason' => 'Catalogue move', 'confirm' => 'MOVE 1'])
            ->assertStatus(422);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/plan-migration/bulk', ['tenant_ids' => $ids, 'reason' => 'Catalogue move', 'confirm' => 'MOVE 2'])
            ->assertOk();

        $this->assertSame([(string) $this->tenant->id], $res->json('data.scheduled'));
        $this->assertSame((string) $big->id, $res->json('data.skipped.0.tenant_id'));
        $this->assertSame('team', $this->subscription->refresh()->scheduledPlan?->code);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'platform.plan_migration.scheduled')->count());
    }

    public function test_only_a_platform_admin_reaches_the_migration_console(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/admin/plan-migration')->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/plan-migration", ['plan_code' => 'team', 'reason' => 'Sneaky move', 'confirm' => 'legacy-co'])
            ->assertForbidden();
    }
}
