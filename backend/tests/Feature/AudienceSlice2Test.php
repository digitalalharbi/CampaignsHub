<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ReportsAudienceBreakdowns;
use App\Domains\Integrations\Providers\SnapchatConnector;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Audience\AudienceBreakdownSync;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUDIENCE-001 slice 2 — Snapchat's full location targeting and region delivery, TikTok's audience report, the file
 * export, and the client portal's «الجمهور» under the performance dashboard.
 *
 * Fixture bodies follow the documented response shapes; no live provider round trip is proven here.
 */
final class AudienceSlice2Test extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    // ------------------------------------------------------------------ Snapchat targeting

    public function test_snapchat_targeting_keeps_regions_exclusions_postal_codes_and_radius_and_names_regions(): void
    {
        Http::fake(['*/targeting/geo/sa/region*' => Http::response(['request_status' => 'SUCCESS', 'targeting_dimensions' => [
            ['sub_request_status' => 'SUCCESS', 'region' => ['region' => ['id' => '7', 'code' => 'NJ', 'name' => 'Najran']]],
        ]])]);
        $connector = app(SnapchatConnector::class);
        $read = (new \ReflectionMethod($connector, 'readableTargeting'))->invoke($connector, [
            'geos' => [
                ['country_code' => 'sa', 'region_id' => ['7', '99'], 'postal_code' => ['11564'], 'operation' => 'INCLUDE'],
                ['country_code' => 'ae', 'operation' => 'EXCLUDE'],
                ['country_code' => 'sa', 'region_id' => ['3'], 'operation' => 'EXCLUDE'],
            ],
            'locations' => [['circles' => [['latitude' => 17.5, 'longitude' => 44.2, 'radius' => 5, 'unit' => 'KILOMETERS']], 'operation' => 'INCLUDE']],
            'demographics' => [['min_age' => 18]],
        ], new OAuthTokens('AT', 'RT', Carbon::now()->addDay()));

        $this->assertSame(['sa'], $read['countries'], 'an excluded country is never a targeted one');
        $this->assertSame(['Najran', '99'], $read['regions'], 'a name Snapchat gave, the id where it gave none');
        $this->assertSame(1, $read['postal_codes']);
        $this->assertSame([5000], $read['radius_m']);
        $this->assertSame(['ae'], $read['excluded_countries'], 'excluding regions of a country is not excluding the country');
        $this->assertSame(['3'], $read['excluded_regions']);
        $this->assertStringNotContainsString('17.5', (string) json_encode($read), 'circle centres are not kept');
    }

    public function test_snapchat_region_names_failing_leaves_the_ids(): void
    {
        Http::fake(['*' => Http::response(['request_status' => 'ERROR'], 500)]);
        $connector = app(SnapchatConnector::class);
        $read = (new \ReflectionMethod($connector, 'readableTargeting'))->invoke($connector, ['geos' => [['country_code' => 'sa', 'region_id' => ['7']]]], new OAuthTokens('AT', 'RT', Carbon::now()->addDay()));
        $this->assertSame(['7'], $read['regions']);
    }

    // ------------------------------------------------------------------ provider rows

    public function test_snapchat_region_stats_map_the_documented_total_shape(): void
    {
        $rows = SnapchatConnector::mapAudienceStats(['total_stats' => [['total_stat' => ['granularity' => 'TOTAL', 'dimension_stats' => [
            ['region' => 'Najran', 'impressions' => 1200, 'swipes' => 30, 'spend' => 4_500_000],
            ['region' => 'Makkah', 'impressions' => 40],
            ['impressions' => 9],
        ]]]]], 'sq1', '2026-11-12', 'region');

        $this->assertCount(2, $rows);
        $this->assertSame(['entity_id' => 'sq1', 'campaign_id' => null, 'date' => '2026-11-12', 'value' => 'Najran', 'impressions' => 1200.0, 'clicks' => 30.0, 'spend' => 4.5], $rows[0]);
        $this->assertArrayNotHasKey('spend', $rows[1], 'not reported is absent, never zero');
    }

    public function test_snapchat_asks_only_squads_that_delivered_and_caps_the_sweep(): void
    {
        $connector = app(SnapchatConnector::class);
        $this->assertSame(['region'], $connector->audienceDimensions());
        $refused = $connector->audienceBreakdown('acc', 'age', '2026-11-12', '2026-11-12', ['sq1' => ['2026-11-12']]);
        $this->assertFalse($refused->success, 'a dimension this product does not ask is refused, not invented');
        $none = $connector->audienceBreakdown('acc', 'region', '2026-11-12', '2026-11-12', []);
        $this->assertTrue($none->success);
        $this->assertSame([], $none->records, 'no squad delivered: nothing is asked');
    }

    public function test_tiktok_audience_rows_keep_ids_and_absent_measures(): void
    {
        $rows = TikTokConnector::mapAudienceRows([
            ['dimensions' => ['adgroup_id' => 'g1', 'stat_time_day' => '2026-11-12 00:00:00', 'province_id' => '103630'], 'metrics' => ['impressions' => '500', 'spend' => '12.5']],
            ['dimensions' => ['adgroup_id' => 'g1', 'stat_time_day' => '2026-11-12 00:00:00', 'province_id' => '']],
        ], 'province_id');

        $this->assertCount(1, $rows);
        $this->assertSame('103630', $rows[0]['value'], 'a province is TikTok’s id, never a guessed name');
        $this->assertSame('2026-11-12', $rows[0]['date']);
        $this->assertArrayNotHasKey('clicks', $rows[0]);
        $this->assertSame(['age', 'gender', 'region'], app(TikTokConnector::class)->audienceDimensions());
    }

    // ------------------------------------------------------------------ sync hands the delivering squads

    public function test_the_sync_hands_a_per_squad_provider_only_the_squads_and_days_that_delivered_in_its_project(): void
    {
        [$tenant, $project, $account, $set, $other] = $this->world();
        DB::table('entity_daily_metrics')->insert([
            $this->entityDay($tenant, $project, $account, $set, '2026-11-12', 50),
            $this->entityDay($tenant, $project, $account, $set, '2026-11-11', 0),
            $this->entityDay($tenant, $other['project'], $account, $other['set'], '2026-11-12', 70),
        ]);
        $connector = new class implements ReportsAudienceBreakdowns
        {
            /** @var array<string, list<string>> */
            public array $asked = [];

            public function audienceDimensions(): array
            {
                return ['region'];
            }

            public function audienceSourceField(string $dimension): string
            {
                return 't';
            }

            public function audienceBreakdown(string $adAccountId, string $dimension, string $from, string $to, array $delivering = []): SyncResult
            {
                $this->asked = $delivering;

                return SyncResult::of([]);
            }
        };
        $run = MetricSyncRun::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => 'snapchat', 'status' => 'running', 'window_start' => '2026-11-11', 'window_end' => '2026-11-12']);

        app(AudienceBreakdownSync::class)->sync($connector, $account, $run, Carbon::parse('2026-11-11'), Carbon::parse('2026-11-12'));

        $this->assertSame(['sq-1' => ['2026-11-12']], $connector->asked, 'only delivering days, only this project');
    }

    // ------------------------------------------------------------------ file export

    public function test_the_file_tables_carry_numerator_and_denominator_and_only_the_parts_present(): void
    {
        $tables = (new \ReflectionMethod(ReportExporter::class, 'audienceTables'))->invoke(null, [
            'audience_regions' => ['groups' => [['provider' => 'meta', 'dimension' => 'region', 'source_field' => 'meta:x', 'rows' => [
                ['value' => 'Najran Province', 'impressions' => 300.0, 'share' => ['numerator' => 300.0, 'denominator' => 400.0, 'ratio' => 0.75], 'clicks' => null],
            ]]]],
        ]);
        $this->assertCount(1, $tables, 'targeting and site are absent: not written');
        $this->assertSame('Audience delivery', $tables[0]['sheet']);
        $this->assertSame(['meta', 'region', 'Najran Province', 300.0, 300.0, 400.0, 0.75, '', 'meta:x'], $tables[0]['rows'][0], 'a measure not reported is an empty cell');
        $this->assertStringNotContainsString('Spend', (string) json_encode($tables[0]['head']), 'no spend column where the part carries none');
        $this->assertSame([], (new \ReflectionMethod(ReportExporter::class, 'audienceTables'))->invoke(null, []));
    }

    // ------------------------------------------------------------------ client portal

    public function test_the_portal_audience_is_off_until_the_agency_switches_it_on_and_then_has_no_money_or_names(): void
    {
        $this->seed(RequestCatalogSeeder::class);
        $agency = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($agency->id);
        $workspace = ClientWorkspace::create(['tenant_id' => $agency->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'settings' => []]);
        $project = Project::create(['tenant_id' => $agency->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        ExternalRequest::create([
            'tenant_id' => $agency->id, 'reference' => 'REQ-2026-'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id, 'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client', 'contact_email' => 'c@x.test', 'contact_phone' => '+966500000001',
            'client_id' => $workspace->id, 'journey_stage' => 'in_progress', 'submitted_at' => now(),
        ]);
        $account = $this->account($agency, $project, 'meta');
        $set = $this->adSet($agency, $project, $account, 'meta', 'set-p', 'SECRET_ADSET', ['cities' => ['Najran']]);
        $this->row($agency, $project, $account, $set, 'Najran Province', 300, 30.0);
        // Another client of the same agency, audience on, with a region nobody else may read.
        $other = ClientWorkspace::create(['tenant_id' => $agency->id, 'name' => 'O', 'slug' => 'o-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'settings' => ['portal' => ['visibility' => ['audience' => true]]]]);
        $otherProject = Project::create(['tenant_id' => $agency->id, 'client_workspace_id' => $other->id, 'name' => 'OP', 'status' => 'active']);
        $otherAccount = $this->account($agency, $otherProject, 'meta');
        $this->row($agency, $otherProject, $otherAccount, $this->adSet($agency, $otherProject, $otherAccount, 'meta', 'set-o', 'O', null), 'OTHER_CLIENT_REGION', 50, 5.0);
        app(TenantContext::class)->forget();

        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'c@x.test'])->assertCreated();
        $verify = $this->postJson('/api/v1/client/login/verify', ['verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code')])->assertOk();
        $headers = ['X-Client-Token' => $verify->json('data.dev_token')];

        $off = $this->getJson('/api/v1/client/audience?from=2026-11-12&to=2026-11-12', $headers);
        $off->assertStatus(403);
        $this->assertStringNotContainsString('Najran', $off->getContent());

        $workspace->forceFill(['settings' => ['portal' => ['visibility' => ['audience' => true]]]])->save();
        $on = $this->getJson('/api/v1/client/audience?from=2026-11-12&to=2026-11-12', $headers)->assertOk();
        $body = $on->getContent();
        $this->assertStringContainsString('Najran Province', $body);
        $this->assertStringNotContainsString('"spend"', $body, 'a client never reads money here');
        $this->assertStringNotContainsString('SECRET_ADSET', $body);
        $this->assertStringNotContainsString('CAMP_', $body);
        $this->assertStringNotContainsString((string) $set, $body, 'no internal ids');
        $this->assertNull($on->json('data.projects.0.reading.capabilities'));
        $this->assertStringNotContainsString('OTHER_CLIENT_REGION', $body, 'another client of the same agency is never read');
        $this->assertCount(1, $on->json('data.projects'));

        $workspace->forceFill(['settings' => ['portal' => ['visibility' => ['audience' => true, 'dashboard' => false]]]])->save();
        $this->getJson('/api/v1/client/audience', $headers)->assertStatus(403);
    }

    public function test_the_probe_prints_a_meta_breakdown_and_writes_nothing(): void
    {
        [$tenant, $project] = $this->world();
        $meta = $this->account($tenant, $project, 'meta');
        $set = $this->adSet($tenant, $project, $meta, 'meta', '777', 'M1', null);
        Http::fake(['*/insights*' => Http::response(['data' => [
            ['adset_id' => '777', 'campaign_id' => 'c', 'date_start' => '2026-11-12', 'region' => 'Najran Province', 'impressions' => '300', 'clicks' => '6', 'spend' => '30', 'account_currency' => 'SAR'],
            ['adset_id' => '777', 'campaign_id' => 'c', 'date_start' => '2026-11-12', 'region' => 'Makkah Province', 'impressions' => '100'],
        ]])]);

        $this->artisan('audience:breakdown-probe', ['account' => (string) $meta->id, '--project' => (string) $project->id, '--from' => '2026-11-12'])
            ->expectsOutputToContain('dry run, nothing stored')
            ->expectsOutputToContain('Najran Province | ad sets 1 | impressions 300 | clicks 6')
            ->expectsOutputToContain('Makkah Province | ad sets 1 | impressions 100 | clicks not reported')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('audience_breakdown_rows')->count(), 'a probe never writes');
        $this->assertNotSame('', $set);

        $this->artisan('audience:breakdown-probe', ['account' => (string) $meta->id, '--project' => (string) Str::uuid(), '--from' => '2026-11-12'])
            ->expectsOutputToContain('not actively bound')->assertFailed();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: Tenant, 1: Project, 2: ExternalAccount, 3: string, 4: array{project: Project, set: string}} */
    private function world(): array
    {
        $tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $sibling = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $account = $this->account($tenant, $project, 'snapchat');
        $set = $this->adSet($tenant, $project, $account, 'snapchat', 'sq-1', 'S1', null);
        $otherSet = $this->adSet($tenant, $sibling, $account, 'snapchat', 'sq-2', 'S2', null);

        return [$tenant, $project, $account, $set, ['project' => $sibling, 'set' => $otherSet]];
    }

    private function account(Tenant $tenant, Project $project, string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Ads '.uniqid());
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'ad-'.uniqid(), 'name' => 'Ad', 'status' => 'active',
            'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param array<string, mixed>|null $targeting */
    private function adSet(Tenant $tenant, Project $project, ExternalAccount $account, string $provider, string $external, string $name, ?array $targeting): string
    {
        $base = ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        DB::table('external_campaigns')->insert($base + ['id' => $campaign, 'client_workspace_id' => $project->client_workspace_id, 'external_account_id' => $account->id, 'provider' => $provider, 'external_id' => 'c-'.$external, 'name' => 'CAMP_'.$name, 'status' => 'active']);
        $id = (string) Str::uuid();
        DB::table('external_ad_sets')->insert($base + ['id' => $id, 'external_campaign_id' => $campaign, 'provider' => $provider, 'external_id' => $external, 'name' => $name, 'status' => 'active', 'targeting' => $targeting === null ? null : json_encode($targeting)]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function entityDay(Tenant $tenant, Project $project, ExternalAccount $account, string $set, string $date, float $impressions): array
    {
        return [
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => (string) $account->provider, 'entity_type' => 'ad_set', 'entity_id' => $set, 'external_entity_id' => 'x',
            'metric_date' => $date, 'impressions' => $impressions, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function row(Tenant $tenant, Project $project, ExternalAccount $account, string $set, string $value, float $imp, float $spend): void
    {
        DB::table('audience_breakdown_rows')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => (string) $account->provider, 'dimension' => 'region', 'value' => $value, 'source_field' => 'meta:test',
            'external_campaign_id' => DB::table('external_ad_sets')->where('id', $set)->value('external_campaign_id'), 'external_ad_set_id' => $set,
            'external_entity_id' => 'x', 'metric_date' => '2026-11-12', 'impressions' => $imp, 'spend_original' => $spend, 'original_currency' => 'SAR',
            'currency_source' => 'account', 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
