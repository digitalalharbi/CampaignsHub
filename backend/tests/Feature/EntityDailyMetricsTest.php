<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\SnapchatConnector;
use App\Domains\Metrics\Actions\UpsertEntityDailyMetrics;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * METRICS-BACKBONE-001 — the two rungs that had no metrics storage at all.
 *
 * The product could measure exactly two grains: `daily_metrics` by campaign and
 * `creative_daily_metrics` by creative. Between them sit 187 ad squads and 5,706 ads on the live
 * account with nowhere to put a number — which is why Analytics has no Ad Set tab and no Ads tab.
 * That was never a missing screen; it was a missing table.
 */
final class EntityDailyMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    /**
     * One internal id per provider id, held for the test's lifetime.
     *
     * A fresh uuid each call would make a re-sync of the same day address a different row — the
     * very thing the natural key exists to prevent, and it would hide a real doubling bug.
     *
     * @var array<string,string>
     */
    private array $entityIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            // The production shape: the client reports in SAR, the account spends USD.
            'default_currency' => 'SAR',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snap',
            'status' => 'active',
            'currency' => 'USD',
            // The connector refuses a window with no timezone, and is right to: a «day» without one
            // is ambiguous, and the live account reports on Asia/Riyadh.
            'timezone' => 'Asia/Riyadh',
            'discovered_at' => Carbon::now(),
        ]);
    }

    /** An ad squad's day is stored, with its money withheld rather than mislabelled. */
    public function test_an_ad_squad_day_is_stored_with_money_withheld(): void
    {
        $result = app(UpsertEntityDailyMetrics::class)->execute(
            $this->account,
            'ad_set',
            [[
                'entity_id' => 'sq-1', 'date' => '2026-08-01',
                'spend' => 412.5, 'impressions' => 90000, 'clicks' => 300, 'reach' => 45000, 'frequency' => 2.0,
            ]],
            $this->known('sq-1'),
        );

        $this->assertSame(['upserted' => 1, 'skipped' => 0], $result);

        $row = DB::table('entity_daily_metrics')->where('external_entity_id', 'sq-1')->first();

        $this->assertNull($row->spend, 'No USD→SAR rate exists, so the figure must be withheld, not wrong.');
        $this->assertEqualsWithDelta(412.5, (float) $row->spend_original, 0.01);
        $this->assertSame('USD', $row->original_currency);
        $this->assertSame('SAR', $row->project_currency);

        // Counts are not money and are never withheld.
        $this->assertEqualsWithDelta(90000, (float) $row->impressions, 0.01);
        $this->assertEqualsWithDelta(45000, (float) $row->reach, 0.01, 'Reach must be stored, never approximated.');
        $this->assertEqualsWithDelta(2.0, (float) $row->frequency, 0.01);
    }

    /** A metric the platform never sent stays NULL — «not reported» is not «none». */
    public function test_an_unreported_metric_is_null_rather_than_zero(): void
    {
        app(UpsertEntityDailyMetrics::class)->execute(
            $this->account,
            'ad_set',
            [['entity_id' => 'sq-1', 'date' => '2026-08-01', 'impressions' => 100]],
            $this->known('sq-1'),
        );

        $row = DB::table('entity_daily_metrics')->where('external_entity_id', 'sq-1')->first();

        $this->assertNull($row->leads, 'A zero here would claim the platform measured no leads.');
        $this->assertNull($row->installs);
        $this->assertNull($row->video_views);
    }

    /** An entity the structure sweep has not discovered is skipped, never invented. */
    public function test_an_undiscovered_entity_is_skipped(): void
    {
        $result = app(UpsertEntityDailyMetrics::class)->execute(
            $this->account,
            'ad_set',
            [['entity_id' => 'sq-unknown', 'date' => '2026-08-01', 'impressions' => 10]],
            $this->known('sq-1'),
        );

        $this->assertSame(['upserted' => 0, 'skipped' => 1], $result);
        $this->assertSame(0, DB::table('entity_daily_metrics')->count());
    }

    /** Re-syncing the same day corrects in place — attribution keeps moving for days. */
    public function test_the_same_day_is_corrected_not_doubled(): void
    {
        $rows = fn (float $impressions) => [[
            'entity_id' => 'sq-1', 'date' => '2026-08-01', 'impressions' => $impressions,
        ]];

        app(UpsertEntityDailyMetrics::class)->execute($this->account, 'ad_set', $rows(100), $this->known('sq-1'));
        app(UpsertEntityDailyMetrics::class)->execute($this->account, 'ad_set', $rows(140), $this->known('sq-1'));

        $this->assertSame(1, DB::table('entity_daily_metrics')->count());
        $this->assertEqualsWithDelta(
            140,
            (float) DB::table('entity_daily_metrics')->value('impressions'),
            0.01,
        );
    }

    /** Two attribution windows are two measurements of one day and must both survive. */
    public function test_two_attribution_windows_do_not_overwrite_each_other(): void
    {
        $upsert = app(UpsertEntityDailyMetrics::class);

        $upsert->execute($this->account, 'ad_set', [[
            'entity_id' => 'sq-1', 'date' => '2026-08-01', 'conversions' => 10, 'attribution_window' => 'swipe_28d',
        ]], $this->known('sq-1'));

        $upsert->execute($this->account, 'ad_set', [[
            'entity_id' => 'sq-1', 'date' => '2026-08-01', 'conversions' => 4, 'attribution_window' => 'swipe_1d',
        ]], $this->known('sq-1'));

        $this->assertSame(2, DB::table('entity_daily_metrics')->count(), 'Summing these would be a fabricated total.');
    }

    /**
     * The connector reads the provider's own shape — ad-squad breakdown, verified field names.
     *
     * `screen_time_millis` is milliseconds and the product reports watch time in seconds; the
     * conversion happens once, at this edge, so no reader has to remember it.
     */
    public function test_the_connector_maps_snapchats_own_field_names(): void
    {
        Http::fake([
            '*/campaigns/cmp-1/stats*' => Http::response(['timeseries_stats' => [
                ['timeseries_stat' => ['breakdown_stats' => ['adsquad' => [
                    ['id' => 'sq-1', 'timeseries' => [
                        ['start_time' => '2026-08-01T00:00:00.000-07:00', 'stats' => [
                            'spend' => 412_500_000,      // micro-units of the account currency
                            'impressions' => 90000,
                            'swipes' => 300,             // Snapchat calls a click a swipe
                            'uniques' => 45000,          // reach, reported — never approximated
                            'frequency' => 2.0,
                            'quartile_1' => 8000,
                            'view_completion' => 2500,
                            'screen_time_millis' => 60_000,
                            'native_leads' => 12,
                            'total_installs' => 5,
                        ]],
                    ]],
                ]]]],
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $rows = $this->connector()->fetchEntityInsights(
            new OAuthTokens('AT', 'RT', Carbon::now()->addDay()),
            'act-1',
            'campaigns',
            'adsquad',
            ['cmp-1'],
            '2026-08-01',
            '2026-08-01',
        );

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('sq-1', $row['entity_id']);
        $this->assertEqualsWithDelta(412.5, $row['spend'], 0.01, 'Micro-units are divided once, at the edge.');
        $this->assertEqualsWithDelta(300, $row['clicks'], 0.01);
        $this->assertEqualsWithDelta(45000, $row['reach'], 0.01);
        $this->assertEqualsWithDelta(8000, $row['video_p25'], 0.01);
        $this->assertEqualsWithDelta(2500, $row['video_p100'], 0.01);
        $this->assertEqualsWithDelta(60, $row['video_watch_seconds'], 0.01, 'Milliseconds became seconds.');
        $this->assertEqualsWithDelta(12, $row['leads'], 0.01);
        $this->assertEqualsWithDelta(5, $row['installs'], 0.01);

        // Absent from the response, so absent from the row — not present and zero.
        $this->assertArrayNotHasKey('sign_ups', $row);
    }

    /** One parent failing costs its own children, not the whole sweep. */
    public function test_a_failing_parent_does_not_lose_the_others(): void
    {
        Http::fake([
            '*/campaigns/cmp-bad/stats*' => Http::response(['error' => 'rate limited'], 429),
            '*/campaigns/cmp-1/stats*' => Http::response(['timeseries_stats' => [
                ['timeseries_stat' => ['breakdown_stats' => ['adsquad' => [
                    ['id' => 'sq-1', 'timeseries' => [
                        ['start_time' => '2026-08-01T00:00:00.000-07:00', 'stats' => ['impressions' => 5]],
                    ]],
                ]]]],
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $rows = $this->connector()->fetchEntityInsights(
            new OAuthTokens('AT', 'RT', Carbon::now()->addDay()),
            'act-1',
            'campaigns',
            'adsquad',
            ['cmp-bad', 'cmp-1'],
            '2026-08-01',
            '2026-08-01',
        );

        $this->assertCount(1, $rows, 'The healthy campaign’s ad squad must still be reported.');
        $this->assertSame('sq-1', $rows[0]['entity_id']);
    }

    /**
     * A connector bound to the connection, as the real sync uses it.
     *
     * `accountTimezone()` reads the account through the CONNECTION, and returns null without one —
     * which makes `ReportingWindow` refuse the window. It is right to refuse: a «day» with no
     * timezone is ambiguous, and this product reports on the account's own local days.
     */
    private function connector(): SnapchatConnector
    {
        return app(SnapchatConnector::class)->withConnection($this->account->connection);
    }

    /** @return array<string, array{id:string,project_id:string,tenant_id:string,campaign_id:?string,ad_set_id:?string}> */
    private function known(string $providerId): array
    {
        return [$providerId => [
            // Stable per provider id: a fresh uuid each call would make a re-sync of the same day
            // address a different row, which is the very thing the natural key exists to prevent.
            'id' => $this->entityIds[$providerId] ??= (string) Str::uuid(),
            'project_id' => $this->project->id,
            'tenant_id' => $this->tenant->id,
            'campaign_id' => null,
            'ad_set_id' => null,
        ]];
    }
}
