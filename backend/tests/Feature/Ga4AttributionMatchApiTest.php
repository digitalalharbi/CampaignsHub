<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4Breakdowns;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
final class Ga4AttributionMatchApiTest extends TestCase
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

    public function test_it_matches_each_campaign_to_its_ga4_traffic_and_labels_the_basis(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1201', 'Ramadan Sale', ['conversions' => 20, 'revenue' => 4000, 'spend' => 800]);
        $this->campaign('snapchat', '55', 'eid_push', ['conversions' => 9, 'revenue' => 900, 'spend' => 300]);
        $this->campaign('tiktok', '77', 'ghost', ['conversions' => 3, 'revenue' => 100, 'spend' => 90]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-05', [
            ['1201', 'facebook / paid_social', ['sessions' => 400, 'purchases' => 10, 'revenue' => 2000]],
            ['eid_push', 'snapchat / cpc', ['sessions' => 200, 'purchases' => 4, 'revenue' => 800]],
            ['(organic)', 'google / organic', ['sessions' => 300, 'purchases' => 6, 'revenue' => 1200]],
        ]);

        $out = $this->match('from=2026-10-01&to=2026-10-10');

        $this->assertSame('ready', $out['state']);
        $this->assertSame('ga4', $out['basis']);
        $this->assertFalse($out['order_level']);
        $this->assertFalse($out['summed']);
        $by = collect($out['platforms'])->keyBy('platform');
        $meta = $by['meta']['campaigns'][0];
        $this->assertSame('id', $meta['confidence']);
        $this->assertEquals(10.0, $meta['ga4']['purchases']);
        $this->assertEquals(500.0, $meta['ga4']['revenue_usd'], '2000 SAR at 0.25');
        $this->assertEquals(10.0, $meta['gap']['conversions']);
        $this->assertSame('utm', $by['snapchat']['campaigns'][0]['confidence']);
        $this->assertSame('unmatched', $by['tiktok']['campaigns'][0]['confidence']);
        $this->assertEquals(300.0, $out['unattributed']['sessions']);
        $this->assertSame(1, $out['unmatched']['campaigns']);
        $this->assertEquals(90.0, $out['unmatched']['spend_usd']);
    }

    public function test_a_project_never_sees_another_projects_property_or_campaigns(): void
    {
        $ws = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed']);
        $other = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Other', 'status' => 'active']);
        $theirs = $this->property($other, '999');
        $this->dimRows($theirs, $other, 'campaign', '2026-10-05', [['secret_campaign', 'facebook / paid_social', ['sessions' => 999, 'purchases' => 9, 'revenue' => 9]]]);
        ExternalCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $other->id, 'client_workspace_id' => $ws->id, 'external_account_id' => $theirs->id, 'provider' => 'meta', 'external_id' => 'x', 'name' => 'secret_campaign', 'status' => 'active']);

        $out = $this->match('from=2026-10-01&to=2026-10-10');

        $this->assertSame('not_selected', $out['state'], 'the tenant has a property, but not one selected for THIS project');
        $this->assertStringNotContainsString('secret_campaign', json_encode($out));

        $mine = $this->property($this->project, '111');
        $this->dimRows($mine, $this->project, 'campaign', '2026-10-05', [['(direct)', '(direct) / (none)', ['sessions' => 10, 'purchases' => 0, 'revenue' => 0]]]);
        $out = $this->match('from=2026-10-01&to=2026-10-10');
        $this->assertStringNotContainsString('secret_campaign', json_encode($out));
        $this->assertEquals(10.0, $out['unattributed']['sessions']);
    }

    public function test_filters_narrow_the_table_and_the_previous_period_is_offered(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->campaign('meta', '1', 'a', ['conversions' => 5, 'revenue' => 500, 'spend' => 100]);
        $this->campaign('snapchat', '2', 'b', ['conversions' => 5, 'revenue' => 500, 'spend' => 100]);
        $this->dimRows($property, $this->project, 'campaign', '2026-10-05', [['a', 'facebook / paid_social', ['sessions' => 50, 'purchases' => 2, 'revenue' => 100]], ['b', 'snapchat / cpc', ['sessions' => 60, 'purchases' => 3, 'revenue' => 100]]]);
        $this->dimRows($property, $this->project, 'campaign', '2026-09-25', [['a', 'facebook / paid_social', ['sessions' => 20, 'purchases' => 1, 'revenue' => 50]]]);

        $out = $this->match('from=2026-10-01&to=2026-10-10&platform=snapchat&compare=1');

        $this->assertSame(['snapchat'], array_column($out['platforms'], 'platform'));
        $this->assertSame('b', $out['platforms'][0]['campaigns'][0]['name']);
        $this->assertSame(['from' => '2026-09-21', 'to' => '2026-09-30'], $out['previous']['period']);
    }

    public function test_the_classification_groups_with_one_control_and_reports_filters_it_cannot_honour(): void
    {
        $property = $this->property($this->project);
        $this->rate('SAR', 'USD', '2026-09-01', 0.25);
        $this->dimRows($property, $this->project, 'source_medium', '2026-10-05', [
            ['facebook', 'paid_social', ['sessions' => 100, 'purchases' => 4, 'revenue' => 400]],
            ['snapchat', 'cpc', ['sessions' => 300, 'purchases' => 6, 'revenue' => 600]],
            ['google', 'organic', ['sessions' => 100, 'purchases' => 1, 'revenue' => 80]],
        ]);
        $this->dimRows($property, $this->project, 'device', '2026-10-05', [['mobile', '', ['sessions' => 400, 'purchases' => 10, 'revenue' => 1000]]]);

        $platform = $this->classification('group=platform&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['snapchat', 'meta', 'organic'], array_column($platform['rows'], 'label'));
        $this->assertEquals(0.6, $platform['rows'][0]['share']);
        $this->assertEquals(150.0, $platform['rows'][0]['revenue_usd']);

        $filtered = $this->classification('group=source_medium&platform=meta&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['facebook / paid_social'], array_column($filtered['rows'], 'label'));

        $device = $this->classification('group=device&platform=meta&from=2026-10-01&to=2026-10-10');
        $this->assertSame(['platform'], $device['unsupported'], 'device rows carry no platform: the filter is not silently ignored');
        $this->assertSame(10, $device['per_page']);
    }

    public function test_saved_views_for_this_module_are_per_user_and_tenant_scoped(): void
    {
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/dashboard/saved-views', ['name' => 'Paid only', 'module' => 'ga4_site', 'filters' => ['platform' => 'meta', 'group' => 'campaign']])->assertCreated();

        $mine = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/saved-views?module=ga4_site')->assertOk()->json('data');
        $this->assertCount(1, $mine);

        $stranger = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $this->tenant);
        $this->app->forgetScopedInstances();
        $theirs = $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/dashboard/saved-views?module=ga4_site')->assertOk()->json('data');
        $this->assertCount(0, $theirs, 'another user never sees this user\'s views');
    }

    /** @param  array<string, float|int>  $m */
    private function campaign(string $provider, string $externalId, string $name, array $m): ExternalCampaign
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
        $c = ExternalCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->project->client_workspace_id, 'external_account_id' => $account->id, 'provider' => $provider, 'external_id' => $externalId, 'name' => $name, 'status' => 'active']);
        foreach ([['conversions', $m['conversions']], ['revenue', $m['revenue']], ['spend', $m['spend']]] as [$key, $value]) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'external_campaign_id' => $c->id, 'provider' => $provider,
                'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'project_currency' => 'USD',
            ]);
        }

        return $c;
    }

    private function match(string $query): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/attribution?$query")->assertOk()->json('data');
    }

    private function classification(string $query): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/measurement/classification?$query")->assertOk()->json('data');
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
}
