<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
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
 * WEBSITE-TOTALS-001 — one number per figure: every breakdown of the visits either sums to the visits tile or says why not.
 *
 * Provider answers are FIXTURES in the shape of the GA4 Data API `runReport` response
 * (`metricHeaders`, `rows[].dimensionValues`, `rows[].metricValues`): no live property exists, so
 * nothing here proves a live round trip. It proves the requests carry Google's documented names and
 * that every figure the store reading states is the arithmetic the rules promise.
 */
final class Ga4TotalsReconciliationTest extends TestCase
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

    public function test_a_breakdown_that_sums_to_the_visits_stays_as_it_is(): void
    {
        $property = $this->property($this->project);
        $this->daily($property, '2026-10-05', ['sessions' => 1000]);
        $this->dimRows($property, $this->project, 'default_channel_group', '2026-10-05', [['Paid Social', '', ['sessions' => 700]], ['Direct', '', ['sessions' => 300]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $this->assertSame(1000.0, (float) array_sum(array_column($out['channels'], 'sessions')));
        $this->assertNotContains('unclassified', array_column($out['channels'], 'key'));
        $this->assertEquals(['total' => 1000.0, 'classified' => 1000.0, 'unclassified' => 0.0, 'rescaled' => false, 'days_covered' => 1, 'days_total' => 1], $out['channels_meta']);
    }

    public function test_a_not_set_bucket_is_kept_and_the_missing_remainder_is_an_explicit_unclassified_slice(): void
    {
        $property = $this->property($this->project);
        $this->daily($property, '2026-10-05', ['sessions' => 1000]);
        $this->dimRows($property, $this->project, 'device', '2026-10-05', [['mobile', '', ['sessions' => 800]], ['(not set)', '', ['sessions' => 50]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $keys = array_column($out['devices'], 'key');
        $this->assertContains('(not set)', $keys);
        $this->assertSame('unclassified', end($keys));
        $this->assertSame(1000.0, (float) array_sum(array_column($out['devices'], 'sessions')));
        $last = end($out['devices']);
        $this->assertEquals(150, $last['sessions']);
        $this->assertEquals(0.15, $last['share']);
        $this->assertNull($last['purchases']);
        $this->assertNull($last['revenue']);
        $this->assertEquals(150.0, $out['devices_meta']['unclassified']);
    }

    public function test_a_breakdown_synced_for_only_some_of_the_days_is_closed_by_unclassified_and_says_how_many_days(): void
    {
        $property = $this->property($this->project);
        foreach (['2026-10-03', '2026-10-04', '2026-10-05'] as $day) {
            $this->daily($property, $day, ['sessions' => 4000]);
        }
        // Channels were only ever synced for the last day (the restatement window), devices for all three.
        $this->dimRows($property, $this->project, 'default_channel_group', '2026-10-05', [['Direct', '', ['sessions' => 3000]], ['Paid Social', '', ['sessions' => 1000]]]);
        foreach (['2026-10-03', '2026-10-04', '2026-10-05'] as $day) {
            $this->dimRows($property, $this->project, 'device', $day, [['mobile', '', ['sessions' => 4000]]]);
        }

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $this->assertSame(12000.0, (float) array_sum(array_column($out['channels'], 'sessions')));
        $this->assertSame(12000.0, (float) array_sum(array_column($out['devices'], 'sessions')));
        $this->assertSame(8000.0, (float) $out['channels_meta']['unclassified']);
        $this->assertSame(1, $out['channels_meta']['days_covered']);
        $this->assertSame(3, $out['channels_meta']['days_total']);
        $this->assertSame(3, $out['devices_meta']['days_covered']);
    }

    public function test_a_breakdown_that_exceeds_the_visits_is_rescaled_to_the_visits_with_whole_numbers(): void
    {
        $property = $this->property($this->project);
        $this->daily($property, '2026-10-05', ['sessions' => 36361]);
        $this->dimRows($property, $this->project, 'device', '2026-10-05', [['mobile', '', ['sessions' => 30000]], ['desktop', '', ['sessions' => 6000]], ['tablet', '', ['sessions' => 384]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $sessions = array_column($out['devices'], 'sessions');
        $this->assertSame(36361.0, (float) array_sum($sessions), 'the donut and the tile say the same number');
        foreach ($sessions as $s) {
            $this->assertSame((float) (int) $s, (float) $s);
        }
        $this->assertTrue($out['devices_meta']['rescaled']);
        $this->assertSame(0.0, (float) $out['devices_meta']['unclassified']);
        $this->assertNotContains('unclassified', array_column($out['devices'], 'key'));
    }

    public function test_the_funnel_reads_the_same_daily_figures_as_the_tiles(): void
    {
        $property = $this->property($this->project);
        $this->daily($property, '2026-10-05', ['sessions' => 1000, 'add_to_carts' => 90, 'checkouts' => 40, 'transactions' => 12]);
        // Device rows disagree on purpose (partial coverage): the funnel must not follow them.
        $this->dimRows($property, $this->project, 'device', '2026-10-05', [['mobile', '', ['sessions' => 400, 'add_to_carts' => 10, 'checkouts' => 5, 'purchases' => 2]]]);

        $out = $this->store('from=2026-10-01&to=2026-10-10');

        $tiles = collect($out['kpis'])->keyBy('key');
        $funnel = collect($out['funnel'])->keyBy('stage');
        $this->assertEquals($tiles['sessions']['current'], $funnel['sessions']['count']);
        $this->assertEquals($tiles['add_to_carts']['current'], $funnel['add_to_carts']['count']);
        $this->assertEquals($tiles['checkouts']['current'], $funnel['checkouts']['count']);
        $this->assertEquals($tiles['transactions']['current'], $funnel['purchases']['count']);
        $this->assertEquals([1000, 90, 40, 12], array_column($out['funnel'], 'count'));
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
