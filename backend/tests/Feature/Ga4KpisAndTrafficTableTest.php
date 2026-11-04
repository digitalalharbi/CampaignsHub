<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4Metrics;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * GA4-BOUNCE-AOV-KPIS-001, TRAFFIC-DRILLDOWN-001, TABLE-SERVER-PAGING-001.
 *
 * Provider answers are FAKED: no real GA4 property is available, so nothing here proves a live
 * round trip. It proves the requests are written in Google's documented names, and that what comes
 * back is read, scoped, paged and exported correctly.
 */
final class Ga4KpisAndTrafficTableTest extends TestCase
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

    public function test_the_daily_request_uses_documented_ga4_metric_names_for_the_new_kpis(): void
    {
        $names = array_keys(Ga4Metrics::MAP);
        foreach (['bounceRate', 'userEngagementDuration', 'activeUsers', 'engagedSessions', 'itemsPurchased', 'transactions', 'purchaseRevenue'] as $documented) {
            $this->assertContains($documented, $names, "$documented is a documented Data API metric and must be requested");
        }
        $this->assertTrue(Ga4Metrics::isRate('bounce_rate'), 'a bounce rate is a rate and is never summed across days');
    }

    public function test_the_breakdowns_ask_first_user_channel_group_and_utm_content_term_in_ga4_names(): void
    {
        $property = $this->property($this->project);
        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['displayName' => 'Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR']),
            '*analyticsdata.googleapis.com*' => fn (Request $r) => Http::response(['metricHeaders' => [['name' => 'sessions']], 'rows' => []]),
        ]);

        app(Ga4PropertySync::class)->sync($property, 1);

        foreach ([
            ['date', 'firstUserSource', 'firstUserMedium'],
            ['date', 'sessionDefaultChannelGroup'],
            ['date', 'sessionDefaultChannelGroup', 'sessionSourceMedium'],
            ['date', 'sessionManualAdContent', 'sessionCampaignName'],
            ['date', 'sessionManualTerm', 'sessionCampaignName'],
        ] as $dims) {
            Http::assertSent(fn (Request $r) => str_contains($r->url(), ':runReport') && array_column($r['dimensions'], 'name') === $dims);
        }
    }

    public function test_the_kpis_show_current_previous_change_definition_source_and_freshness(): void
    {
        $property = $this->property($this->project);
        // previous window 2026-09-21..09-30, current 2026-10-01..10-10 (10 days each)
        $this->daily($property, '2026-09-25', ['sessions' => 100, 'engaged_sessions' => 40, 'bounce_rate' => 0.6, 'transactions' => 5, 'revenue' => 500, 'items_purchased' => 10, 'engagement_seconds' => 6000, 'active_users' => 50, 'users' => 80]);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'engaged_sessions' => 70, 'bounce_rate' => 0.3, 'transactions' => 4, 'revenue' => 800, 'items_purchased' => 12, 'engagement_seconds' => 9000, 'active_users' => 60, 'users' => 90]);
        $this->daily($property, '2026-10-03', ['sessions' => 300, 'engaged_sessions' => 210, 'bounce_rate' => 0.3, 'transactions' => 6, 'revenue' => 1200, 'items_purchased' => 18, 'engagement_seconds' => 21000, 'active_users' => 140, 'users' => 210]);
        $this->stamp($property);

        $out = $this->analytics('from=2026-10-01&to=2026-10-10');
        $this->assertSame('ready', $out['state']);
        $k = collect($out['kpis'])->keyBy('key');

        $this->assertEquals(0.3, $k['bounce_rate']['current'], 'weighted by sessions, never an average of fractions');
        $this->assertEquals(0.6, $k['bounce_rate']['previous']);
        $this->assertEquals(-0.3, round($k['bounce_rate']['change'], 4));
        $this->assertEquals(200.0, $k['aov']['current'], 'AOV = revenue / transactions = 2000 / 10, derived, not a sum of averages');
        $this->assertEquals(100.0, $k['aov']['previous']);
        $this->assertEquals(3.0, $k['items_per_order']['current']);
        $this->assertEquals(150.0, $k['avg_engagement_seconds']['current']);
        $this->assertEquals(5.0, $k['revenue_per_session']['current'] / 1.0);
        $this->assertEqualsWithDelta(2000 / 300, $k['revenue_per_user']['current'], 0.00001);
        foreach (['bounce_rate', 'aov', 'items_per_order', 'avg_engagement_seconds', 'revenue_per_user', 'revenue_per_session'] as $key) {
            $this->assertNotSame('', (string) $k[$key]['definition']);
            $this->assertSame('ga4', $k[$key]['source']['kind']);
            $this->assertNotEmpty($k[$key]['source']['metrics']);
            $this->assertFalse($k[$key]['blended_with_other_sources']);
            $this->assertArrayHasKey('freshness', $k[$key]);
        }
        $this->assertSame('SAR', $k['aov']['currency']);
        $this->assertNotNull($out['kpi_freshness']['last_synced_at']);
        $this->assertSame('2026-09-21', $out['previous_period']['from']);
        $this->assertSame('2026-09-30', $out['previous_period']['to']);
    }

    public function test_a_kpi_with_no_data_is_absent_not_zero_and_revenue_needs_both_counts(): void
    {
        $property = $this->property($this->project);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'revenue' => 800]);
        $out = $this->analytics('from=2026-10-01&to=2026-10-10');
        $k = collect($out['kpis'])->keyBy('key');
        foreach (['bounce_rate', 'aov', 'items_per_order', 'avg_engagement_seconds', 'revenue_per_user'] as $key) {
            $this->assertNull($k[$key]['current'], "$key has no source data, so it is absent");
        }
        $this->assertEquals(8.0, $k['revenue_per_session']['current']);
        $this->assertNull($k['revenue_per_session']['previous']);
        $this->assertNull($k['revenue_per_session']['change']);
    }

    public function test_the_table_pages_sorts_and_totals_on_the_server_with_10_25_50_only(): void
    {
        $property = $this->property($this->project);
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['src'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'cpc', ['sessions' => $i, 'purchases' => 1, 'revenue' => $i * 10]];
        }
        $this->dimRows($property, $this->project, 'source_medium', $rows);

        $p1 = $this->table('breakdown=source_medium&from=2026-10-01&to=2026-10-10');
        $this->assertSame(10, $p1['per_page']);
        $this->assertSame(60, $p1['total']);
        $this->assertSame(6, $p1['pages']);
        $this->assertCount(10, $p1['rows']);
        $this->assertSame('src60', $p1['rows'][0]['dimension_1'], 'default sort is sessions descending');

        $p2 = $this->table('breakdown=source_medium&per_page=25&page=3&from=2026-10-01&to=2026-10-10');
        $this->assertCount(10, $p2['rows']);
        $this->assertSame(3, $p2['page']);
        $this->assertSame('src10', $p2['rows'][0]['dimension_1']);

        $bad = $this->table('breakdown=source_medium&per_page=1000&from=2026-10-01&to=2026-10-10');
        $this->assertSame(10, $bad['per_page'], 'a page size outside 10/25/50 falls back to 10');

        $asc = $this->table('breakdown=source_medium&per_page=50&sort=dimension_1&dir=asc&from=2026-10-01&to=2026-10-10');
        $this->assertCount(50, $asc['rows']);
        $this->assertSame('src01', $asc['rows'][0]['dimension_1']);

        $q = $this->table('breakdown=source_medium&q=src5&from=2026-10-01&to=2026-10-10');
        $this->assertSame(10, $q['total'], 'src50..src59, filtered in the query');
    }

    public function test_the_table_never_falls_back_to_another_project_or_to_all_data(): void
    {
        $property = $this->property($this->project);
        $this->dimRows($property, $this->project, 'source_medium', [['mine', 'cpc', ['sessions' => 5]]]);

        $ws2 = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed']);
        $other = Project::create(['client_workspace_id' => $ws2->id, 'name' => 'Other', 'status' => 'active']);
        $otherProperty = $this->property($other, '222');
        $this->dimRows($otherProperty, $other, 'source_medium', [['theirs', 'cpc', ['sessions' => 999]]]);

        $out = $this->table('breakdown=source_medium&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['mine'], array_column($out['rows'], 'dimension_1'));

        $ws3 = ClientWorkspace::create(['name' => 'C3', 'slug' => 'c3-'.uniqid(), 'mode' => 'managed']);
        $bare = Project::create(['client_workspace_id' => $ws3->id, 'name' => 'Bare', 'status' => 'active']);
        $res = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$bare->id}/measurement/analytics/table?breakdown=source_medium")
            ->assertOk()->json('data');
        $this->assertSame([], $res['rows']);
        $this->assertSame(0, $res['total']);
        $this->assertSame('not_selected', $res['state'], 'the tenant has GA4 properties, but none is selected for this project: empty, never another project\'s');
    }

    public function test_the_drill_down_chains_channel_to_source_to_campaign_to_page_within_the_filter(): void
    {
        $property = $this->property($this->project);
        $this->dimRows($property, $this->project, 'default_channel_group', [['Paid Social', '', ['sessions' => 300]], ['Organic Search', '', ['sessions' => 200]]]);
        $this->dimRows($property, $this->project, 'channel_source', [['Paid Social', 'facebook / paid_social', ['sessions' => 300]], ['Organic Search', 'google / organic', ['sessions' => 200]]]);
        $this->dimRows($property, $this->project, 'campaign', [['sale', 'facebook / paid_social', ['sessions' => 250]], ['brand', 'google / organic', ['sessions' => 200]]]);
        $this->dimRows($property, $this->project, 'campaign_landing', [['sale', '/sale', ['sessions' => 250, 'purchases' => 8]], ['brand', '/', ['sessions' => 200]]]);

        $src = $this->table('breakdown=channel_source&where_d1='.urlencode('Paid Social').'&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['facebook / paid_social'], array_column($src['rows'], 'dimension_2'));
        $camp = $this->table('breakdown=campaign&where_d2='.urlencode('facebook / paid_social').'&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['sale'], array_column($camp['rows'], 'dimension_1'));
        $page = $this->table('breakdown=campaign_landing&where_d1=sale&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['/sale'], array_column($page['rows'], 'dimension_2'));
    }

    public function test_an_unknown_breakdown_or_sort_is_refused_not_interpolated(): void
    {
        $this->property($this->project);
        $base = "/api/v1/projects/{$this->project->id}/measurement/analytics/table";
        $this->actingAs($this->owner, 'sanctum')->getJson($base.'?breakdown=users;drop')->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->getJson($base.'?breakdown=source_medium&sort=sessions;drop')->assertStatus(422);
    }

    public function test_csv_and_xlsx_exports_carry_every_row_in_scope_whatever_the_screen_page_is(): void
    {
        $property = $this->property($this->project);
        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ['src'.$i, 'cpc', ['sessions' => $i]];
        }
        $this->dimRows($property, $this->project, 'source_medium', $rows);
        $ws2 = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed']);
        $other = Project::create(['client_workspace_id' => $ws2->id, 'name' => 'Other', 'status' => 'active']);
        $this->dimRows($this->property($other, '222'), $other, 'source_medium', [['leak', 'cpc', ['sessions' => 1]]]);

        $url = "/api/v1/projects/{$this->project->id}/measurement/analytics/table/export?breakdown=source_medium&per_page=10&page=2&from=2026-10-01&to=2026-10-10";
        $csv = $this->actingAs($this->owner, 'sanctum')->get($url.'&format=csv')->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(121, $lines, 'header + all 120 rows, not the 10 on screen');
        $this->assertStringNotContainsString('leak', $csv);

        $res = $this->actingAs($this->owner, 'sanctum')->get($url.'&format=xlsx')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, $res->baseResponse instanceof StreamedResponse ? $res->streamedContent() : $res->getContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $this->assertSame(121, $sheet->getHighestRow());
        @unlink($tmp);

        $filtered = $this->actingAs($this->owner, 'sanctum')->get($url.'&format=csv&q=src12')->assertOk()->streamedContent();
        $this->assertCount(1 + 2, array_filter(explode("\n", trim($filtered))), 'the export keeps the filter: src12 and src120');
    }

    public function test_the_daily_figures_are_asked_in_requests_of_at_most_ten_metrics_and_merged_by_day(): void
    {
        $property = $this->property($this->project);
        $seen = [];
        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['displayName' => 'Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR']),
            '*analyticsdata.googleapis.com*' => function (Request $r) use (&$seen) {
                $metrics = array_column($r['metrics'], 'name');
                if (array_column($r['dimensions'], 'name') !== ['date']) {
                    return Http::response(['metricHeaders' => [], 'rows' => []]);
                }
                $seen[] = $metrics;

                return Http::response([
                    'metricHeaders' => array_map(static fn (string $m): array => ['name' => $m], $metrics),
                    'rows' => [['dimensionValues' => [['value' => '20261005']], 'metricValues' => array_map(static fn (): array => ['value' => '7'], $metrics)]],
                ]);
            },
        ]);

        app(Ga4PropertySync::class)->sync($property, 1);

        $this->assertNotEmpty($seen);
        foreach ($seen as $metrics) {
            $this->assertLessThanOrEqual(10, count($metrics), 'GA4 caps a report at ten metrics; a wider request fails the whole daily sync');
        }
        $asked = array_merge(...$seen);
        sort($asked);
        $expected = array_keys(Ga4Metrics::MAP);
        sort($expected);
        $this->assertSame($expected, $asked, 'every metric asked exactly once across the requests');
        $stored = DB::table('measurement_daily_metrics')->where('external_account_id', $property->id)->where('metric_date', '2026-10-05')->pluck('value', 'metric_key');
        foreach (Ga4Metrics::MAP as $key) {
            $this->assertEquals(7.0, $stored[$key] ?? null, "$key from whichever request carried it lands on the one day");
        }
    }

    public function test_product_views_active_and_new_users_are_kpis_and_every_kpi_carries_its_daily_trend(): void
    {
        $this->assertContains('itemsViewed', array_keys(Ga4Metrics::MAP), 'a documented Data API metric');
        $property = $this->property($this->project);
        $this->daily($property, '2026-10-02', ['sessions' => 100, 'product_views' => 40, 'active_users' => 5, 'new_users' => 2, 'page_views' => 300, 'revenue' => 50, 'transactions' => 2]);
        $this->daily($property, '2026-10-04', ['sessions' => 50, 'product_views' => 10, 'active_users' => 7, 'new_users' => 1, 'page_views' => 100, 'revenue' => 90, 'transactions' => 3]);

        $k = collect($this->analytics('from=2026-10-01&to=2026-10-10')['kpis'])->keyBy('key');
        $this->assertEquals(50.0, $k['product_views']['current']);
        $this->assertEquals(12.0, $k['active_users']['current']);
        $this->assertEquals(3.0, $k['new_users']['current']);
        $this->assertEquals([['date' => '2026-10-02', 'value' => 40.0], ['date' => '2026-10-04', 'value' => 10.0]], $k['product_views']['trend'], 'a day with no measurement is left out, not drawn as zero');
        $this->assertEquals(25.0, $k['aov']['trend'][0]['value'], 'a derived KPI is derived per day');
        $this->assertSame([], collect($this->analytics('from=2026-09-01&to=2026-09-03')['kpis'] ?? [])->keyBy('key')['aov']['trend'] ?? []);
    }

    public function test_referrals_and_the_paid_organic_direct_split_are_views_of_stored_rows(): void
    {
        $property = $this->property($this->project);
        $this->dimRows($property, $this->project, 'source_medium', [
            ['blog.example.org', 'referral', ['sessions' => 30, 'purchases' => 1]],
            ['google', 'cpc', ['sessions' => 90]],
        ]);
        $this->dimRows($property, $this->project, 'default_channel_group', [
            ['Paid Search', '', ['sessions' => 40, 'purchases' => 2, 'revenue' => 100]],
            ['Paid Social', '', ['sessions' => 60, 'purchases' => 3, 'revenue' => 200]],
            ['Organic Search', '', ['sessions' => 20]],
            ['Direct', '', ['sessions' => 10]],
            ['Referral', '', ['sessions' => 5]],
            ['Email', '', ['sessions' => 4]],
        ]);

        $referrals = $this->table('breakdown=referrals&from=2026-10-01&to=2026-10-10');
        $this->assertSame(1, $referrals['total']);
        $this->assertSame('blog.example.org', $referrals['rows'][0]['dimension_1']);

        $split = collect($this->table('breakdown=traffic_class&from=2026-10-01&to=2026-10-10')['rows'])->keyBy('dimension_1');
        $this->assertEquals(100.0, $split['paid']['sessions']);
        $this->assertEquals(300.0, $split['paid']['revenue']);
        $this->assertEquals(20.0, $split['organic']['sessions']);
        $this->assertEquals(10.0, $split['direct']['sessions']);
        $this->assertEquals(5.0, $split['referral']['sessions']);
        $this->assertEquals(4.0, $split['other']['sessions']);
        $this->assertNull($split['organic']['purchases'], 'absent stays absent');
    }

    public function test_both_new_views_stay_inside_their_project(): void
    {
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Q', 'status' => 'active']);
        $theirs = $this->property($other, '222');
        $this->property($this->project);
        $this->dimRows($theirs, $other, 'source_medium', [['leak.example', 'referral', ['sessions' => 9]]]);
        $this->dimRows($theirs, $other, 'default_channel_group', [['Direct', '', ['sessions' => 9]]]);

        $this->assertSame(0, $this->table('breakdown=referrals&from=2026-10-01&to=2026-10-10')['total']);
        $this->assertSame(0, $this->table('breakdown=traffic_class&from=2026-10-01&to=2026-10-10')['total']);
    }

    /** @return array<string, mixed> */
    private function analytics(string $query): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/measurement/analytics?$query")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function table(string $query): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/measurement/analytics/table?$query")->assertOk()->json('data');
    }

    private function property(Project $project, string $external = '111'): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4 '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
            'provider_connection_id' => $connection->getKey(), 'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => $external, 'name' => 'Store '.$external, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    private function stamp(ExternalAccount $property): void
    {
        $property->forceFill(['last_synced_at' => Carbon::now(), 'currency' => 'SAR'])->save();
    }

    /** @param  array<string, float|int>  $values */
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

    /** @param  list<array{0: string, 1: string, 2: array<string, float|int>}>  $rows */
    private function dimRows(ExternalAccount $property, Project $project, string $breakdown, array $rows): void
    {
        foreach ($rows as [$d1, $d2, $metrics]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
                'external_account_id' => $property->id, 'property_id' => (string) $property->external_id, 'metric_date' => '2026-10-05',
                'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2, 'metrics' => json_encode($metrics),
                'currency' => array_key_exists('revenue', $metrics) ? 'SAR' : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
