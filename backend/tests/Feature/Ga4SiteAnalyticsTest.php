<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
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
use Tests\TestCase;

/**
 * GA4-ANALYTICS-PRODUCT-001 — the property's breakdowns are read and stored, and the site analytics
 * built from them obey the counting rules: counts add up within a breakdown, users never do, paid
 * traffic is recognised by medium and its platform by source, and GA4 stays a layer of its own.
 */
final class Ga4SiteAnalyticsTest extends TestCase
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

    public function test_the_sync_asks_each_breakdown_in_ga4_s_own_names_and_stores_it_per_day(): void
    {
        $property = $this->property();
        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['displayName' => 'Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR']),
            '*analyticsdata.googleapis.com*' => function (Request $request) {
                $dims = array_column($request['dimensions'], 'name');
                if ($dims === ['date']) {
                    return Http::response(['metricHeaders' => [['name' => 'sessions']], 'rows' => [['dimensionValues' => [['value' => '20261007']], 'metricValues' => [['value' => '10']]]]]);
                }
                $metrics = array_column($request['metrics'], 'name');
                $values = array_map(static fn () => ['value' => '3'], $metrics);

                return Http::response([
                    'metricHeaders' => array_map(static fn (string $m) => ['name' => $m], $metrics),
                    'rows' => [['dimensionValues' => array_map(static fn (string $d) => ['value' => $d === 'date' ? '20261007' : $d.'-value'], $dims), 'metricValues' => $values]],
                ]);
            },
        ]);

        app(Ga4PropertySync::class)->sync($property, 1);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), ':runReport') && array_column($r['dimensions'], 'name') === ['date', 'sessionSource', 'sessionMedium']
            && in_array('ecommercePurchases', array_column($r['metrics'], 'name'), true));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), ':runReport') && array_column($r['dimensions'], 'name') === ['date', 'eventName']
            && array_column($r['metrics'], 'name') === ['eventCount', 'keyEvents']);
        $this->assertSame(7, DB::table('measurement_dimension_rows')->count());
        $row = DB::table('measurement_dimension_rows')->where('breakdown', 'source_medium')->first();
        $this->assertSame('sessionSource-value', $row->dimension_1);
        $this->assertSame('sessionMedium-value', $row->dimension_2);
        $this->assertSame(3.0, (float) json_decode($row->metrics, true)['purchases']);
        $run = IntegrationSyncRun::withoutGlobalScopes()->where('type', 'measurement')->sole();
        $this->assertSame(1, (int) $run->records, 'records stay the daily figures');
        $this->assertSame(7, $run->meta['breakdown_rows']);
    }

    public function test_a_failing_breakdown_is_named_and_the_daily_figures_still_land(): void
    {
        $property = $this->property();
        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['displayName' => 'Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR']),
            '*analyticsdata.googleapis.com*' => function (Request $request) {
                $dims = array_column($request['dimensions'], 'name');
                if (in_array('landingPage', $dims, true)) {
                    return Http::response(['error' => ['message' => 'Quota exceeded']], 429);
                }

                return Http::response(['metricHeaders' => [['name' => 'sessions']], 'rows' => []]);
            },
        ]);

        app(Ga4PropertySync::class)->sync($property, 1);

        $run = IntegrationSyncRun::withoutGlobalScopes()->where('type', 'measurement')->sole();
        $this->assertArrayHasKey('landing_page', $run->meta['breakdowns_failed']);
    }

    public function test_the_analytics_add_counts_within_a_breakdown_and_recognise_paid_platforms(): void
    {
        $property = $this->property();
        $this->rows($property, 'device', [['desktop', '', ['sessions' => 600, 'engaged_sessions' => 300, 'add_to_carts' => 90, 'checkouts' => 40, 'purchases' => 20, 'revenue' => 4000]],
            ['mobile', '', ['sessions' => 400, 'engaged_sessions' => 260, 'add_to_carts' => 70, 'checkouts' => 30, 'purchases' => 10, 'revenue' => 1500]]]);
        $this->rows($property, 'source_medium', [
            ['facebook', 'paid_social', ['sessions' => 300, 'purchases' => 9, 'revenue' => 1800]],
            ['google', 'cpc', ['sessions' => 200, 'purchases' => 8, 'revenue' => 1600]],
            ['google', 'organic', ['sessions' => 350, 'purchases' => 10, 'revenue' => 1700]],
            ['(direct)', '(none)', ['sessions' => 150, 'purchases' => 3, 'revenue' => 400]],
        ]);
        $this->rows($property, 'event', [['purchase', '', ['event_count' => 30, 'key_events' => 30]], ['page_view', '', ['event_count' => 4000, 'key_events' => 0]]]);

        $out = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/measurement/analytics?from=2026-10-01&to=2026-10-10")
            ->assertOk()->json('data');

        $this->assertSame('ready', $out['state']);
        $this->assertEquals(1000, $out['totals']['sessions']);
        $this->assertEquals(30, $out['totals']['purchases']);
        $this->assertEquals(0.56, $out['totals']['engagement_rate']);
        $this->assertEquals([1000, 160, 70, 30], array_column($out['funnel'], 'count'));
        $this->assertEquals(0.5, $out['paid']['session_share']);
        $this->assertSame(['meta', 'google'], array_column($out['paid']['platforms'], 'platform'));
        $this->assertSame('facebook', $out['breakdowns']['source_medium']['rows'][1]['dimension_1']);
        $this->assertNull($out['breakdowns']['landing_page'], 'a breakdown never returned is absent, not empty');
        $this->assertTrue($out['events']['rows'][1]['is_key_event']);
    }

    public function test_paid_traffic_is_recognised_by_medium_and_its_platform_by_source(): void
    {
        $site = app(Ga4SiteAnalytics::class);
        $this->assertSame('meta', $site->platformOf('instagram', 'paid_social'));
        $this->assertSame('google', $site->platformOf('google', 'cpc'));
        $this->assertSame('snapchat', $site->platformOf('snapchat', 'paid'));
        $this->assertSame('other_paid', $site->platformOf('newsletter-partner', 'cpm'));
        $this->assertNull($site->platformOf('google', 'organic'));
        $this->assertNull($site->platformOf('facebook', 'referral'));
    }

    public function test_a_project_with_no_selected_property_says_so(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/measurement/analytics")
            ->assertOk()->assertJsonPath('data.state', 'not_connected');
    }

    private function property(): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4');
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id,
            'provider_connection_id' => $connection->getKey(), 'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => '111', 'name' => 'Store', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param  list<array{0: string, 1: string, 2: array<string, float|int>}>  $rows */
    private function rows(ExternalAccount $property, string $breakdown, array $rows): void
    {
        foreach ($rows as [$d1, $d2, $metrics]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $property->id, 'property_id' => '111', 'metric_date' => '2026-10-05',
                'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2, 'metrics' => json_encode($metrics),
                'currency' => array_key_exists('revenue', $metrics) ? 'SAR' : null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
