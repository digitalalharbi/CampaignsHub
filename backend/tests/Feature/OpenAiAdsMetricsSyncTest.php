<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-OPENAI-001 §18 — the figures, and the one distinction the whole product rests on.
 *
 * «The provider did not report conversions» and «the provider reported no conversions» are different
 * answers. Everything downstream — the availability states on a card, the «لم تُرسل المنصة هذا
 * المؤشر» note on a report, the decision not to put a zero in front of a client — is built on the
 * first never being written as the second.
 *
 * `OpenAiAdsConnectorTest` proves the connector does not collapse them. This proves the PERSISTENCE
 * does not, which is a different claim: a row written with `conversions = 0` because the column is
 * not nullable is the same lie arriving by another route.
 */
final class OpenAiAdsMetricsSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acc', 'slug' => 'acc-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'openai_ads',
            'account_type' => 'ad_account',
            'external_id' => 'acct_live_1',
            'name' => 'Acme Riyadh',
            'currency' => 'SAR',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    /** The figures the provider did send arrive, in the account's own currency. */
    public function test_reported_figures_persist(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();

        $rows = DB::table('daily_metrics')->where('metric_date', '2026-09-01')->get();

        $this->assertGreaterThan(0, $rows->count(), 'nothing persisted for a day the provider reported');

        $byKey = $rows->keyBy('metric_key');
        $this->assertEqualsWithDelta(120.5, (float) $byKey['spend']->value, 0.001);
        $this->assertEqualsWithDelta(10000, (float) $byKey['impressions']->value, 0.001);
        $this->assertEqualsWithDelta(250, (float) $byKey['clicks']->value, 0.001);
        $this->assertEqualsWithDelta(12, (float) $byKey['conversions']->value, 0.001);
    }

    /**
     * **A figure the provider never sent has no row.**
     *
     * Not a row holding zero. The absence IS the answer, and a surface reading these rows must be
     * able to tell «we were not told» from «it was nothing» — which it can only do if nothing was
     * written.
     */
    public function test_a_figure_the_provider_never_sent_has_no_row(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 90.0, 'impressions' => 4000, 'clicks' => 60,
            // No conversions key at all, and revenue explicitly null.
            'revenue' => null,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();

        $keys = DB::table('daily_metrics')->where('metric_date', '2026-09-01')->pluck('metric_key')->all();

        $this->assertContains('spend', $keys);
        $this->assertNotContains('conversions', $keys, 'an unreported conversion count was written as a figure');
        $this->assertNotContains('revenue', $keys, 'an unreported revenue was written as a figure');
    }

    /** And a zero the provider DID report is kept, because it is an answer. */
    public function test_a_reported_zero_is_kept(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 90.0, 'impressions' => 4000, 'clicks' => 60, 'conversions' => 0,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();

        $row = DB::table('daily_metrics')->where('metric_date', '2026-09-01')->where('metric_key', 'conversions')->first();

        $this->assertNotNull($row, 'a real zero was dropped, which reads as «not reported»');
        $this->assertEqualsWithDelta(0.0, (float) $row->value, 0.001);
    }

    /**
     * **The same window twice is the same figures.**
     *
     * A metrics sync re-runs constantly — a sweep, a backfill, a reconnect. A second run that
     * inserted rather than updated would double every figure on the dashboard with nothing anywhere
     * saying why.
     */
    public function test_a_second_sync_of_the_same_window_changes_nothing(): void
    {
        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12,
        ]);

        $this->runStructureSync();
        $this->runMetricsSync();
        $first = DB::table('daily_metrics')->orderBy('metric_key')->get(['metric_key', 'value'])->toArray();

        // Not vacuous: two empty sets are also «equal», and this test passed that way while the
        // sync was persisting nothing at all.
        $this->assertNotSame([], $first, 'the first sync persisted nothing, so this proves nothing');

        $this->providerReports([
            'date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12,
        ]);
        $this->runStructureSync();
        $this->runMetricsSync();

        $second = DB::table('daily_metrics')->orderBy('metric_key')->get(['metric_key', 'value'])->toArray();

        $this->assertEquals($first, $second, 'a repeated sync changed the figures');
    }

    /**
     * Structure first, exactly as production does it.
     *
     * An insights row names a campaign, and a figure whose campaign this product has never heard of
     * has nowhere to hang. The sweep syncs structure before metrics for the same reason.
     */
    /**
     * OPENAI-ADS-INSIGHTS-001 — the Production account, end to end: Ads Manager showed, for
     * Sep 29 – Oct 12 2026, spend $556.11, 54,693 impressions, 4,607 clicks and 9 conversions, and
     * every sync answered «no data». The same totals, split across two campaigns, fourteen days and
     * two pages, must land exactly — and the request must be the documented one.
     */
    public function test_a_backfill_lands_the_ads_manager_totals_through_the_documented_request(): void
    {
        $days = [];
        for ($d = Carbon::parse('2026-09-29'); $d->lte(Carbon::parse('2026-10-12')); $d->addDay()) {
            $days[] = $d->toDateString();
        }
        // 2 campaigns × 14 days = 28 rows; totals built to match Ads Manager to the cent.
        $rows = [];
        foreach ($days as $i => $day) {
            foreach (['cmp_1', 'cmp_2'] as $j => $cmp) {
                $rows[] = ['id' => "r{$i}{$j}", 'readable_time' => $day, 'campaign_id' => $cmp,
                    'impressions' => 1953, 'clicks' => 164, 'spend' => 19.86];
            }
        }
        // Adjust the last row so the sums are exactly 54,693 / 4,607 / 556.11.
        $rows[27]['impressions'] = 54693 - 1953 * 27;
        $rows[27]['clicks'] = 4607 - 164 * 27;
        $rows[27]['spend'] = round(556.11 - 19.86 * 27, 2);

        $asked = [];
        Http::fake(function ($request) use ($rows, &$asked) {
            $url = $request->url();
            if (str_contains($url, '/conversions/insights')) {
                $asked[] = ['POST', $url, $request->data()];

                return Http::response(['object' => 'list', 'data' => [
                    ['entity_id' => 'cmp_1', 'date' => '2026-10-01', 'conversions' => 5],
                    ['entity_id' => 'cmp_2', 'date' => '2026-10-05', 'conversions' => 4],
                ], 'count' => 2]);
            }
            if (str_contains($url, '/ad_account/insights') && ! str_contains($url, 'aggregation_level=campaign')) {
                // The ad-group and ad grains: this fixture reports campaigns only.
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights')) {
                $asked[] = ['GET', urldecode($url)];
                $second = str_contains($url, 'after=');

                return Http::response(['object' => 'list', 'data' => $second ? array_slice($rows, 20) : array_slice($rows, 0, 20),
                    'has_more' => ! $second, 'last_id' => $second ? 'r131' : 'r91']);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch'], ['id' => 'cmp_2', 'name' => 'Retarget']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'USD']);
        });

        $this->runStructureSync();
        app()->call([new SyncAccountMetricsJob($this->account->id, '2026-09-27', '2026-10-12'), 'handle']);

        // The documented request: account scope, campaign rows, daily, an inclusive date range, named fields.
        $get = collect($asked)->firstWhere(0, 'GET')[1];
        $this->assertStringContainsString('/ad_account/insights?', $get);
        foreach (['aggregation_level=campaign', 'time_granularity=daily', 'fields[]=campaign.spend', 'fields[]=campaign.clicks', 'fields[]=campaign.impressions', 'fields[]=metadata.readable_time', 'time_ranges[]={"type":"date_range","since":"2026-09-27","until":"2026-10-12"}'] as $part) {
            $this->assertStringContainsString($part, $get);
        }
        $this->assertStringNotContainsString('start_date', $get, 'a parameter the API does not take');
        // The campaign read pages to the end (the ad-group and ad grains are read the same way, separately).
        $this->assertCount(2, collect($asked)->filter(fn ($a) => $a[0] === 'GET' && str_contains($a[1], 'aggregation_level=campaign')), 'the second page was not read');
        $post = collect($asked)->firstWhere(0, 'POST')[2];
        $this->assertSame('campaign', $post['aggregation_level']);
        $this->assertEqualsCanonicalizing(['cmp_1', 'cmp_2'], $post['entity_ids']);

        $totals = DB::table('daily_metrics')->whereBetween('metric_date', ['2026-09-27', '2026-10-12'])
            ->selectRaw('metric_key, SUM(value) AS total')->groupBy('metric_key')->pluck('total', 'metric_key');
        $this->assertEqualsWithDelta(556.11, (float) $totals['spend'], 0.001);
        $this->assertEqualsWithDelta(54693, (float) $totals['impressions'], 0.001);
        $this->assertEqualsWithDelta(4607, (float) $totals['clicks'], 0.001);
        $this->assertEqualsWithDelta(9, (float) $totals['conversions'], 0.001);
        $this->assertSame('success', DB::table('metric_sync_runs')->latest('created_at')->value('status'));
    }

    /** Rows that carry no day are a response this reader does not understand — never «no data». */
    public function test_rows_without_a_day_fail_loudly_instead_of_reading_as_no_data(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/ad_account/insights') && ! str_contains($url, 'aggregation_level=campaign')) {
                // The ad-group and ad grains: this fixture reports campaigns only.
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights')) {
                return Http::response(['object' => 'list', 'data' => [['id' => 'x', 'campaign_id' => 'cmp_1', 'impressions' => 10, 'date_start' => '2026-09-01']], 'has_more' => false]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'USD']);
        });

        $this->runStructureSync();
        $this->runMetricsSync();

        $run = DB::table('metric_sync_runs')->latest('created_at')->first();
        $this->assertSame('failed', $run->status, 'an unreadable answer was stored as «no data»');
        $this->assertStringContainsString('row keys', (string) $run->error);
    }

    /** A refused conversions read costs the conversion column only, and the run says so. */
    public function test_a_refused_conversion_read_keeps_the_delivery_figures_and_is_partial(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/conversions/insights')) {
                return Http::response(['error' => ['message' => 'Unsupported reporting window']], 400);
            }
            if (str_contains($url, '/ad_account/insights') && ! str_contains($url, 'aggregation_level=campaign')) {
                // The ad-group and ad grains: this fixture reports campaigns only.
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights')) {
                return Http::response(['object' => 'list', 'data' => [['id' => 'r', 'readable_time' => '2026-09-01', 'campaign_id' => 'cmp_1', 'impressions' => 100, 'clicks' => 5, 'spend' => 3.5]], 'has_more' => false]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'USD']);
        });

        $this->runStructureSync();
        $this->runMetricsSync();

        $run = DB::table('metric_sync_runs')->latest('created_at')->first();
        $this->assertSame('partial_mapping', $run->status);
        $this->assertStringContainsString('conversions', (string) $run->error);
        $this->assertEqualsWithDelta(3.5, (float) DB::table('daily_metrics')->where('metric_key', 'spend')->value('value'), 0.001);
    }

    /**
     * The ad-group and ad grains land against the structure the sweep discovered.
     *
     * Production, 2026-10-12 (diagnostics run 38271521740): «11 ad-set row(s) came back and none
     * matched a discovered ad-set» and «60 ad row(s) came back and none matched a discovered ad» —
     * on an account whose ad groups and ads HAD been discovered. The ids were right. The connector
     * built its rows under `entity_external_id` while the contract and the upsert read `entity_id`,
     * so every row resolved to nobody and the run blamed discovery for a key the upsert never read.
     * This drives the whole chain — structure sync, then the metrics sync — and reads the stored rows.
     */
    public function test_ad_group_and_ad_figures_land_against_the_discovered_structure(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/conversions/insights')) {
                return Http::response(['object' => 'list', 'data' => [], 'count' => 0]);
            }
            if (str_contains($url, '/ad_account/insights')) {
                $level = str_contains($url, 'aggregation_level=ad_group') ? 'ad_group'
                    : (str_contains($url, 'aggregation_level=campaign') ? 'campaign' : 'ad');
                $row = ['id' => "r-{$level}", 'readable_time' => '2026-09-01', 'impressions' => 3000, 'clicks' => 90, 'spend' => 40];
                $row += match ($level) {
                    'campaign' => ['campaign_id' => 'cmp_1'],
                    'ad_group' => ['ad_group_id' => 'grp_1'],
                    default => ['ad_id' => 'ad_1'],
                };

                return Http::response(['object' => 'list', 'data' => [$row], 'has_more' => false, 'last_id' => $row['id']]);
            }
            if (str_contains($url, '/ad_groups')) {
                return Http::response(['object' => 'list', 'data' => [['id' => 'grp_1', 'name' => 'Riyadh', 'campaign_id' => 'cmp_1', 'status' => 'active']], 'has_more' => false]);
            }
            if (str_contains($url, '/ads')) {
                return Http::response(['object' => 'list', 'data' => [['id' => 'ad_1', 'name' => 'Hero', 'ad_group_id' => 'grp_1', 'status' => 'active']], 'has_more' => false]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'SAR']);
        });

        $this->runStructureSync();
        $this->assertSame(1, DB::table('external_ad_sets')->where('external_id', 'grp_1')->count(), 'the ad group was not discovered — the fixture, not the grain, is wrong');
        $this->assertSame(1, DB::table('external_ads')->where('external_id', 'ad_1')->count(), 'the ad was not discovered — the fixture, not the grain, is wrong');

        $this->runMetricsSync();

        $adSet = DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->first();
        $this->assertNotNull($adSet, 'the ad-group row came back from the provider and was stored against nothing');
        $this->assertSame('grp_1', $adSet->external_entity_id);
        $this->assertEqualsWithDelta(40.0, (float) $adSet->spend_original, 0.001);
        $this->assertSame('SAR', $adSet->original_currency);
        $this->assertEquals(3000, (int) $adSet->impressions);

        $ad = DB::table('entity_daily_metrics')->where('entity_type', 'ad')->first();
        $this->assertNotNull($ad, 'the ad row came back from the provider and was stored against nothing');
        $this->assertSame('ad_1', $ad->external_entity_id);
        $this->assertEquals(90, (int) $ad->clicks);

        $run = DB::table('metric_sync_runs')->latest('created_at')->first();
        $meta = json_decode((string) $run->meta, true) ?? [];
        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame(0, (int) ($meta['entity_ad_sets_skipped'] ?? -1));
        $this->assertSame(0, (int) ($meta['entity_ads_skipped'] ?? -1));
        $this->assertNull($meta['entity_failure'] ?? null);
    }

    private function runStructureSync(): void
    {
        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);
    }

    private function runMetricsSync(): void
    {
        app()->call([
            new SyncAccountMetricsJob($this->account->id, '2026-09-01', '2026-09-07'),
            'handle',
        ]);
    }

    /** @param array<string, mixed> $row */
    private function providerReports(array $row): void
    {
        Http::fake(function ($request) use ($row) {
            $url = $request->url();

            /*
             * OPENAI-ADS-INSIGHTS-001 — the documented shapes: delivery rows from
             * `GET /ad_account/insights` name their day `readable_time` and their campaign
             * `campaign_id`; conversions come from `POST /conversions/insights` as `entity_id` + `date`.
             */
            if (str_contains($url, '/conversions/insights')) {
                $conv = array_filter([
                    'conversions' => $row['conversions'] ?? null,
                    'order_created_attributed_sales' => isset($row['revenue']) ? (string) $row['revenue'] : null,
                ], static fn ($v) => $v !== null);

                return Http::response(['object' => 'list', 'data' => $conv === [] ? [] : [['entity_id' => 'cmp_1', 'date' => $row['date']] + $conv], 'count' => $conv === [] ? 0 : 1]);
            }

            if (str_contains($url, '/ad_account/insights') && ! str_contains($url, 'aggregation_level=campaign')) {
                // The ad-group and ad grains: this fixture reports campaigns only.
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights')) {
                $delivery = array_diff_key($row, array_flip(['date', 'conversions', 'revenue']));

                return Http::response(['object' => 'list', 'data' => [['id' => 'r1', 'readable_time' => $row['date'], 'campaign_id' => 'cmp_1'] + $delivery], 'has_more' => false, 'last_id' => 'r1']);
            }

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'name' => 'Acme Riyadh', 'currency' => 'SAR']);
        });
    }
}
