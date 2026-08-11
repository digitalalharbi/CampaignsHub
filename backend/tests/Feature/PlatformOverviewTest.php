<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The platform console reports the platform, honestly (ADMIN-100).
 *
 * Three claims worth holding in place, each of which has a plausible wrong version:
 *
 * - The growth series **includes empty months**. A series that omits them draws a straight line
 *   through the gap, turning a quiet quarter into apparent steady growth.
 * - Committed value is priced from the SUBSCRIPTION, not from the plan it names. Re-deriving it
 *   would silently re-price every existing customer the next time the owner edits a plan.
 * - The console still shows **no customer work**. Adding charts is exactly the change that would
 *   quietly start leaking a tenant's campaigns into the owner's dashboard.
 */
final class PlatformOverviewTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();

        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
        $this->assertingAcrossTenants();
    }

    private function owner(): User
    {
        return User::query()->where('is_platform_admin', true)->firstOrFail();
    }

    private function overview(): array
    {
        return $this->actingAs($this->owner())
            ->getJson('/api/v1/admin/overview', $this->spaHeaders)
            ->assertOk()->json('data');
    }

    public function test_the_growth_series_covers_twelve_months_with_no_gaps(): void
    {
        $growth = $this->overview()['growth'];

        $this->assertCount(12, $growth);

        $months = array_column($growth, 'month');
        $this->assertSame($months, array_unique($months), 'a month appears twice');

        // Consecutive, ending on the current month — no month is skipped for being empty.
        $expected = collect(range(11, 0))->map(fn (int $back) => now()->startOfMonth()->subMonths($back)->format('Y-m'))->all();
        $this->assertSame($expected, $months);
    }

    /** The running total never goes backwards; tenants are not un-created. */
    public function test_the_running_total_only_ever_rises(): void
    {
        $totals = array_column($this->overview()['growth'], 'total');

        $this->assertSame($totals, collect($totals)->sort()->values()->all());
    }

    /**
     * Committed value follows the SUBSCRIPTION's agreed amount.
     *
     * Editing a plan's price must not retroactively re-price a customer still on the old terms —
     * which is exactly what deriving the figure from `subscription_plans` would do.
     */
    public function test_committed_value_is_priced_from_the_subscription_not_the_plan(): void
    {
        /*
         * The currency is read from the subscription rather than named here — subscriptions are sold
         * in USD since PAY-AUDIT-002, and a literal «SAR» silently selected an empty bucket and
         * turned this into an assertion about nothing.
         *
         * Money is reported PER currency and never summed across them, so picking the currency the
         * seeded subscription actually carries is also the only correct way to read this figure.
         */
        $currency = (string) Subscription::query()->withoutGlobalScopes()
            ->where('status', 'active')->value('currency');
        $this->assertNotSame('', $currency, 'the demo seed has no active subscription at all');

        $before = collect($this->overview()['subscriptions']['committed_monthly'])
            ->firstWhere('currency', $currency)['monthly'] ?? 0.0;

        $this->assertGreaterThan(0, $before, 'the demo seed has no active subscription to price');

        // The owner doubles a plan's price. Existing subscriptions keep what they agreed to.
        DB::table('subscription_plans')->where('code', 'growth')->update(['price_monthly' => 99999]);

        $after = collect($this->overview()['subscriptions']['committed_monthly'])
            ->firstWhere('currency', $currency)['monthly'] ?? 0.0;

        $this->assertEquals($before, $after);
    }

    /** And it is never called revenue: the collection side does not exist yet. */
    public function test_the_payload_states_that_collection_is_not_implemented(): void
    {
        $this->assertSame('not_implemented', $this->overview()['subscriptions']['collection_status']);
    }

    /**
     * The attention list returns zeros rather than omitting them.
     *
     * A row that vanishes is indistinguishable from a row that was never computed, and "nothing is
     * past due" is information the console should be able to state.
     */
    public function test_attention_rows_are_present_even_at_zero(): void
    {
        $rows = collect($this->overview()['attention'])->keyBy('key');

        foreach (['registrations_pending', 'subscriptions_past_due', 'tenants_suspended', 'users_without_membership'] as $key) {
            $this->assertTrue($rows->has($key), "the {$key} row is missing");
            $this->assertIsInt($rows[$key]['count']);
            $this->assertNotSame('', $rows[$key]['to'], "the {$key} row leads nowhere");
        }
    }

    public function test_a_suspended_tenant_is_counted_for_attention(): void
    {
        $before = collect($this->overview()['attention'])->firstWhere('key', 'tenants_suspended')['count'];

        Tenant::query()->withoutGlobalScopes()->where('status', 'active')->first()
            ?->forceFill(['status' => 'suspended'])->saveQuietly();

        $after = collect($this->overview()['attention'])->firstWhere('key', 'tenants_suspended')['count'];

        $this->assertSame($before + 1, $after);
    }

    /**
     * Still a console about the PLATFORM.
     *
     * Owning it is not a reason to read a customer's campaigns, and adding charts is precisely the
     * change that starts leaking them. Asserted on the payload, because a field that never reaches
     * the browser cannot be rendered by accident later.
     */
    public function test_no_customer_work_appears_in_the_payload(): void
    {
        $body = json_encode($this->overview());

        foreach (['campaign', 'creative', 'impressions', 'clicks', 'roas'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $body);
        }
    }

    /** A platform with no tenants reports zeros — never a sample figure. */
    public function test_an_empty_platform_reports_zeros(): void
    {
        DB::table('subscriptions')->delete();
        Tenant::query()->withoutGlobalScopes()->get()->each(fn (Tenant $t) => $t->forceDelete());

        $d = $this->overview();

        $this->assertSame(0, $d['tenants']['total']);
        $this->assertSame([], $d['subscriptions']['committed_monthly']);
        $this->assertSame(0, array_sum(array_column($d['growth'], 'opened')));
    }

    /** …and only the platform owner may read any of it. */
    public function test_a_tenant_owner_cannot_read_the_platform_console(): void
    {
        $user = User::query()->where('email', 'agency@campaignshub.io')->firstOrFail();

        $this->actingAs($user)->getJson('/api/v1/admin/overview', $this->spaHeaders)->assertForbidden();
    }
}
