<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\EntitlementMode;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — plan entitlements (free now, priceable later) and per-workspace monthly usage (spec §1). */
final class EntitlementsAndUsageTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
        $this->seedPermissions();

        // A guarded write and read, the way a notifications endpoint declares its key.
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'plan.feature:notifications.external'])->group(function (): void {
            Route::get('/api/v1/__nv2/external', fn () => response()->json(['ok' => true]));
            Route::post('/api/v1/__nv2/external', fn () => response()->json(['ok' => true]));
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gate(): EntitlementGate
    {
        app()->forgetScopedInstances();

        return app(EntitlementGate::class);
    }

    /** @param array<string, mixed> $extra */
    private function feature(string $key, bool $enabled, array $extra = []): void
    {
        DB::table('notification_plan_features')->insert(array_merge([
            'id' => (string) Str::uuid(), 'key' => $key, 'plan_code' => null, 'workspace_type' => null, 'enabled' => $enabled,
            'limits' => null, 'grace_days' => 14, 'keep_existing' => true, 'paid_from' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    public function test_by_default_every_key_is_on_for_every_type_except_removing_the_footprint(): void
    {
        foreach ([$this->tenant('A', 'agency'), $this->tenant('B', 'brand'), $this->tenant('C', 'self_serve_company')] as $tenant) {
            foreach (EntitlementKey::cases() as $key) {
                $expected = $key === EntitlementKey::RemovePlatformFootprint ? EntitlementMode::Off : EntitlementMode::Full;
                $this->assertSame($expected, $this->gate()->state($tenant->id, $key)->mode, $key->value);
            }
        }
        $this->assertSame(500, $this->gate()->limit($tenant->id, 'external_recipients'));
        $this->assertSame(20000, $this->gate()->limit($tenant->id, 'monthly_messages'));
    }

    public function test_switching_a_key_off_refuses_the_api_and_switching_it_back_restores_it(): void
    {
        $tenant = $this->tenant('A');
        $owner = $this->member($tenant);
        $this->feature('notifications.external', false);

        $this->actingAs($owner)->postJson('/api/v1/__nv2/external')->assertForbidden()
            ->assertJsonPath('meta.upgrade', true)->assertJsonPath('errors.entitlement.0', 'notifications.external');
        $this->actingAs($owner)->getJson('/api/v1/__nv2/external')->assertForbidden();

        DB::table('notification_plan_features')->update(['enabled' => true]);
        app()->forgetScopedInstances();
        $this->actingAs($owner)->postJson('/api/v1/__nv2/external')->assertOk();
    }

    public function test_a_key_that_becomes_paid_grandfathers_existing_workspaces_and_is_off_for_new_ones(): void
    {
        $old = $this->tenant('Old');
        DB::table('tenants')->where('id', $old->id)->update(['created_at' => '2026-10-01 00:00:00']);
        $new = $this->tenant('New');
        $this->feature('notifications.external.charts', false, ['paid_from' => '2026-11-10 00:00:00', 'keep_existing' => true]);

        $this->assertSame(EntitlementMode::Grandfathered, $this->gate()->state($old->id, EntitlementKey::ExternalCharts)->mode);
        $this->assertTrue($this->gate()->allows($old->id, EntitlementKey::ExternalCharts));
        $this->assertSame(EntitlementMode::Off, $this->gate()->state($new->id, EntitlementKey::ExternalCharts)->mode);
    }

    public function test_without_keep_existing_a_workspace_gets_a_grace_period_then_view_only_with_reads_kept(): void
    {
        $tenant = $this->tenant('A');
        DB::table('tenants')->where('id', $tenant->id)->update(['created_at' => '2026-10-01 00:00:00']);
        $owner = $this->member($tenant);
        $this->feature('notifications.external', false, ['paid_from' => '2026-11-10 00:00:00', 'keep_existing' => false, 'grace_days' => 14]);

        $grace = $this->gate()->state($tenant->id, EntitlementKey::External);
        $this->assertSame(EntitlementMode::Grace, $grace->mode);
        $this->assertSame('2026-11-24', $grace->graceEndsAt?->toDateString());
        $this->assertTrue($grace->upgradeRequired());
        $this->actingAs($owner)->postJson('/api/v1/__nv2/external')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00:00', 'UTC'));
        $after = $this->gate()->state($tenant->id, EntitlementKey::External);
        $this->assertSame(EntitlementMode::ViewOnly, $after->mode);
        $this->assertFalse($after->canEdit());
        app()->forgetScopedInstances();
        $this->actingAs($owner)->getJson('/api/v1/__nv2/external')->assertOk();      // view-only: settings still readable
        $this->actingAs($owner)->postJson('/api/v1/__nv2/external')->assertForbidden(); // …but not editable
    }

    public function test_an_explicit_workspace_state_wins_and_the_most_specific_plan_row_applies(): void
    {
        $agency = $this->tenant('A', 'agency');
        $brand = $this->tenant('B', 'brand');
        $plan = SubscriptionPlan::create(['code' => 'growth-nv2', 'name' => 'Growth', 'price_monthly' => 100, 'currency' => 'SAR', 'is_active' => true]);
        app(SubscriptionService::class)->assignPlan($agency, $plan);
        $starter = SubscriptionPlan::create(['code' => 'starter-nv2', 'name' => 'Starter', 'price_monthly' => 10, 'currency' => 'SAR', 'is_active' => true]);
        app(SubscriptionService::class)->assignPlan($brand, $starter);

        $this->feature('notifications.push', false, ['workspace_type' => 'business']);
        $this->feature('notifications.push', true, ['plan_code' => 'growth-nv2', 'limits' => json_encode(['devices_per_user' => 3])]);

        $this->assertSame(EntitlementMode::Off, $this->gate()->state($brand->id, EntitlementKey::Push)->mode);
        $this->assertSame(EntitlementMode::Full, $this->gate()->state($agency->id, EntitlementKey::Push)->mode);
        $this->assertSame(3, $this->gate()->limit($agency->id, 'devices_per_user'));

        DB::table('tenant_limit_overrides')->insert(['tenant_id' => $agency->id, 'metric' => 'notif.devices_per_user', 'limit_value' => 7, 'reason' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(7, $this->gate()->limit($agency->id, 'devices_per_user'));

        DB::table('workspace_entitlement_states')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $brand->id, 'key' => 'notifications.push', 'mode' => 'grandfathered', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(EntitlementMode::Grandfathered, $this->gate()->state($brand->id, EntitlementKey::Push)->mode);
    }

    public function test_usage_counts_per_workspace_per_month_atomically_and_never_cross_workspaces(): void
    {
        $a = $this->tenant('A');
        $b = $this->tenant('B');
        $usage = app(UsageCounter::class);

        $usage->increment($a->id, UsageCounter::EXTERNAL_MESSAGES, 3);
        $usage->increment($a->id, UsageCounter::EXTERNAL_MESSAGES);
        $usage->increment($b->id, UsageCounter::PUSH_NOTIFICATIONS, 2);
        // 22:30 UTC on the 30th is already 1 December in Riyadh: the next month's row.
        $usage->increment($a->id, UsageCounter::EXTERNAL_MESSAGES, 5, Carbon::parse('2026-11-30 22:30:00', 'UTC'));

        $this->assertSame(4, $usage->current($a->id, UsageCounter::EXTERNAL_MESSAGES));
        $this->assertSame(0, $usage->current($b->id, UsageCounter::EXTERNAL_MESSAGES));
        $this->assertSame(2, $usage->month($b->id)[UsageCounter::PUSH_NOTIFICATIONS]);
        $this->assertSame(5, $usage->current($a->id, UsageCounter::EXTERNAL_MESSAGES, Carbon::parse('2026-12-02 09:00:00', 'UTC')));
    }

    public function test_the_workspace_usage_endpoint_shows_only_its_own_counts_and_limits(): void
    {
        $a = $this->tenant('A');
        $ownerA = $this->member($a);
        $b = $this->tenant('B');
        $this->member($b);
        app(UsageCounter::class)->increment($b->id, UsageCounter::EXTERNAL_MESSAGES, 9);
        app(UsageCounter::class)->increment($a->id, UsageCounter::EXTERNAL_MESSAGES, 2);
        app(TenantContext::class)->forget();

        $response = $this->actingAs($ownerA)->getJson('/api/v1/settings/workspace/notifications/usage')->assertOk()
            ->assertJsonPath('data.limits.monthly_messages', 20000)
            ->assertJsonPath('data.period', '2026-11');
        $this->assertSame(2, $response->json('data.counts')['notif.external_messages']);
        $this->actingAs($ownerA)->getJson('/api/v1/settings/workspace/notifications/entitlements')->assertOk()
            ->assertJsonCount(11, 'data.entitlements')
            ->assertJsonPath('data.workspace_type.key', 'agency');
        $this->assertInstanceOf(Tenant::class, $b);
    }
}
