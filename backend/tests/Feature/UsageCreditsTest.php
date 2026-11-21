<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Subscriptions\Usage\CreditTransaction;
use App\Domains\Subscriptions\Usage\UsageEvent;
use App\Domains\Subscriptions\Usage\UsageLedger;
use App\Domains\Subscriptions\Usage\UsageMeter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PRICING-USAGE-CREDITS-001 — metered usage and credits.
 *
 * Usage on the customer's own AI key is recorded and alerts against the cap they set on the key, and never takes credits.
 * Platform-funded usage costs what the operator entered (captured per row), takes credits only when charging is switched on,
 * never takes the balance below zero, and is recorded exactly once per idempotency key. 75/90/100% alerts are sent once.
 */
final class UsageCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    private User $owner;

    private User $admin;

    private UsageLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Metered Co', 'slug' => 'metered-co', 'status' => 'active']);
        $this->other = Tenant::create(['name' => 'Other Co', 'slug' => 'other-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@metered.test', 'password' => 'secret1234']);
        $this->grantMembership($this->owner, $this->tenant, role: 'owner');
        $this->owner->assignRole($role);
        app(TenantContext::class)->forget();
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'team')->firstOrFail(), 'active', Carbon::now()->addDays(20));

        $this->admin = User::create(['name' => 'Operator', 'email' => 'op@platform.test', 'password' => 'secret1234']);
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $this->ledger = app(UsageLedger::class);
    }

    private function priceSms(string $cost, bool $charges = true): void
    {
        UsageMeter::query()->where('code', 'sms_verifications')->update(['unit_cost' => $cost, 'charges_credits' => $charges]);
    }

    private function sms(string $key, ?Tenant $tenant = null): ?UsageEvent
    {
        return $this->ledger->record((string) ($tenant ?? $this->tenant)->id, 'sms_verifications', 1, $key);
    }

    public function test_own_key_usage_is_recorded_and_never_takes_credits(): void
    {
        $this->ledger->adjust((string) $this->tenant->id, '50', 'Goodwill credit', $this->admin->id, 'k-grant');
        // Even with a cost and charging switched on in the table, the customer's own key never charges.
        UsageMeter::query()->where('code', 'ai_tokens_own_key')->update(['unit_cost' => '0.01', 'charges_credits' => true]);

        $event = $this->ledger->record((string) $this->tenant->id, 'ai_tokens_own_key', 1200, 'agent_message:1', scope: 'key-1', cap: 100000);

        $this->assertSame(1200, $event?->quantity);
        $this->assertSame('customer', $event?->funded_by);
        $this->assertSame(0.0, (float) $event?->cost);
        $this->assertSame(0.0, (float) $event?->credits_charged);
        $this->assertSame('50.00', $this->ledger->balance((string) $this->tenant->id));
        $this->assertSame(1, CreditTransaction::query()->count(), 'only the grant');
    }

    public function test_the_own_key_meter_cannot_be_set_to_charge_credits_from_the_console(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/usage/meters/ai_tokens_own_key', ['unit_cost' => '0.01', 'charges_credits' => true, 'reason' => 'Try to charge'])
            ->assertStatus(422);
        $this->assertFalse((bool) UsageMeter::query()->where('code', 'ai_tokens_own_key')->value('charges_credits'));
    }

    public function test_charging_needs_the_actual_cost_first(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/usage/meters/sms_verifications', ['charges_credits' => true, 'reason' => 'Switch on charging'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/usage/meters/sms_verifications', ['unit_cost' => '0.25', 'charges_credits' => true, 'included_per_plan' => ['team' => 100], 'reason' => 'Twilio KSA rate'])
            ->assertOk()->assertJsonPath('data.meter.charges', true)->assertJsonPath('data.meter.included_per_plan.team', 100);
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.usage_meter.updated')->where('reason', 'Twilio KSA rate')->count());
    }

    public function test_unpriced_platform_usage_is_recorded_and_charges_nothing(): void
    {
        $event = $this->sms('cv:1');

        $this->assertSame('platform', $event?->funded_by);
        $this->assertNull($event?->unit_cost);
        $this->assertSame(0.0, (float) $event?->cost);
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_priced_usage_takes_its_actual_cost_once_per_key(): void
    {
        $this->ledger->adjust((string) $this->tenant->id, '1', 'Starter credit', $this->admin->id, 'k-grant');
        $this->priceSms('0.25');

        $first = $this->sms('cv:1');
        $again = $this->sms('cv:1');

        $this->assertSame($first?->id, $again?->id, 'the same act is the same row');
        $this->assertSame(1, UsageEvent::query()->count());
        $this->assertSame('0.75', $this->ledger->balance((string) $this->tenant->id));
        $this->assertSame(1, CreditTransaction::query()->where('kind', 'consume')->count());

        // Re-pricing never re-prices what already happened.
        $this->priceSms('9');
        $this->assertSame(0.25, (float) $first?->refresh()->cost);
    }

    public function test_usage_beyond_the_balance_is_recorded_as_uncovered_and_the_balance_stays_at_zero(): void
    {
        $this->ledger->adjust((string) $this->tenant->id, '0.30', 'Tiny credit', $this->admin->id, 'k-grant');
        $this->priceSms('0.25');

        $this->sms('cv:1');
        $second = $this->sms('cv:2');

        $this->assertSame('0.00', $this->ledger->balance((string) $this->tenant->id));
        $this->assertSame(0.05, round((float) $second?->credits_charged, 2));
        $this->assertSame(0.20, round((float) $second?->uncovered, 2));
        $this->assertNotNull($this->sms('cv:3'), 'nothing is blocked when the balance is empty');
    }

    public function test_alerts_are_sent_once_at_75_90_and_100_percent(): void
    {
        UsageMeter::query()->where('code', 'sms_verifications')->update(['included_per_plan' => ['team' => 20]]);

        for ($i = 1; $i <= 25; $i++) {
            $this->sms("cv:{$i}");
        }
        $this->sms('cv:1'); // a retry alerts nothing

        $sent = SubscriptionNotification::query()->withoutGlobalScopes()->where('event', 'usage_threshold')->pluck('dedup_key');
        $this->assertCount(3, $sent);
        $this->assertSame([75, 90, 100], DB::table('usage_alerts')->orderBy('threshold')->pluck('threshold')->map(fn ($t) => (int) $t)->all());
    }

    public function test_own_key_alerts_follow_the_cap_on_that_key(): void
    {
        $this->ledger->record((string) $this->tenant->id, 'ai_tokens_own_key', 800, 'm:1', scope: 'key-1', cap: 1000);
        $this->ledger->record((string) $this->tenant->id, 'ai_tokens_own_key', 800, 'm:2', scope: 'key-2', cap: 1000);

        $alerts = DB::table('usage_alerts')->orderBy('scope')->get();
        $this->assertSame(['key-1', 'key-2'], $alerts->pluck('scope')->all(), 'each key is measured against its own cap');
        $body = (string) SubscriptionNotification::query()->withoutGlobalScopes()->where('event', 'usage_threshold')->value('body');
        $this->assertStringContainsString('مفتاحك الخاص', $body);
    }

    public function test_a_hand_adjustment_is_idempotent_audited_and_never_goes_below_zero(): void
    {
        $url = "/api/v1/admin/tenants/{$this->tenant->id}/credits";
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['amount' => 20, 'reason' => 'Launch goodwill', 'idempotency_key' => 'grant-0001'])->assertCreated();
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['amount' => 20, 'reason' => 'Launch goodwill', 'idempotency_key' => 'grant-0001'])->assertOk();
        $this->assertSame('20.00', $this->ledger->balance((string) $this->tenant->id));

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['amount' => -25, 'reason' => 'Take back too much', 'idempotency_key' => 'take-0001'])->assertStatus(422);
        $this->assertSame('20.00', $this->ledger->balance((string) $this->tenant->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.credits.adjusted')->count());

        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.balance', '20.00')->assertJsonCount(1, 'data.transactions');
    }

    public function test_the_workspace_sees_its_own_usage_keys_and_balance_only(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $key = new AIProviderCredential(['tenant_id' => $this->tenant->id, 'provider' => 'anthropic', 'credential_scope' => 'tenant', 'status' => 'active', 'monthly_token_limit' => 5000]);
        $key->setSecret('sk-ant-test-1234');
        $key->save();
        app(TenantContext::class)->forget();

        $this->ledger->record((string) $this->tenant->id, 'ai_tokens_own_key', 300, 'm:1', scope: (string) $key->id, cap: 5000);
        $this->sms('cv:1');
        $this->sms('cv:other', $this->other);

        $data = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/subscriptions/usage')->assertOk()->json('data');

        $meters = collect($data['meters'])->keyBy('code');
        $this->assertSame(1, $meters['sms_verifications']['used'], "another workspace's usage is not counted");
        $this->assertSame(300, $meters['ai_tokens_own_key']['used']);
        $this->assertSame('customer', $meters['ai_tokens_own_key']['funded_by']);
        $this->assertSame(300, $data['own_keys'][0]['used']);
        $this->assertSame(5000, $data['own_keys'][0]['monthly_token_limit']);
        $this->assertSame('••••1234', $data['own_keys'][0]['key']);
        $this->assertSame('0.00', $data['credits']['balance']);
        $this->assertSame(['active' => 0, 'cap' => 20], array_intersect_key($data['seats'], ['active' => 0, 'cap' => 0]), 'Team: 20 ad-account seats');
    }

    public function test_a_code_the_platform_pays_twilio_to_send_for_a_workspace_is_metered_once(): void
    {
        config(['services.twilio.account_sid' => 'AC_test', 'services.twilio.auth_token' => 'secret-token',
            'services.twilio.verify_service_sid' => 'VA_test', 'services.twilio.verify_channel' => 'sms']);
        Http::fake(['*' => Http::response(['status' => 'pending'], 201)]);
        $service = app(ContactVerificationService::class);

        $service->start('sms', '+966501234567', 'contact_verify', (string) $this->tenant->id);
        $service->start('sms', '+966501234568', 'contact_verify'); // no workspace: nobody to attribute it to

        $events = UsageEvent::query()->get();
        $this->assertCount(1, $events);
        $this->assertSame((string) $this->tenant->id, $events->first()->tenant_id);
        $this->assertSame('sms_verifications', $events->first()->meter_code);
        $this->assertSame('platform', $events->first()->funded_by);
    }

    public function test_only_a_platform_admin_reaches_meters_and_credits(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/admin/usage/meters')->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/credits", ['amount' => 100, 'reason' => 'Free money', 'idempotency_key' => 'self-0001'])
            ->assertForbidden();
        $this->assertSame('0.00', $this->ledger->balance((string) $this->tenant->id));
    }
}
