<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REPORT-OBJECTIVE-005 — the acceptance claims for attribution transparency and de-duplication.
 *
 * The claims are about what the product REFUSES to say as much as what it says:
 *
 *   - the platforms' conversions are never summed into a unified order count, and the refusal is in
 *     the payload with its reason, so no surface can print one by forgetting the rule;
 *   - the store's orders ARE totalled, because an order id is a real dedup key;
 *   - a shop connected twice does not double a merchant's revenue — and the collapse is REPORTED,
 *     not applied in silence;
 *   - a window the platform never sent yields null click/view days, never a defaulted seven;
 *   - «no store connected» is null, never zero, on every store-confirmed figure.
 */
final class AttributionTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Attr', 'slug' => 'attr-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        app(ProjectContext::class)->setProjectId($this->project->id);
    }

    // ── The refusal ───────────────────────────────────────────────────────────────────────────

    /**
     * The whole point. Two platforms each reporting 40 conversions is not 80 orders, and the payload
     * must not contain a number that anybody could print as though it were.
     */
    public function test_two_platforms_conversions_are_never_summed_into_one_order_count(): void
    {
        $this->conversions('meta', 40, revenue: 20_000);
        $this->conversions('snapchat', 40, revenue: 18_000);

        $out = $this->build();

        $this->assertNull($out['platform_reported']['total_orders']);
        $this->assertNull($out['platform_reported']['total_revenue']);
        $this->assertTrue($out['platform_reported']['total_withheld']);
        $this->assertSame('no_shared_order_key_across_platforms', $out['platform_reported']['total_withheld_reason']);
        $this->assertNotSame('', $out['platform_reported']['total_withheld_ar']);

        // Each platform keeps its own claim, in full.
        $orders = collect($out['platform_reported']['platforms'])->pluck('platform_reported_orders', 'provider');
        $this->assertEquals(40.0, $orders['meta']);
        $this->assertEquals(40.0, $orders['snapchat']);

        /*
         * And the sum is not present under ANY key — checked by walking the block rather than by
         * naming the keys, because the rule has to survive a key added later. A future
         * `platforms_total`, however carefully it were labelled, would be read as an order count by
         * everybody who saw it.
         */
        $this->assertNotContains(80.0, $this->scalarsIn($out['platform_reported']));
        $this->assertNotContains(38_000.0, $this->scalarsIn($out['platform_reported']));
    }

    /**
     * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — how much of what the platforms claim is the same sale.
     *
     * Every platform counts a purchase it believes it caused, on its own window, knowing nothing of
     * the others. Add them up and one order bought after a TikTok video and a Meta retargeting ad is
     * two orders. It is the commonest way an advertising report overstates itself, and it is
     * invisible per platform: each number is honest on its own terms.
     *
     * The shop's ledger has one row per sale, so the arithmetic that IS available is a floor.
     */
    public function test_the_platforms_claim_more_sales_than_the_shop_recorded(): void
    {
        $this->conversions('meta', 40, revenue: 20_000);
        $this->conversions('snapchat', 30, revenue: 15_000);

        $store = $this->storeAccount();
        foreach (range(1, 50) as $i) {
            $this->order((string) (2000 + $i), 400.0, $store, $this->campaign('meta'));
        }

        $overlap = $this->build()['overlap'];

        $this->assertTrue($overlap['available']);
        $this->assertEqualsWithDelta(70.0, $overlap['platforms_claim'], 0.01);
        $this->assertSame(50, $overlap['store_confirms']);
        $this->assertSame(20, $overlap['at_least_duplicated']);
        $this->assertEqualsWithDelta(1.4, $overlap['claims_per_confirmed_sale'], 0.001);

        /*
         * «At least», and the note says why.
         *
         * A claim with no confirmed sale behind it is one of three things and nothing here can tell
         * them apart: two platforms claiming one order, a sale that never happened, or a real sale
         * the shop cannot see. Calling it «duplicate conversions» would assert the interesting one
         * without evidence.
         */
        $this->assertStringContainsString('floor, not a count', $overlap['note_en']);
        $this->assertStringContainsString('two platforms both claimed', $overlap['note_en']);
    }

    /**
     * Platforms claiming FEWER sales than the shop recorded is not negative overlap.
     *
     * Organic orders, a platform that under-reports, a window that does not line up — all ordinary,
     * and none of them evidence of double counting. The floor is zero.
     */
    public function test_claims_below_the_ledger_are_no_evidence_of_overlap(): void
    {
        $this->conversions('meta', 10);

        $store = $this->storeAccount();
        foreach (range(1, 30) as $i) {
            $this->order((string) (3000 + $i), 200.0, $store, $this->campaign('meta'));
        }

        $overlap = $this->build()['overlap'];

        $this->assertSame(0, $overlap['at_least_duplicated']);
        $this->assertSame(30, $overlap['store_confirms']);
    }

    /**
     * Coverage bounds everything above it.
     *
     * Only orders the shop could attribute to a campaign are comparable at all. Measured against
     * half a ledger, the gap is a claim about half a shop — and a reader who is not told that will
     * read it as a claim about the whole one.
     */
    public function test_coverage_states_how_much_of_the_ledger_could_be_attributed(): void
    {
        $this->conversions('meta', 20);

        $store = $this->storeAccount();
        foreach (range(1, 6) as $i) {
            $this->order((string) (4000 + $i), 100.0, $store, $this->campaign('meta'));
        }
        foreach (range(1, 4) as $i) {
            // No campaign: a phone order, a direct visit, a link nobody tagged.
            $this->order((string) (4100 + $i), 100.0, $store);
        }

        $overlap = $this->build()['overlap'];

        $this->assertSame(10, $overlap['store_confirms']);
        $this->assertSame(6, $overlap['attributed_orders']);
        $this->assertEqualsWithDelta(0.6, $overlap['coverage'], 0.001);
    }

    /** With no ledger there is nothing to compare against, and the block says so rather than guessing. */
    public function test_overlap_is_unavailable_without_a_store(): void
    {
        $this->conversions('meta', 40);

        $overlap = $this->build()['overlap'];

        $this->assertFalse($overlap['available']);
        $this->assertSame('no_store_connected', $overlap['reason']);
        $this->assertArrayNotHasKey('at_least_duplicated', $overlap);
    }

    /**
     * And the sum still appears nowhere in `platform_reported`.
     *
     * The overlap block exists to show that summing the platforms is wrong; it must not become the
     * place where the sum is finally published as an order count. It is labelled a CLAIM, it sits
     * beside the ledger it is compared against, and the block that states platform figures still
     * refuses to total them.
     */
    public function test_the_overlap_block_does_not_smuggle_a_platform_total_into_the_platform_section(): void
    {
        $this->conversions('meta', 40, revenue: 20_000);
        $this->conversions('snapchat', 30, revenue: 15_000);

        $out = $this->build();

        $this->assertNotContains(70.0, $this->scalarsIn($out['platform_reported']));
        $this->assertNull($out['platform_reported']['total_orders']);
        $this->assertArrayNotHasKey('orders', $out['overlap']);
    }

    /**
     * Every numeric leaf of a nested payload, as floats.
     *
     * @return list<float>
     */
    private function scalarsIn(mixed $value): array
    {
        if (is_numeric($value)) {
            return [(float) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_merge(...array_map(fn (mixed $v): array => $this->scalarsIn($v), array_values($value))) ?: [];
    }

    public function test_the_platforms_are_declared_undeduplicable_and_the_store_exactly_deduplicable(): void
    {
        $this->conversions('meta', 10);
        $this->order('1001', 500.0, $this->storeAccount());

        $out = $this->build();

        $this->assertSame('not_possible', $out['dedup']['platform_reported']['status']);
        $this->assertFalse($out['dedup']['platform_reported']['may_be_summed']);
        $this->assertSame('exact', $out['dedup']['store_confirmed']['status']);
        $this->assertTrue($out['dedup']['store_confirmed']['may_be_summed']);
        $this->assertSame('provider + shop id + order id', $out['dedup']['store_confirmed']['key']);
    }

    /** The platforms are listed in the product's order, not in order of who claimed most. */
    public function test_platforms_are_listed_in_the_products_order(): void
    {
        $this->conversions('meta', 90);
        $this->conversions('snapchat', 5);
        $this->conversions('tiktok', 40);

        $out = $this->build();

        $this->assertSame(
            ['snapchat', 'tiktok', 'meta'],
            collect($out['platform_reported']['platforms'])->pluck('provider')->all(),
        );
    }

    // ── The duplicate a shop connected twice produces ─────────────────────────────────────────

    /**
     * The defect this unit exists to close: one shop, two connections, every sale in the ledger
     * twice — and AOV stays exactly right, so nothing looks wrong.
     */
    public function test_a_shop_connected_twice_does_not_double_the_merchants_revenue(): void
    {
        $first = $this->storeAccount('salla', 'shop_9');
        $second = $this->storeAccount('salla', 'shop_9', secondConnection: true);

        $this->assertNotSame($first->getKey(), $second->getKey(), 'Two connections to one shop are two rows.');

        foreach ([$first, $second] as $account) {
            $this->order('1001', 500.0, $account);
            $this->order('1002', 300.0, $account);
        }

        $this->assertSame(4, CommerceOrder::withoutGlobalScopes()->count(), 'Both copies really are in the table.');

        $out = $this->build();

        $this->assertSame(2, $out['store_confirmed']['orders']);
        $this->assertEqualsWithDelta(800.0, $out['store_confirmed']['revenue'], 0.01);
        $this->assertSame(2, $out['store_confirmed']['duplicates_collapsed']);
    }

    /** The collapse is stated, with the shop named, because it is a setup problem worth fixing. */
    public function test_the_collapse_names_the_shop_that_is_connected_more_than_once(): void
    {
        $first = $this->storeAccount('salla', 'shop_9');
        $second = $this->storeAccount('salla', 'shop_9', secondConnection: true);
        $this->order('1001', 500.0, $first);
        $this->order('1001', 500.0, $second);

        $shops = $this->build()['store_confirmed']['shops_connected_more_than_once'];

        $this->assertCount(1, $shops);
        $this->assertSame('salla', $shops[0]['provider']);
        $this->assertSame('shop_9', $shops[0]['shop_external_id']);
        $this->assertSame(2, $shops[0]['connections']);
    }

    /**
     * The key carries the SHOP. Two different merchants both numbering an order `1001` are two
     * orders, and collapsing them would trade a double-count for an undercount.
     */
    public function test_two_different_shops_using_the_same_order_number_are_two_orders(): void
    {
        $this->order('1001', 500.0, $this->storeAccount('salla', 'shop_a'));
        $this->order('1001', 700.0, $this->storeAccount('salla', 'shop_b'));

        $out = $this->build();

        $this->assertSame(2, $out['store_confirmed']['orders']);
        $this->assertSame(0, $out['store_confirmed']['duplicates_collapsed']);
        $this->assertEqualsWithDelta(1200.0, $out['store_confirmed']['revenue'], 0.01);
    }

    /** The dedup runs in the loader, so the funnel — every surface's store figures — gets it too. */
    public function test_the_funnel_reads_the_same_deduplicated_orders(): void
    {
        $first = $this->storeAccount('salla', 'shop_9');
        $second = $this->storeAccount('salla', 'shop_9', secondConnection: true);
        $this->order('1001', 500.0, $first);
        $this->order('1001', 500.0, $second);

        $funnel = app(StoreFunnelService::class)->build(
            $this->tenant->id,
            $this->project->id,
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertSame(1, $funnel['totals']['orders']);
        $this->assertEqualsWithDelta(500.0, $funnel['totals']['revenue'], 0.01);
        $this->assertSame(1, $funnel['coverage']['duplicate_orders_collapsed']);
    }

    /**
     * An order whose store account cannot be read is KEPT — «I could not check» is not «delete it».
     *
     * The account here belongs to another tenant, which is data that should not exist and is exactly
     * why the branch is written: without the shop we cannot prove a duplicate, and losing a real sale
     * is the worse failure of the two.
     */
    public function test_an_order_whose_shop_cannot_be_read_is_kept_rather_than_dropped(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $connection = app(TokenVault::class)->open(
            tenantId: $other->id,
            provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'salla-other',
        );
        $stranger = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla',
            'account_type' => 'store',
            'external_id' => 'shop_elsewhere',
            'name' => 'Elsewhere',
            'currency' => 'SAR',
            'status' => 'active',
        ]);

        $this->order('1001', 500.0, $stranger);

        $loaded = app(ProjectOrders::class)->forWindow(
            $this->tenant->id,
            $this->project->id,
            Carbon::today()->subDays(7)->startOfDay(),
            Carbon::today()->endOfDay(),
        );

        $this->assertCount(1, $loaded['orders']);
        $this->assertSame(0, $loaded['duplicates_collapsed']);
    }

    // ── Windows: click-through and view-through ───────────────────────────────────────────────

    public function test_the_window_is_read_from_the_rows_and_split_into_click_and_view(): void
    {
        $this->conversions('meta', 12, window: '7d_click_1d_view');

        $meta = collect($this->build()['platform_reported']['platforms'])->firstWhere('provider', 'meta');

        $this->assertTrue($meta['attribution']['window_known']);
        $this->assertSame(7, $meta['attribution']['click_through_days']);
        $this->assertSame(1, $meta['attribution']['view_through_days']);
        $this->assertTrue($meta['attribution']['includes_view_through']);
    }

    /** A window the platform never sent is null on both counts — never a defaulted seven days. */
    public function test_an_unstated_window_yields_null_days_and_says_so(): void
    {
        $this->conversions('snapchat', 5, window: 'default');

        $snap = collect($this->build()['platform_reported']['platforms'])->firstWhere('provider', 'snapchat');

        $this->assertFalse($snap['attribution']['window_known']);
        $this->assertNull($snap['attribution']['click_through_days']);
        $this->assertNull($snap['attribution']['view_through_days']);
        $this->assertNull($snap['attribution']['includes_view_through']);
        $this->assertNotNull($snap['attribution']['unknown_ar']);
    }

    /** A click-only window has no view-through, and that is a fact rather than a gap. */
    public function test_a_click_only_window_reports_no_view_through(): void
    {
        $this->conversions('tiktok', 8, window: '7d_click');

        $tiktok = collect($this->build()['platform_reported']['platforms'])->firstWhere('provider', 'tiktok');

        $this->assertTrue($tiktok['attribution']['window_known']);
        $this->assertSame(7, $tiktok['attribution']['click_through_days']);
        $this->assertNull($tiktok['attribution']['view_through_days']);
        $this->assertFalse($tiktok['attribution']['includes_view_through']);
    }

    /** Two windows inside one platform's figures means those figures are not comparable to each other. */
    public function test_mixed_windows_inside_one_platform_are_declared(): void
    {
        $this->conversions('meta', 10, window: '7d_click_1d_view');
        $this->conversions('meta', 10, window: '1d_click', date: Carbon::today()->subDay());

        $meta = collect($this->build()['platform_reported']['platforms'])->firstWhere('provider', 'meta');

        $this->assertTrue($meta['attribution']['mixed_windows']);
        $this->assertCount(2, $meta['attribution']['windows']);
    }

    // ── Store-Confirmed against Platform-Reported ─────────────────────────────────────────────

    /**
     * The useful comparison: one platform's claim beside the orders the SHOP recorded for it. Neither
     * figure is adjusted to match the other — we do not know which is wrong.
     */
    public function test_a_platforms_claim_sits_beside_the_orders_the_store_confirmed_for_it(): void
    {
        $account = $this->storeAccount();
        $campaign = $this->campaign('meta');

        $this->conversions('meta', 10, revenue: 5000);
        $this->order('1001', 400.0, $account, $campaign);
        $this->order('1002', 600.0, $account, $campaign);

        $meta = collect($this->build()['platform_reported']['platforms'])->firstWhere('provider', 'meta');

        $this->assertEquals(10.0, $meta['platform_reported_orders']);
        $this->assertSame(2, $meta['store_confirmed_orders']);
        $this->assertEqualsWithDelta(1000.0, $meta['store_confirmed_revenue'], 0.01);
        $this->assertEqualsWithDelta(8.0, $meta['difference'], 0.01);
        $this->assertEqualsWithDelta(5.0, $meta['ratio'], 0.001);
    }

    /** With no store connected there is nothing to compare against, and null says exactly that. */
    public function test_with_no_store_connected_the_confirmed_figures_are_null_not_zero(): void
    {
        $this->conversions('meta', 10);

        $out = $this->build();
        $meta = collect($out['platform_reported']['platforms'])->firstWhere('provider', 'meta');

        $this->assertNull($meta['store_confirmed_orders']);
        $this->assertNull($meta['store_confirmed_revenue']);
        $this->assertNull($meta['difference']);

        $this->assertFalse($out['store_confirmed']['available']);
        $this->assertSame('no_store_connected', $out['store_confirmed']['unavailable_reason']);
        $this->assertNull($out['store_confirmed']['orders']);
        $this->assertSame('unavailable', $out['dedup']['store_confirmed']['status']);
    }

    /** A cancelled order is not a sale, on either side of the comparison. */
    public function test_a_cancelled_order_is_not_counted_as_confirmed(): void
    {
        $account = $this->storeAccount();
        $this->order('1001', 500.0, $account);
        $this->order('1002', 500.0, $account)->forceFill(['cancelled_at' => Carbon::now()])->save();

        $out = $this->build();

        $this->assertSame(1, $out['store_confirmed']['orders']);
        $this->assertSame(1, $out['store_confirmed']['cancelled_orders']);
    }

    /** A refund is money the merchant no longer has, and the confirmed revenue says so. */
    public function test_confirmed_revenue_is_net_of_refunds(): void
    {
        $account = $this->storeAccount();
        $this->order('1001', 500.0, $account)->forceFill(['refunded_total' => 200.0])->save();

        $this->assertEqualsWithDelta(300.0, $this->build()['store_confirmed']['revenue'], 0.01);
    }

    // ── Unattributed ──────────────────────────────────────────────────────────────────────────

    /** Orders the resolver could not place stay in the store total and reach no campaign. */
    public function test_unattributed_orders_are_reported_and_distributed_to_nobody(): void
    {
        $account = $this->storeAccount();
        $campaign = $this->campaign('meta');

        $this->order('1001', 400.0, $account, $campaign);
        $this->order('1002', 600.0, $account);

        $out = $this->build();

        $this->assertSame(2, $out['store_confirmed']['orders']);
        $this->assertSame(1, $out['store_confirmed']['attributed_orders']);
        $this->assertSame(1, $out['unattributed']['orders']);
        $this->assertEqualsWithDelta(600.0, $out['unattributed']['revenue'], 0.01);
        $this->assertEqualsWithDelta(0.5, $out['unattributed']['share'], 0.001);
    }

    /**
     * The breakdown answers «why was this one not placed», so it may only contain unplaced orders.
     *
     * Found live: grouping every order put `utm_campaign_id` at the top of a block headed «طلبات بلا
     * إسناد», which is a contradiction — those orders WERE placed. The count above it was correct
     * throughout, and only the breakdown beneath it lied.
     */
    public function test_the_unattributed_breakdown_contains_only_orders_that_were_not_placed(): void
    {
        $account = $this->storeAccount();
        $campaign = $this->campaign('meta');

        $this->order('1001', 400.0, $account, $campaign);
        $this->order('1002', 400.0, $account, $campaign);
        $this->order('1003', 600.0, $account);

        $methods = collect($this->build()['unattributed']['by_method']);

        $this->assertSame(['none'], $methods->pluck('method')->all());
        $this->assertSame(1, $methods->firstWhere('method', 'none')['orders']);
    }

    // ── The model, which is governance and not measurement ────────────────────────────────────

    /** A campaign with no attribution model set says `unset` rather than being given a default. */
    public function test_a_campaign_with_no_attribution_model_is_reported_unset(): void
    {
        $campaign = $this->unifiedCampaign(model: null);
        $this->conversions('meta', 5, unifiedCampaign: $campaign);

        $models = $this->build()['models'];

        $this->assertCount(1, $models);
        $this->assertSame('unset', $models[0]['model']);
        $this->assertFalse($models[0]['is_set']);
    }

    public function test_the_attribution_model_a_person_set_is_reported_as_theirs(): void
    {
        $campaign = $this->unifiedCampaign(model: 'last_click');
        $this->conversions('meta', 5, unifiedCampaign: $campaign);

        $models = $this->build()['models'];

        $this->assertSame('last_click', $models[0]['model']);
        $this->assertTrue($models[0]['is_set']);
        $this->assertSame(1, $models[0]['campaigns']);
    }

    // ── The summed conversions figure, and the sentence that has to travel with it ────────────

    /**
     * The dashboard's «conversions» is `SUM(conversions)` across platforms, which the contract permits
     * only if nobody reads it as a unique order count. So the basis travels in the payload — not added
     * by each page, because the first page to forget is the one that prints a bare number.
     */
    public function test_a_summed_conversions_figure_declares_that_it_may_double_count(): void
    {
        $this->conversions('meta', 40);
        $this->conversions('snapchat', 40);

        $basis = app(MetricsAggregator::class)->conversionsBasis(
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertTrue($basis['may_double_count']);
        $this->assertFalse($basis['is_unique_order_count']);
        $this->assertSame('platform_reported', $basis['source']);
        $this->assertSame(['snapchat', 'meta'], $basis['providers']);
    }

    /** One platform cannot overlap with itself, and the basis says that rather than warning about nothing. */
    public function test_a_single_platform_figure_is_not_flagged_as_double_counting(): void
    {
        $this->conversions('meta', 40);

        $basis = app(MetricsAggregator::class)->conversionsBasis(
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertFalse($basis['may_double_count']);
        $this->assertSame(['meta'], $basis['providers']);
    }

    /** A platform that reported no conversions is not named as a contributor to a figure it did not make. */
    public function test_a_platform_with_no_conversions_is_not_named_in_the_basis(): void
    {
        $this->conversions('meta', 40);
        $this->conversions('google', 0);

        $basis = app(MetricsAggregator::class)->conversionsBasis(
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertSame(['meta'], $basis['providers']);
        $this->assertFalse($basis['may_double_count']);
    }

    /** The summary endpoint carries it, so the dashboard cannot render the number without the sentence. */
    public function test_the_summary_endpoint_carries_the_conversions_basis(): void
    {
        $this->conversions('meta', 40);
        $this->conversions('snapchat', 40);

        $this->actingAs($this->reader(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/summary")
            ->assertOk()
            ->assertJsonPath('data.conversions_basis.may_double_count', true)
            ->assertJsonPath('data.conversions_basis.is_unique_order_count', false);
    }

    // ── The endpoint ──────────────────────────────────────────────────────────────────────────

    public function test_the_endpoint_answers_and_refuses_the_unified_total(): void
    {
        $this->conversions('meta', 40);
        $this->conversions('snapchat', 40);

        $response = $this->actingAs($this->reader(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/attribution");

        $response->assertOk();
        $response->assertJsonPath('data.platform_reported.total_orders', null);
        $response->assertJsonPath('data.platform_reported.total_withheld', true);
        $response->assertJsonPath('data.dedup.platform_reported.may_be_summed', false);
    }

    public function test_the_endpoint_refuses_a_reader_without_campaigns_view(): void
    {
        $this->actingAs($this->reader(withCampaignsView: false), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/attribution")
            ->assertForbidden();
    }

    /** The platform filter narrows this page like every other, server-side. */
    public function test_the_platform_filter_narrows_the_claims(): void
    {
        $this->conversions('meta', 40);
        $this->conversions('snapchat', 40);

        $response = $this->actingAs($this->reader(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/attribution?provider=meta");

        $response->assertOk();
        $this->assertSame(
            ['meta'],
            collect($response->json('data.platform_reported.platforms'))->pluck('provider')->all(),
        );
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    private function reader(bool $withCampaignsView = true): User
    {
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reader',
            'slug' => 'reader-'.uniqid(),
        ]);
        // `projects.view` + `projects.view.all` only get the reader THROUGH the project middleware.
        // Whether they may read these figures is `campaigns.view`, which is what this varies.
        $role->givePermissionTo('projects.view', 'projects.view.all');

        if ($withCampaignsView) {
            $role->givePermissionTo('campaigns.view');
        }

        $user = User::create([
            'name' => 'R', 'email' => 'r-'.uniqid().'@attr.local',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function build(): array
    {
        return app(AttributionTransparency::class)->build(
            $this->tenant->id,
            $this->project->id,
            Carbon::today()->subDays(7),
            Carbon::today(),
        );
    }

    /**
     * A store account behind its own connection.
     *
     * `$secondConnection` is how one shop legitimately ends up connected twice: the agency connects
     * it tenant-wide, and later somebody connects the same shop inside the client's workspace. Both
     * rows are valid, both satisfy every unique index, and both sync the same orders — which is the
     * defect {@see ProjectOrders} exists to absorb.
     */
    private function storeAccount(
        string $provider = 'salla',
        string $shopId = 'shop_1',
        bool $secondConnection = false,
    ): ExternalAccount {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider.'-'.uniqid(),
            clientWorkspaceId: $secondConnection ? $this->workspace->id : null,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'store',
            'external_id' => $shopId,
            'name' => ucfirst($provider).' store',
            'currency' => 'SAR',
            'status' => 'active',
        ]);
    }

    private function order(
        string $externalId,
        float $total,
        ?ExternalAccount $account = null,
        ?ExternalCampaign $campaign = null,
    ): CommerceOrder {
        $account ??= $this->storeAccount();

        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'provider' => $account->provider,
            'external_id' => $externalId,
            'status' => 'completed',
            'placed_at' => Carbon::today()->subDay(),
            'currency' => 'SAR',
            'total' => $total,
            'refunded_total' => 0,
            'external_campaign_id' => $campaign?->getKey(),
            'attribution_method' => $campaign !== null ? 'utm_campaign_id' : 'none',
        ]);
    }

    /** @var array<string,ExternalCampaign> */
    private array $campaigns = [];

    private function campaign(string $provider): ExternalCampaign
    {
        if (isset($this->campaigns[$provider])) {
            return $this->campaigns[$provider];
        }

        return $this->campaigns[$provider] = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount($provider)->getKey(),
            'provider' => $provider,
            'external_id' => 'c-'.uniqid(),
            'name' => 'Campaign',
            'status' => 'active',
        ]);
    }

    /** @var array<string,ExternalAccount> */
    private array $adAccounts = [];

    private function adAccount(string $provider): ExternalAccount
    {
        if (isset($this->adAccounts[$provider])) {
            return $this->adAccounts[$provider];
        }

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider.'-'.uniqid(),
        );

        return $this->adAccounts[$provider] = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => 'acct-'.uniqid(),
            'name' => 'Ad account',
            'currency' => 'SAR',
            'status' => 'active',
        ]);
    }

    private function unifiedCampaign(?string $model): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Unified',
            'status' => 'active',
            'attribution_model' => $model,
        ]);
    }

    private function conversions(
        string $provider,
        float $count,
        float $revenue = 0.0,
        string $window = 'default',
        ?Carbon $date = null,
        ?UnifiedCampaign $unifiedCampaign = null,
    ): void {
        $date ??= Carbon::today()->subDay();

        foreach ([['conversions', $count], ['revenue', $revenue]] as [$key, $value]) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'external_account_id' => $this->adAccount($provider)->getKey(),
                'external_campaign_id' => $this->campaign($provider)->getKey(),
                'provider' => $provider,
                'unified_campaign_id' => $unifiedCampaign?->getKey(),
                'metric_key' => $key,
                'metric_date' => $date->toDateString(),
                'value' => $value,
                'project_currency' => 'SAR',
                'attribution_window' => $window,
                'source_type' => 'api',
            ]);
        }
    }
}
