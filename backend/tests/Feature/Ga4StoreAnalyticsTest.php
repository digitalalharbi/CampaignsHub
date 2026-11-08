<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4Breakdowns;
use App\Domains\Integrations\Measurement\Ga4Metrics;
use App\Domains\Integrations\Measurement\Ga4PlatformGap;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GA4-STORE-ANALYTICS-001, GA4-PLATFORM-GAP-001.
 *
 * Provider answers are FIXTURES in the shape of the GA4 Data API `runReport` response
 * (`metricHeaders`, `rows[].dimensionValues`, `rows[].metricValues`): no live property exists, so
 * nothing here proves a live round trip. It proves the requests carry Google's documented names and
 * that every figure the store reading states is the arithmetic the rules promise.
 */
final class Ga4StoreAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_the_new_requests_use_documented_data_api_names(): void
    {
        foreach (['averageSessionDuration', 'addToCarts', 'checkouts', 'bounceRate', 'sessions', 'totalUsers', 'newUsers', 'engagementRate', 'transactions', 'purchaseRevenue'] as $metric) {
            $this->assertArrayHasKey($metric, Ga4Metrics::MAP);
        }
        $this->assertTrue(Ga4Metrics::isRate('avg_session_seconds'), 'an average is weighted, never summed across days');
        $this->assertSame(['itemName'], Ga4Breakdowns::DIMENSIONS['product']);
        $this->assertSame(['itemsViewed', 'itemsAddedToCart', 'itemsPurchased', 'itemRevenue'], array_keys(Ga4Breakdowns::metricsFor('product')));

        $property = $this->property($this->project);
        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['displayName' => 'Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR']),
            '*analyticsdata.googleapis.com*' => function (Request $r) {
                if (array_column($r['dimensions'], 'name') === ['date', 'itemName']) {
                    // The runReport response shape: headers name the metrics, rows carry strings.
                    return Http::response([
                        'dimensionHeaders' => [['name' => 'date'], ['name' => 'itemName']],
                        'metricHeaders' => [['name' => 'itemRevenue', 'type' => 'TYPE_CURRENCY'], ['name' => 'itemsPurchased', 'type' => 'TYPE_INTEGER'], ['name' => 'itemsViewed', 'type' => 'TYPE_INTEGER'], ['name' => 'itemsAddedToCart', 'type' => 'TYPE_INTEGER']],
                        'rows' => [['dimensionValues' => [['value' => Carbon::now('Asia/Riyadh')->format('Ymd')], ['value' => 'عباية سوداء']], 'metricValues' => [['value' => '750.5'], ['value' => '3'], ['value' => '40'], ['value' => '9']]]],
                        'rowCount' => 1,
                    ]);
                }

                return Http::response(['metricHeaders' => [['name' => 'sessions']], 'rows' => []]);
            },
        ]);

        app(Ga4PropertySync::class)->sync($property, 1);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), ':runReport')
            && array_column($r['dimensions'], 'name') === ['date', 'itemName']
            && array_column($r['metrics'], 'name') === ['itemsViewed', 'itemsAddedToCart', 'itemsPurchased', 'itemRevenue']);
        $asked = [];
        Http::assertSent(function (Request $r) use (&$asked) {
            if (str_contains($r->url(), ':runReport') && array_column($r['dimensions'], 'name') === ['date']) {
                $asked = [...$asked, ...array_column($r['metrics'], 'name')];
                $this->assertLessThanOrEqual(10, count($r['metrics']), 'a runReport takes at most ten metrics');
            }

            return true;
        });
        foreach (['averageSessionDuration', 'addToCarts', 'checkouts'] as $metric) {
            $this->assertContains($metric, $asked);
        }

        $stored = DB::table('measurement_dimension_rows')->where('breakdown', 'product')->first();
        $this->assertSame('عباية سوداء', $stored->dimension_1);
        $this->assertSame('SAR', $stored->currency, 'item revenue is money and carries the property currency');
        $this->assertEquals(['item_revenue' => 750.5, 'items_purchased' => 3, 'items_viewed' => 40, 'items_added_to_cart' => 9], json_decode((string) $stored->metrics, true), 'values are read by header name, not by position');
    }

    public function test_every_tile_states_its_figure_its_previous_period_and_its_change(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        // previous 2026-09-21..09-30, current 2026-10-01..10-10
        $this->daily($property, '2026-09-25', ['sessions' => 200, 'users' => 150, 'new_users' => 90, 'bounce_rate' => 0.5, 'engagement_rate' => 0.5, 'avg_session_seconds' => 60, 'transactions' => 4, 'revenue' => 400, 'add_to_carts' => 20, 'checkouts' => 8]);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'users' => 80, 'new_users' => 50, 'bounce_rate' => 0.6, 'engagement_rate' => 0.4, 'avg_session_seconds' => 30, 'transactions' => 2, 'revenue' => 300, 'add_to_carts' => 12, 'checkouts' => 5]);
        $this->daily($property, '2026-10-03', ['sessions' => 300, 'users' => 220, 'new_users' => 150, 'bounce_rate' => 0.2, 'engagement_rate' => 0.8, 'avg_session_seconds' => 90, 'transactions' => 8, 'revenue' => 1700, 'add_to_carts' => 28, 'checkouts' => 15]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $this->assertSame('ready', $out['state']);
        $this->assertTrue($out['not_summed']);
        $this->assertSame(['from' => '2026-09-21', 'to' => '2026-09-30'], $out['previous_period']);
        $k = collect($out['kpis'])->keyBy('key');
        $this->assertSame(['sessions', 'users_daily_sum', 'new_users', 'bounce_rate', 'engagement_rate', 'avg_session_seconds', 'transactions', 'revenue', 'aov', 'conversion_rate', 'add_to_carts', 'checkouts'], $k->keys()->all());

        $this->assertEquals(400.0, $k['sessions']['current']);
        $this->assertEquals(200.0, $k['sessions']['previous']);
        $this->assertEquals(1.0, $k['sessions']['change_pct']);
        $this->assertEquals(300.0, $k['users_daily_sum']['current']);
        $this->assertEquals(200.0, $k['new_users']['current']);
        $this->assertEquals(0.3, $k['bounce_rate']['current'], '(0.6×100 + 0.2×300) ÷ 400: weighted by sessions');
        $this->assertEquals(0.7, $k['engagement_rate']['current']);
        $this->assertEquals(75.0, $k['avg_session_seconds']['current'], '(30×100 + 90×300) ÷ 400');
        $this->assertEquals(10.0, $k['transactions']['current']);
        $this->assertEquals(2000.0, $k['revenue']['current']);
        $this->assertSame('SAR', $k['revenue']['currency'], 'the original currency is kept');
        $this->assertEquals(500.0, $k['revenue']['current_usd'], '2000 SAR at 0.25');
        $this->assertEquals(100.0, $k['revenue']['previous_usd']);
        $this->assertEquals(200.0, $k['aov']['current'], 'revenue ÷ purchases');
        $this->assertEquals(50.0, $k['aov']['current_usd']);
        $this->assertEquals(0.025, $k['conversion_rate']['current'], '10 purchases ÷ 400 sessions');
        $this->assertEquals(40.0, $k['add_to_carts']['current']);
        $this->assertEquals(20.0, $k['checkouts']['current']);

        $this->assertSame(['2026-10-02', '2026-10-03'], array_column($out['series'], 'date'), 'a day with no measurement is not drawn');
        $this->assertEquals(75.0, $out['series'][0]['revenue_usd']);
        $this->assertSame(['available' => true, 'from' => 'SAR', 'rate' => 0.25, 'rate_date' => '2026-09-01', 'source' => 'manual'], $out['usd']);
    }

    public function test_aov_is_withheld_with_no_purchases_and_absent_is_never_zero(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'transactions' => 0, 'revenue' => 0]);

        $k = collect($this->store('from=2026-10-01&to=2026-10-10')['kpis'])->keyBy('key');

        $this->assertNull($k['aov']['current'], 'an average over no purchases is withheld');
        $this->assertNull($k['aov']['current_usd']);
        $this->assertEquals(0.0, $k['conversion_rate']['current'], 'no purchase in 100 sessions is a measured zero');
        $this->assertNull($k['bounce_rate']['current'], 'never measured is absent');
        $this->assertNull($k['add_to_carts']['current']);
        $this->assertNull($k['sessions']['previous']);
        $this->assertNull($k['sessions']['change_pct'], 'no baseline, no change');
    }

    public function test_revenue_in_usd_is_withheld_when_a_day_has_no_rate_rather_than_guessed(): void
    {
        $property = $this->property($this->project);
        // The only stored rate is dated AFTER the first day with revenue.
        $this->rate('SAR', 'USD', '2026-10-03', 0.25);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'transactions' => 2, 'revenue' => 300]);
        $this->daily($property, '2026-10-03', ['sessions' => 100, 'transactions' => 2, 'revenue' => 500]);
        $this->dimRows($property, $this->project, 'source_medium', '2026-10-02', [['google', 'organic', ['sessions' => 100, 'purchases' => 2, 'revenue' => 300]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');
        $k = collect($out['kpis'])->keyBy('key');

        $this->assertEquals(800.0, $k['revenue']['current'], 'the property-currency figure is still stated');
        $this->assertNull($k['revenue']['current_usd']);
        $this->assertNull($k['aov']['current_usd']);
        $this->assertFalse($out['usd']['available']);
        $this->assertSame('no_rate', $out['usd']['reason']);
        $this->assertNull($out['series'][0]['revenue_usd']);
        $this->assertEquals(125.0, $out['series'][1]['revenue_usd']);
        $this->assertNull($out['tables']['sources']['rows'][0]['revenue_usd']);
    }

    public function test_a_property_with_no_currency_states_no_usd(): void
    {
        $property = $this->property($this->project, currency: null);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'transactions' => 2, 'revenue' => 300]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $this->assertNull($out['currency']);
        $this->assertSame(['available' => false, 'reason' => 'no_currency'], $out['usd']);
        $this->assertNull(collect($out['kpis'])->firstWhere('key', 'revenue')['current_usd']);
    }

    public function test_channels_devices_funnel_and_tables_are_summed_within_their_breakdown(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->daily($property, '2026-10-05', ['sessions' => 1000]);
        $this->dimRows($property, $this->project, 'default_channel_group', '2026-10-05', [
            ['Paid Social', '', ['sessions' => 600, 'purchases' => 12, 'revenue' => 2400]],
            ['Organic Search', '', ['sessions' => 300, 'purchases' => 6, 'revenue' => 900]],
            ['Direct', '', ['sessions' => 100, 'purchases' => 2, 'revenue' => 200]],
        ]);
        $this->dimRows($property, $this->project, 'device', '2026-10-05', [
            ['mobile', '', ['sessions' => 800, 'add_to_carts' => 120, 'checkouts' => 50, 'purchases' => 16, 'revenue' => 2800]],
            ['desktop', '', ['sessions' => 200, 'add_to_carts' => 30, 'checkouts' => 10, 'purchases' => 4, 'revenue' => 700]],
        ]);
        $this->dimRows($property, $this->project, 'landing_page', '2026-10-05', [['/', '', ['sessions' => 700, 'engaged_sessions' => 350, 'purchases' => 7, 'revenue' => 700]], ['/abaya', '', ['sessions' => 300, 'engaged_sessions' => 240, 'purchases' => 13, 'revenue' => 2800]]]);
        $this->dimRows($property, $this->project, 'country', '2026-10-05', [['Saudi Arabia', '', ['sessions' => 900, 'purchases' => 19, 'revenue' => 3300]], ['Kuwait', '', ['sessions' => 100, 'purchases' => 1, 'revenue' => 200]]]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-05', [['eid_sale', 'snapchat / cpc', ['sessions' => 400, 'purchases' => 8, 'revenue' => 1600]]]);
        $this->dimRows($property, $this->project, 'product', '2026-10-05', [['عباية سوداء', '', ['items_viewed' => 400, 'items_added_to_cart' => 90, 'items_purchased' => 14, 'item_revenue' => 2800]], ['طرحة', '', ['items_viewed' => 100, 'items_purchased' => 6, 'item_revenue' => 700]]]);
        $this->dimRows($property, $this->project, 'product', '2026-10-06', [['طرحة', '', ['items_viewed' => 50, 'items_purchased' => 4, 'item_revenue' => 300]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $this->assertSame(['Paid Social', 'Organic Search', 'Direct'], array_column($out['channels'], 'key'));
        $this->assertEquals([600, 300, 100], array_column($out['channels'], 'sessions'));
        $this->assertEquals([0.6, 0.3, 0.1], array_column($out['channels'], 'share'));
        $this->assertEquals(1.0, round(array_sum(array_column($out['channels'], 'share')), 3));
        $this->assertEquals(600.0, $out['channels'][0]['revenue_usd']);
        $this->assertSame(['mobile', 'desktop'], array_column($out['devices'], 'key'));
        $this->assertEquals([0.8, 0.2], array_column($out['devices'], 'share'));

        $this->assertSame(['sessions', 'add_to_carts', 'checkouts', 'purchases'], array_column($out['funnel'], 'stage'));
        $this->assertEquals([1000, 150, 60, 20], array_column($out['funnel'], 'count'));
        $this->assertEquals([null, 0.15, 0.4, 0.3333], array_column($out['funnel'], 'step_rate'));

        $landing = $out['tables']['landing_pages'];
        $this->assertSame(2, $landing['total']);
        $this->assertEquals(['name' => '/', 'sub' => null, 'sessions' => 700.0, 'engagement_rate' => 0.5, 'purchases' => 7.0, 'conversion_rate' => 0.01, 'revenue' => 700.0, 'revenue_usd' => 175.0], $landing['rows'][0]);
        $this->assertSame('Saudi Arabia', $out['tables']['countries']['rows'][0]['name']);
        $this->assertSame(['name' => 'eid_sale', 'sub' => 'snapchat / cpc'], array_intersect_key($out['tables']['campaigns']['rows'][0], ['name' => 1, 'sub' => 1]));
        $this->assertEquals([
            ['name' => 'عباية سوداء', 'views' => 400.0, 'carts' => 90.0, 'purchased' => 14.0, 'revenue' => 2800.0, 'revenue_usd' => 700.0],
            ['name' => 'طرحة', 'views' => 150.0, 'carts' => null, 'purchased' => 10.0, 'revenue' => 1000.0, 'revenue_usd' => 250.0],
        ], $out['tables']['products']['rows'], 'a product is summed across days; a count never reported stays absent');
        $this->assertNull($out['tables']['sources'], 'a breakdown the property never returned is absent, not an empty table');
    }

    public function test_the_gap_sets_each_platform_beside_ga4_and_never_adds_them(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->daily($property, '2026-10-05', ['sessions' => 1000, 'transactions' => 30, 'revenue' => 6000]);
        $this->dimRows($property, $this->project, 'source_medium', '2026-10-05', [
            ['facebook', 'paid_social', ['sessions' => 300, 'purchases' => 6, 'revenue' => 1200]],
            ['instagram', 'cpc', ['sessions' => 100, 'purchases' => 2, 'revenue' => 400]],
            ['snapchat', 'cpc', ['sessions' => 200, 'purchases' => 12, 'revenue' => 2400]],
            ['newsletter', 'cpm', ['sessions' => 20, 'purchases' => 0, 'revenue' => 0]],
            ['google', 'organic', ['sessions' => 250, 'purchases' => 7, 'revenue' => 1400]],
            ['(direct)', '(none)', ['sessions' => 100, 'purchases' => 3, 'revenue' => 600]],
            ['t.co', 'referral', ['sessions' => 20, 'purchases' => 0, 'revenue' => 0]],
            ['mail', 'email', ['sessions' => 10, 'purchases' => 0, 'revenue' => 0]],
        ]);
        $this->adMetric('meta', 'conversions', 20);
        $this->adMetric('meta', 'revenue', 1000); // USD
        $this->adMetric('snapchat', 'conversions', 10);
        $this->adMetric('snapchat', 'revenue', 500);
        $this->adMetric('tiktok', 'conversions', 5);
        $this->adMetric('tiktok', 'revenue', 100);

        $gap = $this->store('from=2026-10-01&to=2026-10-10')['gap'];

        $this->assertFalse($gap['summed']);
        $this->assertTrue($gap['platforms_available']);
        $rows = collect($gap['rows'])->keyBy('platform');
        $this->assertSame(['meta', 'snapchat', 'tiktok'], $rows->keys()->all());

        $this->assertEquals(20.0, $rows['meta']['reported']['conversions']);
        $this->assertEquals(8.0, $rows['meta']['ga4']['purchases'], 'facebook and instagram paid visits are one platform');
        $this->assertEquals(400.0, $rows['meta']['ga4']['sessions']);
        $this->assertEquals(12.0, $rows['meta']['gap']['conversions'], 'platform − GA4');
        $this->assertEquals(0.6, $rows['meta']['gap']['conversions_share'], '12 ÷ the platform’s own 20');
        $this->assertEquals(400.0, $rows['meta']['ga4']['revenue_usd'], '1600 SAR at 0.25');
        $this->assertEquals(600.0, $rows['meta']['gap']['revenue_usd']);
        $this->assertEquals(0.6, $rows['meta']['gap']['revenue_share']);

        $this->assertEquals(-2.0, $rows['snapchat']['gap']['conversions'], 'GA4 measured more than the platform claimed');
        $this->assertEquals(-0.2, $rows['snapchat']['gap']['conversions_share']);

        $this->assertEquals(0.0, $rows['tiktok']['ga4']['sessions'], 'a read property that saw no visit measured zero');
        $this->assertEquals(5.0, $rows['tiktok']['gap']['conversions']);
        $this->assertEquals(1.0, $rows['tiktok']['gap']['conversions_share']);

        $this->assertSame(['organic', 'direct', 'referral', 'other'], array_column($gap['unpaid'], 'class'));
        $this->assertEquals([250, 100, 20, 10], array_column($gap['unpaid'], 'sessions'));
        $this->assertEquals(0.25, $gap['unpaid'][0]['session_share']);
        $this->assertEquals(20.0, $gap['other_paid']['sessions']);
        $this->assertEquals(1000.0, $gap['ga4_sessions']);

        // No figure anywhere in the reading is a platform number plus a GA4 number.
        $flat = json_encode($gap);
        foreach ([28, 22, 5 + 0, 1400 + 1000, 2400 + 500] as $sum) {
            $this->assertStringNotContainsString(':'.$sum.'.0', (string) $flat);
        }
        foreach (['blended', 'combined', 'total_conversions', 'total_revenue'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $flat);
        }
    }

    public function test_the_gap_is_withheld_where_a_side_is_absent_or_the_platform_reported_nothing(): void
    {
        $gap = app(Ga4PlatformGap::class)->build(
            ['meta' => ['conversions' => 0.0, 'revenue' => 0.0, 'currency' => 'USD', 'revenue_usd' => 0.0], 'tiktok' => ['conversions' => 4.0, 'revenue' => null, 'currency' => null, 'revenue_usd' => null]],
            [['source' => 'facebook', 'medium' => 'cpc', 'sessions' => 50.0, 'purchases' => 1.0, 'revenue' => 100.0, 'revenue_usd' => null]],
            'SAR',
        );
        $rows = collect($gap['rows'])->keyBy('platform');

        $this->assertEquals(-1.0, $rows['meta']['gap']['conversions']);
        $this->assertNull($rows['meta']['gap']['conversions_share'], 'nothing to take a share of');
        $this->assertNull($rows['meta']['ga4']['revenue_usd'], 'no rate, no USD');
        $this->assertNull($rows['meta']['gap']['revenue_usd'], 'money is never compared across two currencies');
        $this->assertNull($rows['tiktok']['gap']['revenue_usd']);

        $none = app(Ga4PlatformGap::class)->build(['meta' => ['conversions' => 4.0, 'revenue' => 10.0, 'currency' => 'USD', 'revenue_usd' => 10.0]], [], 'SAR');
        $this->assertNull($none['rows'][0]['ga4'], 'a property with no source rows measured nothing, which is not zero');
        $this->assertNull($none['rows'][0]['gap']['conversions']);
        $this->assertNull($none['ga4_sessions']);
    }

    public function test_one_mapper_decides_whose_visit_a_session_was(): void
    {
        $gap = app(Ga4PlatformGap::class);

        $this->assertSame('meta', $gap->classOf('facebook', 'paid_social'));
        $this->assertSame('google', $gap->classOf('google', 'cpc'));
        $this->assertSame('organic', $gap->classOf('google', 'organic'), 'the medium decides paid, never the source alone');
        $this->assertSame('other', $gap->classOf('facebook', 'social'));
        $this->assertSame('other_paid', $gap->classOf('newsletter', 'cpc'));
        $this->assertSame('direct', $gap->classOf('(direct)', '(none)'));
        $this->assertSame('referral', $gap->classOf('example.com', 'referral'));
    }

    public function test_a_project_never_reads_another_projects_property(): void
    {
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $mine = $this->property($this->project, '111');
        $theirs = $this->property($other, '222');
        $this->daily($mine, '2026-10-05', ['sessions' => 10, 'transactions' => 1, 'revenue' => 50]);
        $this->daily($theirs, '2026-10-05', ['sessions' => 999, 'transactions' => 99, 'revenue' => 9999], $other);
        $this->dimRows($theirs, $other, 'source_medium', '2026-10-05', [['secret-source', 'cpc', ['sessions' => 999, 'purchases' => 99, 'revenue' => 9999]]]);
        $this->dimRows($theirs, $other, 'product', '2026-10-05', [['secret-product', '', ['item_revenue' => 9999]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $this->assertSame('111', $out['property']['id']);
        $this->assertEquals(10.0, collect($out['kpis'])->firstWhere('key', 'sessions')['current']);
        $flat = (string) json_encode($out);
        $this->assertStringNotContainsString('secret-', $flat);
        $this->assertStringNotContainsString('999', $flat);

        // A row of the other project filed under MY property id still does not surface: the project is in the WHERE.
        $this->dimRows($mine, $other, 'source_medium', '2026-10-05', [['leaked', 'cpc', ['sessions' => 5]]]);
        $this->assertStringNotContainsString('leaked', (string) json_encode($this->store('from=2026-10-01&to=2026-10-10')));

        // A project with no property says so instead of borrowing one.
        $bare = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Bare', 'status' => 'active']);
        $this->assertSame('not_selected', $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$bare->id}/measurement/store?from=2026-10-01&to=2026-10-10")->assertOk()->json('data.state'));
    }

    public function test_another_tenant_cannot_open_the_reading(): void
    {
        $this->property($this->project);
        $this->getJson("/api/v1/projects/{$this->project->id}/measurement/store")->assertUnauthorized();
        $foreign = Tenant::create(['name' => 'F', 'slug' => 'f-'.uniqid(), 'status' => 'active']);
        $stranger = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $foreign);

        $status = $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/store")->status();
        $this->assertContains($status, [403, 404]);
    }

    public function test_a_selected_property_with_nothing_read_is_empty_and_an_unconnected_tenant_says_so(): void
    {
        $this->assertSame('not_connected', $this->store('from=2026-10-01&to=2026-10-10')['state']);
        $this->property($this->project);
        $this->assertSame('empty', $this->store('from=2026-10-01&to=2026-10-10')['state']);
    }

    /** @return array<string, mixed> */
    private function store(string $query): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/measurement/store?$query")->assertOk()->json('data');
    }

    private function property(Project $project, string $external = '111', ?string $currency = 'SAR'): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4 '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
            'provider_connection_id' => $connection->getKey(), 'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => $external, 'name' => 'Store '.$external, 'status' => 'active', 'discovered_at' => Carbon::now(),
            'currency' => $currency, 'timezone' => 'Asia/Riyadh', 'last_synced_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    private function rate(string $from, string $to, string $date, float $rate): void
    {
        CurrencyRate::query()->create(['base_currency' => $from, 'quote_currency' => $to, 'rate' => $rate, 'rate_date' => $date, 'source' => 'manual']);
    }

    /** @param  array<string, float|int>  $values */
    private function daily(ExternalAccount $property, string $date, array $values, ?Project $project = null): void
    {
        foreach ($values as $key => $value) {
            DB::table('measurement_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => ($project ?? $this->project)->id,
                'external_account_id' => $property->id, 'property_id' => (string) $property->external_id, 'metric_date' => $date,
                'metric_key' => $key, 'value' => $value, 'currency' => $key === 'revenue' ? $property->currency : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param  list<array{0: string, 1: string, 2: array<string, float|int>}>  $rows */
    private function dimRows(ExternalAccount $property, Project $project, string $breakdown, string $date, array $rows): void
    {
        foreach ($rows as [$d1, $d2, $metrics]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
                'external_account_id' => $property->id, 'property_id' => (string) $property->external_id, 'metric_date' => $date,
                'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2, 'metrics' => json_encode($metrics),
                'currency' => array_intersect(Ga4Breakdowns::MONEY, array_keys($metrics)) !== [] ? $property->currency : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function adMetric(string $provider, string $key, float $value): void
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Ads '.$provider);
        $account = ExternalAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'provider' => $provider, 'external_id' => 'ad-'.$provider, 'account_type' => 'ad_account'],
            ['client_workspace_id' => $this->project->client_workspace_id, 'provider_connection_id' => $connection->getKey(), 'name' => 'Ad', 'status' => 'active', 'discovered_at' => Carbon::now()],
        );
        ProjectIntegrationBinding::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id],
            ['client_workspace_id' => $this->project->client_workspace_id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true],
        );
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'external_campaign_id' => (string) Str::uuid(), 'provider' => $provider,
            'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'project_currency' => 'USD',
        ]);
    }
}
