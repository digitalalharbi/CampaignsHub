<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\InfluencerProjectLink;
use App\Domains\Analytics\Services\AnalyticsReads;
use App\Domains\Analytics\Services\AnalyticsSnapshots;
use App\Domains\Analytics\Services\UnifiedAttribution;
use App\Domains\Analytics\Support\AnalyticsReadCache;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AttributionCredit;
use App\Domains\Metrics\Touchpoints\TouchpointLinker;
use App\Domains\Metrics\Touchpoints\TouchpointRecorder;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Models\ShortLinkHop;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ANALYTICS-DATA-001 — the read APIs behind the analytics board: site (GA4), store, influencers, attribution.
 *
 * Nothing here reaches a real GA4 property, store or ad platform: rows are written directly, so the tests prove
 * shape, scope, honesty of the not-linked states, the source on every number, and the arithmetic of the four
 * attribution models — not provider behaviour.
 */
final class AnalyticsDataLayersTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private const Q = 'from=2026-10-01&to=2026-10-31';

    private Tenant $tenant;

    private Tenant $other;

    private User $owner;

    private User $stranger;

    private Project $project;

    private Project $sibling;

    private Project $foreign;

    private ExternalAccount $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->owner = $this->userIn($this->tenant, 'owner');
        $a = ClientWorkspace::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $a->id, 'name' => 'P', 'status' => 'active']);
        $this->sibling = Project::create(['client_workspace_id' => $b->id, 'name' => 'Q', 'status' => 'active']);
        $this->store = $this->storeFor($this->tenant, $this->project, 'shop-1');

        $this->other = Tenant::create(['name' => 'T2', 'slug' => 't2-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->other->id);
        $this->stranger = $this->userIn($this->other, 'stranger');
        $c = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->foreign = Project::create(['client_workspace_id' => $c->id, 'name' => 'R', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    // ── §4 Site (GA4) ──────────────────────────────────────────────────────────────────────────

    public function test_a_project_without_a_property_is_not_linked_and_says_which_step_is_missing(): void
    {
        // The tenant has a Google sign-in and a property bound to P: Q has none confirmed, and nothing is suggested.
        $this->property($this->project);
        $q = $this->read($this->sibling, 'analytics/site?'.self::Q);
        $this->assertSame('not_linked', $q['state']);
        $this->assertSame('awaiting_selection', $q['binding']['state']);
        $this->assertNull($q['binding']['suggestion']);
        $this->assertSame('needs_confirmation', $q['binding']['auto_bind']);
        $this->assertSame([], $q['kpis']);
        $this->assertSame('ga4', $q['source']);

        // A tenant that never connected Google: AWAITING_CREDENTIALS, and never an invented figure.
        $r = $this->read($this->foreign, 'analytics/site?'.self::Q, $this->stranger);
        $this->assertSame('AWAITING_CREDENTIALS', $r['binding']['status']);
        $this->assertSame([], $r['channels']);
        $this->assertNull($r['reconciliation']);
    }

    public function test_exactly_one_discovered_property_is_suggested_but_never_bound(): void
    {
        $account = $this->property($this->project);
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $account->id)->delete();

        $b = $this->read($this->project, 'analytics/binding');
        $this->assertSame('awaiting_selection', $b['state']);
        $this->assertSame('111', $b['suggestion']['property_id']);
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('provider', 'ga4')->count(), 'a suggestion is not a binding');
    }

    public function test_the_site_read_carries_every_requested_figure_and_a_source_on_each(): void
    {
        $this->siteData();
        $site = $this->read($this->project, 'analytics/site?'.self::Q);

        $this->assertSame('ready', $site['state']);
        $this->assertSame('bound', $site['binding']['state']);
        $this->assertFalse($site['blended_with_other_sources']);
        foreach (['visits', 'users', 'sessions', 'avg_duration_seconds', 'bounce_rate', 'engagement_rate'] as $k) {
            $this->assertArrayHasKey($k, $site['kpis']);
            $this->assertSame('ga4', $site['kpis'][$k]['source'], $k);
        }
        $this->assertEquals(1000, $site['kpis']['sessions']['value']);
        $this->assertSame($site['kpis']['sessions']['value'], $site['kpis']['visits']['value']);
        $this->assertEqualsWithDelta(0.4, $site['kpis']['bounce_rate']['value'], 0.0001);

        foreach (['channels', 'devices', 'countries', 'pages'] as $list) {
            $this->assertNotEmpty($site[$list], $list);
            foreach ($site[$list] as $row) {
                $this->assertSame('ga4', $row['source'], $list);
            }
        }
        $this->assertSame('Organic Search', collect($site['channels'])->sortByDesc('sessions')->first()['channel']);
        $this->assertSame(['visit', 'cart', 'checkout', 'purchase'], array_column($site['funnel'], 'stage'));
        $this->assertEquals([1000, 120, 60, 25], array_column($site['funnel'], 'count'));
        foreach ($site['funnel'] as $step) {
            $this->assertSame('ga4', $step['source']);
        }
    }

    public function test_the_reconciliation_sets_the_three_sources_side_by_side_and_never_adds_them(): void
    {
        $this->siteData();
        $this->metric('conversions', 40, 'meta');
        $this->order('r1', 100, ['click_id_provider' => 'meta', 'click_id' => 'c1', 'attribution_method' => 'click_id_platform_only']);

        $rec = $this->read($this->project, 'analytics/site?'.self::Q)['reconciliation'];
        $this->assertFalse($rec['summed']);
        $meta = collect($rec['rows'])->firstWhere('platform', 'meta');
        $this->assertSame('platform_reported', $meta['platform_reported']['source']);
        $this->assertSame('store_confirmed', $meta['store_confirmed']['source']);
        $this->assertEquals(40, $meta['platform_reported']['orders']);
        $this->assertEquals(1, $meta['store_confirmed']['orders']);
        $this->assertEquals(39, $meta['gap']['platform_minus_store_orders']);
    }

    public function test_a_reader_without_the_store_capability_gets_traffic_but_no_sales_anywhere(): void
    {
        $this->siteData();
        $member = $this->narrowMember();

        $site = $this->read($this->project, 'analytics/site?'.self::Q, $member);
        $this->assertTrue($site['sales_hidden']);
        $this->assertNull($site['reconciliation']);
        $this->assertSame(['visit'], array_column($site['funnel'], 'stage'));
        foreach (['channels', 'devices', 'countries', 'pages'] as $list) {
            foreach ($site[$list] as $row) {
                $this->assertArrayNotHasKey('purchases', $row);
                $this->assertArrayNotHasKey('revenue', $row);
            }
        }
        foreach (['store', 'influencers', 'attribution'] as $section) {
            $this->app->forgetScopedInstances();
            $this->actingAs($member, 'sanctum')->getJson($this->url($this->project, "analytics/$section?".self::Q))->assertForbidden();
        }
    }

    // ── §5 Store ───────────────────────────────────────────────────────────────────────────────

    public function test_an_unlinked_store_is_a_state_with_no_figures_and_the_app_status_is_honest(): void
    {
        $s = $this->read($this->sibling, 'analytics/store?'.self::Q);
        $this->assertSame('not_linked', $s['state']);
        $this->assertContains($s['status'], ['AWAITING_CREDENTIALS', 'AWAITING_LINK']);
        $this->assertSame([], $s['kpis']);
        $this->assertSame([], $s['coupons']);
        $this->assertSame(['salla', 'zid'], array_column($s['provider_apps'], 'provider'));
        $this->assertNull($s['products']);
    }

    public function test_the_store_read_has_orders_revenue_aov_products_coupons_carts_and_utm_ties_each_with_its_source(): void
    {
        $this->order('s1', 100, ['utm_campaign' => 'Ramadan', 'utm_source' => 'facebook', 'attribution_method' => 'utm_campaign_name', 'coupon_code' => 'SAVE10']);
        $this->order('s2', 300, ['utm_campaign' => 'Ramadan', 'coupon_code' => 'SAVE10', 'attribution_method' => 'none']);
        $this->order('s3', 50, ['attribution_method' => 'none']);
        $this->order('s4', 999, ['cancelled_at' => '2026-10-06 10:00:00', 'status' => 'cancelled', 'coupon_code' => 'GONE']);

        $s = $this->read($this->project, 'analytics/store?'.self::Q);
        $this->assertSame('ready', $s['state']);
        $this->assertFalse($s['blended_with_other_sources']);
        $this->assertEquals(3, $s['kpis']['orders']['value']);
        $this->assertEquals(450, $s['kpis']['revenue']['value']);
        $this->assertEquals(150, $s['kpis']['aov']['value']);
        foreach (['orders', 'revenue', 'aov', 'abandoned_carts'] as $k) {
            $this->assertSame('store_confirmed', $s['kpis'][$k]['source'], $k);
        }
        $save = collect($s['coupons'])->firstWhere('dimension_1', 'SAVE10');
        $this->assertSame(2, $save['orders']);
        $this->assertSame('store_confirmed', $save['source']);
        $this->assertNull(collect($s['coupons'])->firstWhere('dimension_1', 'GONE'), 'a cancelled order is not a coupon use');
        $this->assertNotEmpty($s['campaign_ties']);
        foreach ($s['campaign_ties'] as $row) {
            $this->assertSame('store_confirmed', $row['source']);
        }
        $this->assertSame('store_confirmed', $s['products']['source']);
        $this->assertSame('store_confirmed', $s['abandoned']['source']);
    }

    // ── §6 Influencers ─────────────────────────────────────────────────────────────────────────

    public function test_minting_an_influencer_link_uses_the_short_link_library_with_a_fixed_utm(): void
    {
        $res = $this->mint($this->project, ['name' => 'Sara Ali', 'destination' => 'https://shop.example.com/p', 'coupon_code' => 'sara10'])->assertCreated()->json('data');

        $this->assertSame('influencer', $res['utm']['source']);
        $this->assertSame('influencer', $res['utm']['medium']);
        $this->assertMatchesRegularExpression('/^inf_sara-ali_[a-z0-9]{6}$/', $res['utm']['campaign']);
        $this->assertSame('SARA10', $res['coupon_code']);
        $short = ShortLink::withoutGlobalScopes()->findOrFail($res['short_link_id']);
        $this->assertSame((string) $this->project->id, (string) $short->project_id, 'the link belongs to the project');
        $this->assertSame((string) $this->tenant->id, (string) $short->tenant_id);
        $this->assertStringContainsString('utm_campaign='.$res['utm']['campaign'], (string) $short->destination);
        $this->assertStringContainsString('utm_source=influencer', (string) $short->destination);
        $this->assertStringEndsWith('/l/'.$short->slug, (string) $res['url']);

        $list = $this->read($this->project, 'analytics/influencer-links');
        $this->assertCount(1, $list);
    }

    public function test_one_link_per_influencer_per_project_and_a_campaign_value_never_repeats(): void
    {
        $this->mint($this->project, ['name' => 'Sara Ali', 'destination' => 'https://shop.example.com/p'])->assertCreated();
        $this->app->forgetScopedInstances();
        $this->mint($this->project, ['name' => 'Sara Ali', 'destination' => 'https://shop.example.com/p'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->app->forgetScopedInstances();
        $this->mint($this->project, ['name' => 'Omar', 'destination' => 'https://shop.example.com/p', 'coupon_code' => 'X1'])->assertCreated();
        $this->app->forgetScopedInstances();
        $this->mint($this->project, ['name' => 'Layla', 'destination' => 'https://shop.example.com/p', 'coupon_code' => 'x1'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        // An Arabic name folds to a handle of its own and a fixed ASCII campaign value.
        $this->app->forgetScopedInstances();
        $this->mint($this->project, ['name' => 'سارة', 'destination' => 'https://shop.example.com/p'])->assertCreated();
        $this->app->forgetScopedInstances();
        // The same name in ANOTHER project is allowed, and its campaign value is its own.
        $this->mint($this->sibling, ['name' => 'Sara Ali', 'destination' => 'https://shop.example.com/p'])->assertCreated();

        $campaigns = InfluencerProjectLink::withoutGlobalScopes()->pluck('utm_campaign')->all();
        $this->assertCount(4, $campaigns);
        $this->assertSame($campaigns, array_values(array_unique($campaigns)));
        $this->assertSame(2, InfluencerProjectLink::withoutGlobalScopes()->where('project_id', $this->project->id)->where('handle_slug', '!=', 'sara-ali')->count());
    }

    public function test_a_link_refuses_an_unsafe_destination_and_another_tenants_influencer(): void
    {
        $this->mint($this->project, ['name' => 'A', 'destination' => 'http://shop.example.com'])->assertStatus(422);
        $this->app->forgetScopedInstances();
        $this->mint($this->project, ['name' => 'A', 'destination' => 'https://127.0.0.1/x'])->assertStatus(422);

        $this->holdingTenant((string) $this->other->id);
        $foreignInfluencer = Influencer::create(['name' => 'Theirs', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $this->app->forgetScopedInstances();

        $this->mint($this->project, ['name' => 'A', 'destination' => 'https://shop.example.com', 'influencer_id' => (string) $foreignInfluencer->id])->assertStatus(422)->assertJsonValidationErrors('influencer_id');
        $this->assertSame(0, InfluencerProjectLink::withoutGlobalScopes()->count());
    }

    public function test_each_influencer_gets_clicks_visits_orders_revenue_cost_and_a_return_each_with_its_source(): void
    {
        $this->property($this->project);
        $this->holdingTenant((string) $this->tenant->id);
        $influencer = Influencer::create(['name' => 'Sara', 'status' => 'active']);
        $collab = InfluencerCollaboration::create(['influencer_id' => $influencer->id, 'title' => 'Ramadan', 'status' => 'active', 'currency' => 'USD', 'agreed_fee' => 600, 'influencer_fee' => 100]);
        app(TenantContext::class)->forget();
        $this->app->forgetScopedInstances();

        $a = $this->mint($this->project, ['name' => 'Sara', 'destination' => 'https://shop.example.com/p', 'influencer_id' => (string) $influencer->id, 'collaboration_id' => (string) $collab->id, 'coupon_code' => 'SARA10'])->assertCreated()->json('data');
        $this->app->forgetScopedInstances();
        $b = $this->mint($this->project, ['name' => 'Omar', 'destination' => 'https://shop.example.com/p'])->assertCreated()->json('data');

        // Sara: two orders by her campaign, one by her coupon only, one carrying both (one order, not two).
        $this->order('i1', 100, ['utm_campaign' => $a['utm']['campaign']]);
        $this->order('i2', 200, ['utm_campaign' => $a['utm']['campaign'], 'coupon_code' => 'sara10']);
        $this->order('i3', 50, ['coupon_code' => 'SARA10']);
        $this->order('i4', 70, ['utm_campaign' => $b['utm']['campaign']]);
        $this->order('i5', 999, ['utm_campaign' => $a['utm']['campaign'], 'cancelled_at' => '2026-10-06 10:00:00', 'status' => 'cancelled']);
        // Visits from GA4 for Sara's fixed campaign only.
        $this->dimRows('campaign', [[$a['utm']['campaign'], 'influencer / influencer', ['sessions' => 80, 'purchases' => 3, 'add_to_carts' => 9]]]);
        // Clicks: the redirect's own counter and hops recorded in the window.
        $shortA = ShortLink::withoutGlobalScopes()->findOrFail($a['short_link_id']);
        $shortA->forceFill(['clicks' => 120])->save();
        foreach ([1, 2, 3] as $day) {
            ShortLinkHop::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'short_link_id' => $shortA->id, 'occurred_at' => '2026-10-0'.$day.' 12:00:00']);
        }
        ShortLinkHop::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'short_link_id' => $shortA->id, 'occurred_at' => '2026-09-20 12:00:00']);

        $out = $this->read($this->project, 'analytics/influencers?'.self::Q);
        $this->assertSame('ready', $out['state']);
        $sara = collect($out['influencers'])->firstWhere('name', 'Sara');
        $omar = collect($out['influencers'])->firstWhere('name', 'Omar');

        $this->assertSame(3, $sara['clicks']['value'], 'only the follows recorded in the window');
        $this->assertSame(120, $sara['clicks']['all_time']);
        $this->assertSame('short_link_counter', $sara['clicks']['source']);
        $this->assertEquals(80, $sara['visits']['value']);
        $this->assertSame('ga4', $sara['visits']['source']);
        $this->assertEquals(0, $omar['visits']['value'], 'GA4 was read and holds none for Omar: a true zero');
        $this->assertSame(3, $sara['orders']['value'], 'the order carrying both the campaign and the coupon is one order');
        $this->assertSame('order_evidence', $sara['orders']['source']);
        $this->assertEquals(350, $sara['revenue']['value']);
        $this->assertSame(1, $omar['orders']['value']);
        $this->assertEquals(100, $sara['cost']['value']);
        $this->assertSame('collaboration_fee', $sara['cost']['source']);
        $this->assertEquals(3.5, $sara['roas']);
        $this->assertEquals(2.5, $sara['roi']);
        $this->assertNull($omar['cost']['value'], 'no collaboration: cost is not stated, never zero');
        $this->assertNull($omar['roas']);

        $kinds = array_count_values(array_column($out['comparison'], 'kind'));
        $this->assertSame(2, $kinds['influencer']);
        foreach ($out['comparison'] as $row) {
            $this->assertArrayHasKey('source', $row);
            $this->assertArrayHasKey('source', $row['orders']);
            $this->assertArrayHasKey('source', $row['revenue']);
        }
    }

    public function test_costs_are_withheld_from_a_reader_without_the_permission_after_the_cache(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $influencer = Influencer::create(['name' => 'Sara', 'status' => 'active']);
        $collab = InfluencerCollaboration::create(['influencer_id' => $influencer->id, 'title' => 'T', 'status' => 'active', 'currency' => 'USD', 'agreed_fee' => 600, 'influencer_fee' => 100]);
        app(TenantContext::class)->forget();
        $this->app->forgetScopedInstances();
        $a = $this->mint($this->project, ['name' => 'Sara', 'destination' => 'https://shop.example.com/p', 'collaboration_id' => (string) $collab->id])->assertCreated()->json('data');
        $this->order('c1', 100, ['utm_campaign' => $a['utm']['campaign']]);

        $member = $this->narrowMember(storeAllowed: true);
        // The owner reads first, so the cache holds the unscrubbed payload; the member must still get the cut copy.
        $this->assertEquals(100, $this->read($this->project, 'analytics/influencers?'.self::Q)['influencers'][0]['cost']['value']);
        $row = $this->read($this->project, 'analytics/influencers?'.self::Q, $member)['influencers'][0];
        $this->assertNull($row['cost']['value']);
        $this->assertSame('hidden', $row['cost']['reason']);
        $this->assertNull($row['roas']);
        $this->assertNull($row['cpa']);
    }

    // ── §7 Attribution ─────────────────────────────────────────────────────────────────────────

    public function test_the_four_models_each_share_the_same_conversions_and_revenue(): void
    {
        $this->attributionFixture();
        $a = $this->read($this->project, 'analytics/attribution?'.self::Q);

        $this->assertSame('ready', $a['state']);
        $this->assertSame(['last_click', 'first_click', 'linear', 'position_based'], $a['models']);
        $this->assertSame('last_click', $a['default_model']);
        $this->assertEquals(5, $a['coverage']['orders_credited']);

        foreach ($a['models'] as $model) {
            $this->assertEqualsWithDelta(5.0, $a['totals_by_model'][$model]['orders'], 0.0001, $model);
            $this->assertEqualsWithDelta(1000.0, $a['totals_by_model'][$model]['revenue'], 0.01, $model);
            $channelOrders = array_sum(array_map(static fn (array $c): float => $c['models'][$model]['orders'], $a['channels']));
            $channelRevenue = array_sum(array_map(static fn (array $c): float => $c['models'][$model]['revenue'], $a['channels']));
            $this->assertEqualsWithDelta(5.0, $channelOrders, 0.0001, "channels add up under $model");
            $this->assertEqualsWithDelta(1000.0, $channelRevenue, 0.01, "channel revenue adds up under $model");
        }
    }

    public function test_each_model_gives_the_credit_its_rule_says(): void
    {
        $this->attributionFixture();
        $by = collect($this->read($this->project, 'analytics/attribution?'.self::Q)['channels'])->keyBy('channel');

        // m1 (400): influencer 6 days before, Meta 2 days before. m2: TikTok. m3: a Facebook UTM (Meta). m4: the influencer's coupon. m5: nothing.
        $credit = static fn (string $channel, string $model): float => $by[$channel]['models'][$model]['orders'];

        $this->assertEqualsWithDelta(2.0, $credit('meta', 'last_click'), 0.0001, 'm1 ends on Meta, and m3 is Meta by its own UTM');
        $this->assertEqualsWithDelta(1.0, $credit('influencer', 'last_click'), 0.0001, 'only the coupon order ends on the influencer');
        $this->assertEqualsWithDelta(1.0, $credit('tiktok', 'last_click'), 0.0001);

        $this->assertEqualsWithDelta(2.0, $credit('influencer', 'first_click'), 0.0001, 'm1 began with the influencer, plus the coupon order');
        $this->assertEqualsWithDelta(1.0, $credit('meta', 'first_click'), 0.0001, 'm3 only: m1 began elsewhere');

        // Two touches have no middle, so position-based keeps only the ratio of the ends: 0.5 / 0.5, as linear does.
        foreach (['linear', 'position_based'] as $model) {
            $this->assertEqualsWithDelta(1.5, $credit('influencer', $model), 0.0001, $model);
            $this->assertEqualsWithDelta(1.5, $credit('meta', $model), 0.0001, $model);
        }
        // The credit is revenue-weighted by the order it shares: m1 is 400, so a half is 200 of it.
        $this->assertEqualsWithDelta(200.0 + 150.0, $by['influencer']['models']['linear']['revenue'], 0.01);
    }

    public function test_the_unattributed_stays_direct_or_organic_and_is_never_handed_to_a_platform(): void
    {
        $this->attributionFixture();
        $by = collect($this->read($this->project, 'analytics/attribution?'.self::Q)['channels'])->keyBy('channel');

        $this->assertSame('direct_organic', $by['direct_organic']['kind']);
        $this->assertEqualsWithDelta(1.0, $by['direct_organic']['models']['linear']['orders'], 0.0001);
        $this->assertSame('order_evidence', $by['direct_organic']['source']);
    }

    public function test_every_channel_and_total_carries_its_source_and_the_claim_sits_beside_never_inside(): void
    {
        $this->attributionFixture();
        $this->metric('conversions', 9, 'meta');
        $a = $this->read($this->project, 'analytics/attribution?'.self::Q);

        foreach ($a['channels'] as $c) {
            $this->assertContains($c['source'], ['verified_touchpoints', 'order_evidence'], $c['channel']);
            $this->assertNotEmpty($c['evidence']);
        }
        foreach ($a['totals_by_model'] as $t) {
            $this->assertSame('store_confirmed', $t['source']);
        }
        $this->assertFalse($a['claim_vs_confirmed']['summed']);
        $meta = collect($a['channels'])->firstWhere('channel', 'meta');
        $this->assertEquals(9, $meta['platform_reported']['orders']);
        $this->assertSame('platform_reported', $meta['platform_reported']['source']);
        // The platform's 9 did not move meta's credited conversions.
        $this->assertEqualsWithDelta(2.0, $meta['models']['last_click']['orders'], 0.0001);
        $this->assertSame('verified_touchpoints', $a['paths']['source']);
        $this->assertSame('store_confirmed', $a['overlap']['claim_overlap']['store_orders']['source']);
        $this->assertSame('platform_reported', $a['overlap']['claim_overlap']['platform_claimed_orders']['source']);
    }

    public function test_paths_are_sankey_ready_and_overlap_is_a_ratio_of_verified_orders(): void
    {
        $this->attributionFixture();
        $a = $this->read($this->project, 'analytics/attribution?'.self::Q);

        $sankey = $a['paths']['sankey'];
        $nodes = array_column($sankey['nodes'], 'id');
        $this->assertContains('purchase', $nodes);
        foreach ($sankey['links'] as $l) {
            $this->assertContains($l['source'], $nodes);
            $this->assertContains($l['target'], $nodes);
            $this->assertGreaterThan(0, $l['orders']);
        }
        // Every order ends in the purchase node: the links into it carry all five.
        $this->assertSame(5, array_sum(array_map(static fn (array $l): int => $l['target'] === 'purchase' ? $l['orders'] : 0, $sankey['links'])));
        $path = $a['paths']['rows'][0];
        $this->assertSame(['influencer', 'meta'], $path['steps']);
        $this->assertSame(1, $path['orders']);

        // Of the 2 orders that had verified touchpoints, 1 was reached by two channels.
        $this->assertSame(2, $a['overlap']['orders_with_verified_touchpoints']);
        $this->assertSame(1, $a['overlap']['multi_channel_orders']);
        $this->assertEqualsWithDelta(0.5, $a['overlap']['overlap_ratio'], 0.0001);
        $pair = $a['overlap']['pairs'][0];
        $this->assertSame(['influencer', 'meta'], $pair['channels']);
        $this->assertSame(1, $pair['orders_both']);
    }

    public function test_the_credit_arithmetic_of_the_four_models_on_a_three_touch_path(): void
    {
        $config = AttributionCredit::config(['models' => UnifiedAttribution::MODELS]);
        $t = Carbon::parse('2026-10-10 12:00:00');
        $touches = [
            ['channel' => 'tiktok', 'at' => $t->copy()->subDays(5), 'is_paid' => true],
            ['channel' => 'influencer', 'at' => $t->copy()->subDays(3), 'is_paid' => false],
            ['channel' => 'meta', 'at' => $t->copy()->subDay(), 'is_paid' => true],
        ];
        $split = static fn (string $m): array => AttributionCredit::split($m, $touches, $t, $config);

        $this->assertSame(['meta' => 1.0], $split('last_click'));
        $this->assertSame(['tiktok' => 1.0], $split('first_click'));
        foreach ($split('linear') as $share) {
            $this->assertEqualsWithDelta(1 / 3, $share, 0.0001);
        }
        $pos = $split('position_based');
        $this->assertEqualsWithDelta(0.4, $pos['tiktok'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $pos['influencer'], 0.0001);
        $this->assertEqualsWithDelta(0.4, $pos['meta'], 0.0001);
        foreach (UnifiedAttribution::MODELS as $m) {
            $this->assertEqualsWithDelta(1.0, array_sum($split($m)), 0.0001, $m);
        }
    }

    public function test_a_store_less_project_has_the_claim_comparison_but_no_credit_and_no_invented_orders(): void
    {
        $this->metric('conversions', 7, 'meta', $this->sibling);
        $a = $this->read($this->sibling, 'analytics/attribution?'.self::Q);

        $this->assertSame('not_linked', $a['state']);
        $this->assertSame([], $a['channels']);
        $this->assertNull($a['totals_by_model']);
        $this->assertSame('meta', $a['claim_vs_confirmed']['rows'][0]['platform']);
        $this->assertEquals(7, $a['claim_vs_confirmed']['rows'][0]['platform_reported']['orders']);
        $this->assertNull($a['claim_vs_confirmed']['rows'][0]['store_confirmed']);
        $this->assertNull($a['overlap']['claim_overlap']['store_orders']['value']);
    }

    // ── §9 Isolation, cache, snapshots ────────────────────────────────────────────────────────

    public function test_nothing_crosses_a_project_or_a_tenant(): void
    {
        $this->attributionFixture();
        $this->siteData();
        $a = $this->mint($this->project, ['name' => 'Sara', 'destination' => 'https://shop.example.com/p'])->assertCreated()->json('data');

        // The sibling project of the SAME tenant sees none of P.
        $this->assertSame('not_linked', $this->read($this->sibling, 'analytics/attribution?'.self::Q)['state']);
        $this->assertSame('no_links', $this->read($this->sibling, 'analytics/influencers?'.self::Q)['state']);
        $this->assertSame([], $this->read($this->sibling, 'analytics/influencer-links'));
        $this->assertSame('not_linked', $this->read($this->sibling, 'analytics/site?'.self::Q)['state']);

        // Reading P's pre-aggregated rows through Q is impossible: rows are keyed on both ids.
        app(BuildAnalyticsSnapshotJob::class, ['tenantId' => (string) $this->tenant->id, 'projectId' => (string) $this->project->id])->handle(app(AnalyticsReads::class), app(AnalyticsSnapshots::class));
        $this->assertSame(3, AnalyticsSnapshot::withoutGlobalScopes()->where('project_id', $this->project->id)->count());
        $this->assertSame(0, AnalyticsSnapshot::withoutGlobalScopes()->where('project_id', $this->sibling->id)->count());
        $this->assertNull(app(AnalyticsSnapshots::class)->fresh((string) $this->tenant->id, (string) $this->sibling->id, 'attribution', '2026-10-01', '2026-10-31'));

        // Another tenant's reader is refused P outright, for every section.
        foreach (['site', 'store', 'influencers', 'attribution', 'influencer-links', 'binding'] as $path) {
            $this->app->forgetScopedInstances();
            $status = $this->actingAs($this->stranger, 'sanctum')->getJson($this->url($this->project, "analytics/$path?".self::Q))->status();
            $this->assertContains($status, [403, 404], $path);
        }
        // And its own project, with the same shape, holds nothing of P's.
        $this->assertSame('not_linked', $this->read($this->foreign, 'analytics/attribution?'.self::Q, $this->stranger)['state']);
        $this->assertSame([], $this->read($this->foreign, 'analytics/influencer-links', $this->stranger));
        $this->assertNotNull($a);
    }

    public function test_the_cache_key_carries_tenant_and_project_so_two_projects_never_share_an_entry(): void
    {
        $this->attributionFixture();
        $this->read($this->project, 'analytics/attribution?'.self::Q);
        $this->read($this->sibling, 'analytics/attribution?'.self::Q);

        $keys = [
            AnalyticsReadCache::key('attribution', (string) $this->tenant->id, (string) $this->project->id, '2026-10-01_2026-10-31'),
            AnalyticsReadCache::key('attribution', (string) $this->tenant->id, (string) $this->sibling->id, '2026-10-01_2026-10-31'),
        ];
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertStringContainsString(':t:'.$this->tenant->id.':p:'.$this->project->id.':', (string) $keys[0]);
        $this->assertTrue(Cache::has($keys[0]));
        $this->assertSame('ready', Cache::get($keys[0])['v']['state']);
        $this->assertSame('not_linked', Cache::get($keys[1])['v']['state']);
    }

    public function test_a_fresh_snapshot_is_served_a_stale_one_is_ignored_and_minting_a_link_forgets_it(): void
    {
        $this->attributionFixture();
        $reads = app(AnalyticsReads::class);
        $snaps = app(AnalyticsSnapshots::class);
        $tenant = (string) $this->tenant->id;
        $project = (string) $this->project->id;

        $live = $reads->compute('attribution', $tenant, $project, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $marked = ['marker' => 'from-snapshot'] + $live;
        $snaps->store($tenant, $project, 'attribution', '2026-10-01', '2026-10-31', $marked);
        $this->assertSame('from-snapshot', $this->read($this->project, 'analytics/attribution?'.self::Q)['marker']);

        // Older than the bound: ignored, and the read computes live.
        AnalyticsSnapshot::withoutGlobalScopes()->update(['computed_at' => Carbon::now()->subMinutes(AnalyticsSnapshots::MAX_AGE_MINUTES + 5)]);
        AnalyticsReadCache::bump($tenant, $project);
        $this->assertArrayNotHasKey('marker', $this->read($this->project, 'analytics/attribution?'.self::Q));

        // A new influencer link drops the stored influencer and attribution sections.
        $snaps->store($tenant, $project, 'influencers', '2026-10-01', '2026-10-31', ['state' => 'stale']);
        $this->mint($this->project, ['name' => 'Sara', 'destination' => 'https://shop.example.com/p'])->assertCreated();
        $this->assertSame(0, AnalyticsSnapshot::withoutGlobalScopes()->where('project_id', $project)->whereIn('section', ['influencers', 'attribution'])->count());
        $this->assertSame('ready', $this->read($this->project, 'analytics/influencers?'.self::Q)['state']);
    }

    public function test_the_snapshot_command_is_bounded_and_queues_one_job_per_active_project(): void
    {
        Queue::fake();
        $this->order('q1', 10);
        $this->order('q2', 10, [], null, $this->sibling);

        $this->artisan('analytics:build-snapshots', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(BuildAnalyticsSnapshotJob::class, 1);

        $this->artisan('analytics:build-snapshots', ['--limit' => 10])->assertSuccessful();
        Queue::assertPushed(BuildAnalyticsSnapshotJob::class, 3);

        // A project with no recent order is not queued.
        CommerceOrder::withoutGlobalScopes()->update(['updated_at' => now()->subDays(30)]);
        Queue::fake();
        $this->artisan('analytics:build-snapshots')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_the_attribution_read_costs_a_bounded_number_of_queries_and_a_cached_read_costs_almost_none(): void
    {
        $this->attributionFixture();
        for ($i = 0; $i < 25; $i++) {
            $this->order('bulk'.$i, 10, ['click_id_provider' => 'tiktok', 'click_id' => 'bk'.$i, 'attribution_method' => 'click_id_platform_only']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->read($this->project, 'analytics/attribution?'.self::Q);
        $cold = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->read($this->project, 'analytics/attribution?'.self::Q);
        $warm = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(120, $cold, 'the cold read is bounded and does not grow per order (31 orders here)');
        $this->assertLessThan($cold, $warm, 'a cached read is cheaper than a computed one');
        $this->assertLessThan(60, $warm);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    /**
     * Five orders, 1000 in all:
     *   m1 400  — an influencer click 6 days before and a Meta click 2 days before, both verified (the two-touch path)
     *   m2 200  — a TikTok click id, verified
     *   m3 150  — the order's own UTM names Facebook (no touchpoint)
     *   m4 150  — the influencer link's coupon (no touchpoint)
     *   m5 100  — nothing at all
     */
    private function attributionFixture(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $inf = Influencer::create(['name' => 'Sara', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $this->app->forgetScopedInstances();
        $link = $this->mint($this->project, ['name' => 'Sara', 'destination' => 'https://shop.example.com/p', 'influencer_id' => (string) $inf->id, 'coupon_code' => 'SARA10'])->assertCreated()->json('data');
        $this->app->forgetScopedInstances();

        $m1 = $this->order('m1', 400, ['click_id_provider' => 'meta', 'click_id' => 'meta-click-1', 'attribution_method' => 'click_id_platform_only', 'utm_content' => 'tok-inf', 'placed_at' => '2026-10-10 12:00:00']);
        $m2 = $this->order('m2', 200, ['click_id_provider' => 'tiktok', 'click_id' => 'tt-1', 'attribution_method' => 'click_id_platform_only', 'placed_at' => '2026-10-11 12:00:00']);
        $this->order('m3', 150, ['utm_source' => 'facebook', 'attribution_method' => 'utm_source_platform_only', 'placed_at' => '2026-10-12 12:00:00']);
        $this->order('m4', 150, ['coupon_code' => 'sara10', 'attribution_method' => 'none', 'placed_at' => '2026-10-13 12:00:00']);
        $this->order('m5', 100, ['attribution_method' => 'none', 'placed_at' => '2026-10-14 12:00:00']);

        $at = $m1->placed_at;
        $this->touch('inf-1', 'smart_link', $at->copy()->subDays(6), ['tracking_token' => 'tok-inf', 'smart_link_id' => $link['short_link_id']]);
        $this->touch('meta-1', 'meta', $at->copy()->subDays(2), ['click_id' => 'meta-click-1', 'click_id_provider' => 'meta']);
        $this->touch('tt-1', 'tiktok', $m2->placed_at->copy()->subDay(), ['click_id' => 'tt-1', 'click_id_provider' => 'tiktok']);
        $this->assertSame(2, app(TouchpointLinker::class)->linkOrder($m1));
        $this->assertSame(1, app(TouchpointLinker::class)->linkOrder($m2));
    }

    /** @param array<string, mixed> $extra */
    private function touch(string $ref, string $channel, Carbon $at, array $extra = []): void
    {
        app(TouchpointRecorder::class)->record((string) $this->tenant->id, (string) $this->project->id, array_merge([
            'source_type' => 'smart_link_click', 'source_ref' => $ref, 'channel' => $channel, 'occurred_at' => $at, 'consent_state' => 'not_required',
        ], $extra));
    }

    private function siteData(): void
    {
        $property = $this->property($this->project);
        foreach (['2026-10-05' => 500, '2026-10-06' => 500] as $day => $sessions) {
            $this->daily($property, $day, ['sessions' => $sessions, 'users' => 300, 'bounce_rate' => 0.4, 'engagement_rate' => 0.6, 'avg_session_seconds' => 90, 'engaged_sessions' => $sessions * 0.6, 'transactions' => 10, 'revenue' => 1000]);
        }
        $this->dimRows('device', [['mobile', '', ['sessions' => 700, 'engaged_sessions' => 400, 'add_to_carts' => 80, 'checkouts' => 40, 'purchases' => 15, 'revenue' => 1500]], ['desktop', '', ['sessions' => 300, 'engaged_sessions' => 200, 'add_to_carts' => 40, 'checkouts' => 20, 'purchases' => 10, 'revenue' => 1000]]]);
        $this->dimRows('country', [['Saudi Arabia', '', ['sessions' => 900, 'purchases' => 20]], ['Kuwait', '', ['sessions' => 100, 'purchases' => 5]]]);
        $this->dimRows('landing_page', [['/', '', ['sessions' => 600, 'purchases' => 10]], ['/p/1', '', ['sessions' => 400, 'purchases' => 15]]]);
        $this->dimRows('source_medium', [['google', 'organic', ['sessions' => 700, 'purchases' => 15]], ['facebook', 'paid_social', ['sessions' => 300, 'purchases' => 10]]]);
        $this->dimRows('default_channel_group', [['Organic Search', '', ['sessions' => 600, 'engaged_sessions' => 300, 'purchases' => 12, 'revenue' => 1200]], ['Paid Social', '', ['sessions' => 300, 'engaged_sessions' => 100, 'purchases' => 10, 'revenue' => 800]], ['Direct', '', ['sessions' => 100, 'engaged_sessions' => 50, 'purchases' => 3, 'revenue' => 300]]]);
    }

    private function userIn(Tenant $tenant, string $slug): User
    {
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $slug, 'slug' => $slug]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => $slug, 'email' => $slug.'-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    private function narrowMember(bool $storeAllowed = false): User
    {
        $this->holdingTenant((string) $this->tenant->id);
        $member = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($member, $this->tenant);
        $narrow = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Narrow', 'slug' => 'narrow-'.uniqid()]);
        $narrow->givePermissionTo('analytics.view', 'projects.view', 'projects.view.all');
        $member->assignRole($narrow);
        ProjectMembership::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $member->id, 'role' => 'marketing_manager', 'denied' => $storeAllowed ? [] : ['analytics.store.view'], 'status' => 'active']);
        app(TenantContext::class)->forget();

        return $member;
    }

    private function url(Project $project, string $path): string
    {
        return '/api/v1/projects/'.$project->id.'/'.$path;
    }

    /** @return array<string, mixed> */
    private function read(Project $project, string $path, ?User $as = null): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($as ?? $this->owner, 'sanctum')->getJson($this->url($project, $path))->assertOk()->json('data');
    }

    /** @param array<string, mixed> $body */
    private function mint(Project $project, array $body): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')->postJson($this->url($project, 'analytics/influencer-links'), $body);
    }

    private function storeFor(Tenant $tenant, Project $project, string $external): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $tenant->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Salla '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => $external, 'name' => 'Shop '.$external, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'salla', 'purpose' => 'store', 'is_active' => true,
        ]);

        return $account;
    }

    private function property(Project $project): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4');
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE, 'external_id' => '111', 'name' => 'Prop', 'status' => 'active', 'discovered_at' => Carbon::now(), 'currency' => 'SAR',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param array<string, float|int> $values */
    private function daily(ExternalAccount $property, string $date, array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('measurement_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $property->id, 'property_id' => (string) $property->external_id, 'metric_date' => $date,
                'metric_key' => $key, 'value' => $value, 'currency' => $key === 'revenue' ? 'SAR' : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param list<array{0: string, 1: string, 2: array<string, float|int>}> $rows */
    private function dimRows(string $breakdown, array $rows): void
    {
        $property = ExternalAccount::withoutGlobalScopes()->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->firstOrFail();
        foreach ($rows as [$d1, $d2, $metrics]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $property->id,
                'property_id' => '111', 'metric_date' => '2026-10-05', 'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2,
                'metrics' => json_encode($metrics), 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function metric(string $key, float $value, string $provider = 'meta', ?Project $project = null): void
    {
        $project ??= $this->project;
        $account = ExternalAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'provider' => $provider, 'external_id' => 'ad-'.$project->id, 'account_type' => 'ad_account'],
            ['client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $this->store->provider_connection_id, 'name' => 'Ad', 'status' => 'active', 'discovered_at' => Carbon::now()],
        );
        ProjectIntegrationBinding::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id],
            ['client_workspace_id' => $project->client_workspace_id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true],
        );
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'external_campaign_id' => (string) Str::uuid(), 'provider' => $provider,
            'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'project_currency' => 'USD',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function order(string $reference, float $total, array $over = [], ?ExternalAccount $store = null, ?Project $project = null): CommerceOrder
    {
        $project ??= $this->project;
        $store ??= $project->is($this->project) ? $this->store : $this->storeFor($this->tenant, $project, 'shop-'.$project->id);

        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $store->id,
            'provider' => 'salla', 'external_id' => 'ext-'.$reference.'-'.$project->id, 'reference' => $reference, 'status' => 'completed', 'placed_at' => '2026-10-05 10:00:00',
            'currency' => 'USD', 'total' => $total, ...$over,
        ]);
    }
}
