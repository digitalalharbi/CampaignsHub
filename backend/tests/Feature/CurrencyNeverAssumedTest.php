<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Actions\ImportStoreData;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\OpenAiAdsConnector;
use App\Domains\Integrations\Services\AccountCurrencyLookup;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Actions\UpsertEntityDailyMetrics;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\ValueObjects\MoneyScope;
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
 * MONEY-CURRENCY-NEVER-ASSUMED-001 — an unknown currency is never taken to be the reporting currency.
 *
 * Owner, 2026-11-05: «عمم حل المشكلة لكل النظام لكي لاتكرر، تحويل عملة اي منصة الى الدولار بدل العملات
 * الاخرى! سوا بالريال الى دولار او اليورو الى الدولار».
 *
 * OPENAI-ADS-CURRENCY-001 fixed one key in one connector. What made that key a money defect was a fallback
 * shared by every ingest path: «row currency, else account currency, else the reporting currency». The
 * third step stored a riyal spend as dollars at rate 1.0 and said nothing. These tests hold the rule that
 * replaces it, on every path that had it: row, else account, else ask the provider once and record the
 * answer, else WITHHOLD — amount kept, no converted value, no currency claimed, and a run that says so.
 */
final class CurrencyNeverAssumedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private ProviderConnection $connection;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);

        $this->tenant = Tenant::create(['name' => 'Acc', 'slug' => 'acc-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        // The state that made the incident: discovered, bound, and with NO currency.
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection->getKey(),
            'provider' => 'openai_ads',
            'account_type' => 'ad_account',
            'external_id' => 'adacct_123',
            'name' => 'Riyadh store',
            'currency' => null,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    // ── campaign grain, through the real sync ─────────────────────────────────────────────────

    /** Nobody states a currency: the money is withheld, the counts are stored, and the run says why. */
    public function test_money_nobody_stated_a_currency_for_is_withheld_and_the_run_says_so(): void
    {
        $this->providerReports(245.07, accountCurrency: null);

        $this->sync();

        $spend = DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->first();
        $this->assertNotNull($spend, 'the figure is kept, not dropped');
        $this->assertNull($spend->value, 'a figure in an unknown unit must not be stored as dollars');
        $this->assertEqualsWithDelta(245.07, (float) $spend->original_amount, 0.001);
        $this->assertNull($spend->original_currency, 'no currency may be claimed for it — not even the reporting one');
        $this->assertNull($spend->exchange_rate);

        $impressions = DB::table('daily_metrics')->where('metric_key', 'impressions')->where('metric_date', '2026-11-02')->first();
        $this->assertEqualsWithDelta(9000, (float) $impressions->value, 0.001, 'counts are not money and are never withheld');

        $run = MetricSyncRun::withoutGlobalScopes()->latest('started_at')->firstOrFail();
        $this->assertSame('partial_mapping', $run->status->value ?? $run->status);
        $this->assertSame(1, (int) ($run->meta['currency_unknown_rows'] ?? 0));
        $this->assertSame('not_stated', $run->meta['currency_lookup'] ?? null);
        $this->assertStringContainsString('currency is unknown', (string) $run->error);
        $this->assertSame('currency_unknown', $this->account->fresh()->last_sync_error_category);
        // The operator's word for it: «العملة غير معروفة», not the generic «فشلت آخر محاولة».
        $this->assertSame(AccountHealth::CURRENCY_UNKNOWN, app(AccountHealth::class)->for($this->account->fresh()));
    }

    /** The account was stored without a currency and the provider knows it: asked during the sync, recorded, used. */
    public function test_a_sync_asks_the_provider_for_a_missing_account_currency_and_records_it(): void
    {
        $this->providerReports(245.07, accountCurrency: 'SAR');

        $this->sync();

        $this->assertSame('SAR', $this->account->fresh()->currency);

        $spend = DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->first();
        $this->assertSame('SAR', $spend->original_currency);
        $this->assertEqualsWithDelta(245.07 / 3.75, (float) $spend->value, 0.01);

        $run = MetricSyncRun::withoutGlobalScopes()->latest('started_at')->firstOrFail();
        $this->assertSame('success', $run->status->value ?? $run->status);
        $this->assertSame('found', $run->meta['currency_lookup'] ?? null);
        $this->assertNull($this->account->fresh()->last_sync_error_category);
    }

    /** «Once»: a provider that does not state the currency is not asked again by every half-hourly sweep. */
    public function test_a_provider_that_does_not_state_the_currency_is_not_asked_again_at_once(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response($this->adAccount(null))]);
        $connector = (new OpenAiAdsConnector)->withConnection($this->connection);
        $lookup = app(AccountCurrencyLookup::class);

        $this->assertSame('not_stated', $lookup->ensure($this->account, $connector));
        $asked = count(Http::recorded());

        $this->assertSame('asked_recently', $lookup->ensure($this->account->fresh(), $connector));
        $this->assertCount($asked, Http::recorded(), 'the second lookup made a provider call');
        $this->assertNull($this->account->fresh()->currency);
    }

    /** A known currency is never looked up, and never replaced. */
    public function test_a_known_account_currency_is_left_alone(): void
    {
        $this->account->forceFill(['currency' => 'SAR'])->save();
        Http::fake(['api.ads.openai.com/*' => Http::response($this->adAccount('USD'))]);

        $outcome = app(AccountCurrencyLookup::class)->ensure($this->account, (new OpenAiAdsConnector)->withConnection($this->connection));

        $this->assertSame('known', $outcome);
        $this->assertCount(0, Http::recorded());
        $this->assertSame('SAR', $this->account->fresh()->currency);
    }

    /** A discovery answer that omits the currency must not erase one already recorded. */
    public function test_discovery_never_erases_a_recorded_currency(): void
    {
        $this->account->forceFill(['currency' => 'SAR'])->save();
        Http::fake(['api.ads.openai.com/*' => Http::response($this->adAccount(null))]);

        app(AccountDiscovery::class)->refresh($this->connection);

        $this->assertSame('SAR', $this->account->fresh()->currency);
    }

    /** The row's own label still outranks the account's, and both outrank nothing. */
    public function test_a_row_that_states_its_currency_is_converted_from_it(): void
    {
        $campaign = $this->campaign();
        $known = [$sq = 'sq-1' => ['id' => (string) Str::uuid(), 'project_id' => (string) $this->project->id, 'tenant_id' => (string) $this->tenant->id, 'campaign_id' => (string) $campaign->id, 'ad_set_id' => null]];

        $result = app(UpsertEntityDailyMetrics::class)->execute($this->account, 'ad_set', [
            ['entity_id' => $sq, 'date' => '2026-11-02', 'spend' => 375.0, 'impressions' => 10, 'currency' => 'sar'],
        ], $known);

        $row = DB::table('entity_daily_metrics')->first();
        $this->assertSame('SAR', $row->original_currency);
        $this->assertEqualsWithDelta(100.0, (float) $row->spend, 0.001);
        $this->assertSame(0, $result['currency_unknown']);
    }

    // ── ad-set / ad grain ─────────────────────────────────────────────────────────────────────

    public function test_entity_grain_money_with_no_currency_is_withheld_and_counted(): void
    {
        $campaign = $this->campaign();
        $known = ['sq-1' => ['id' => (string) Str::uuid(), 'project_id' => (string) $this->project->id, 'tenant_id' => (string) $this->tenant->id, 'campaign_id' => (string) $campaign->id, 'ad_set_id' => null]];

        $result = app(UpsertEntityDailyMetrics::class)->execute($this->account, 'ad_set', [
            ['entity_id' => 'sq-1', 'date' => '2026-11-02', 'spend' => 412.5, 'impressions' => 90000],
        ], $known);

        $row = DB::table('entity_daily_metrics')->first();
        $this->assertNull($row->spend);
        $this->assertEqualsWithDelta(412.5, (float) $row->spend_original, 0.001);
        $this->assertNull($row->original_currency);
        $this->assertEqualsWithDelta(90000, (float) $row->impressions, 0.001);
        $this->assertSame(1, $result['currency_unknown']);
    }

    // ── creative grain ────────────────────────────────────────────────────────────────────────

    public function test_creative_grain_money_with_no_currency_is_withheld_and_counted(): void
    {
        $campaign = $this->campaign();
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'openai_ads',
            'external_creative_id' => 'cr-1', 'name' => 'Creative', 'format' => 'image',
        ]);

        $result = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-11-02', 'spend' => 88.0, 'impressions' => 700],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();
        $this->assertNull($row->spend);
        $this->assertEqualsWithDelta(88.0, (float) $row->spend_original, 0.001);
        $this->assertNull($row->original_currency);
        $this->assertEqualsWithDelta(700, (float) $row->impressions, 0.001);
        $this->assertSame(1, $result['currency_unknown']);
    }

    // ── what reads a withheld figure ──────────────────────────────────────────────────────────

    /**
     * Totals: unknown-currency money is «no single total», never a number — alone, and also when it sits
     * beside rate-withheld money in ONE known currency, where `COUNT(DISTINCT original_currency)` used to
     * skip the NULLs, answer 1, and let the unknown amounts be summed and labelled with that currency.
     */
    public function test_totals_never_state_a_figure_that_contains_unknown_currency_money(): void
    {
        $campaign = $this->campaign();
        $base = [
            'id' => null, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'openai_ads', 'metric_key' => 'spend', 'attribution_window' => 'default',
            'value' => null, 'project_currency' => 'USD', 'is_demo' => false,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ];
        DB::table('daily_metrics')->insert([
            ['id' => (string) Str::uuid(), 'metric_date' => '2026-11-02', 'original_amount' => 100, 'original_currency' => null] + $base,
        ]);

        $totals = app(MetricsAggregator::class)->forProjects([(string) $this->project->id])->totals(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-05'));
        $alone = MoneyScope::of((float) $totals['spend'], (int) $totals['spend_withheld_rows'], (float) $totals['spend_original'], (int) $totals['money_original_currencies'], $totals['money_original_currency']);
        $this->assertFalse($alone->hasSingleTotal());
        $this->assertNull($alone->amount());

        DB::table('daily_metrics')->insert([
            ['id' => (string) Str::uuid(), 'metric_date' => '2026-11-03', 'original_amount' => 50, 'original_currency' => 'EUR'] + $base,
        ]);

        $totals = app(MetricsAggregator::class)->forProjects([(string) $this->project->id])->totals(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-05'));
        $mixed = MoneyScope::of((float) $totals['spend'], (int) $totals['spend_withheld_rows'], (float) $totals['spend_original'], (int) $totals['money_original_currencies'], $totals['money_original_currency']);
        $this->assertFalse($mixed->hasSingleTotal(), '150 «EUR» would be 100 of nobody-knows-what plus 50 euros');
        $this->assertNull($mixed->amount());
    }

    // ── budgets ───────────────────────────────────────────────────────────────────────────────

    /** A budget whose currency nobody stated is not adopted as a figure; it arrives when the currency does. */
    public function test_a_budget_in_an_unknown_currency_is_withheld_until_the_currency_is_known(): void
    {
        $import = app(ImportExternalCampaigns::class);
        $rows = [['external_id' => 'cmp_1', 'name' => 'Launch', 'status' => 'active', 'lifetime_budget' => 5000.0, 'currency' => null]];

        $import->execute($this->account, SyncResult::of($rows), (string) $this->project->id);

        $unified = UnifiedCampaign::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($unified->total_budget, '5000 of an unknown unit must not be shown as 5000 dollars');
        $this->assertTrue((bool) ($unified->meta['budget_currency_unknown'] ?? false));
        $this->assertEqualsWithDelta(5000.0, (float) ExternalCampaign::withoutGlobalScopes()->firstOrFail()->lifetime_budget, 0.001, 'the provider figure is kept');

        $this->account->forceFill(['currency' => 'SAR'])->save();
        $import->execute($this->account->fresh(), SyncResult::of($rows), (string) $this->project->id);

        $unified->refresh();
        $this->assertEqualsWithDelta(5000.0, (float) $unified->total_budget, 0.001);
        $this->assertSame('SAR', $unified->budget_currency);
        $this->assertArrayNotHasKey('budget_currency_unknown', (array) $unified->meta);
    }

    // ── commerce ──────────────────────────────────────────────────────────────────────────────

    public function test_a_store_order_with_no_currency_is_withheld_not_read_as_dollars(): void
    {
        $store = new ExternalAccount;
        $store->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection->getKey(), 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => 'shop', 'name' => 'shop',
            'currency' => null, 'timezone' => 'Asia/Riyadh', 'status' => 'active',
        ])->save();

        app(ImportStoreData::class)->orders($store, (string) $this->project->id, [[
            'external_id' => 'o-1', 'status' => 'completed', 'placed_at' => '2026-11-02 10:00:00', 'total' => 300.0,
        ]]);

        $order = CommerceOrder::withoutGlobalScopes()->where('external_id', 'o-1')->firstOrFail();
        $this->assertNull($order->total);
        $this->assertEqualsWithDelta(300.0, (float) $order->original_total, 0.001);
        $this->assertNull($order->original_currency);
        $this->assertNull($order->exchange_rate);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    private function campaign(): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'openai_ads',
            'external_id' => 'cmp-x', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function adAccount(?string $currency): array
    {
        return array_filter([
            'id' => 'adacct_123', 'name' => 'Riyadh store', 'status' => 'active',
            'timezone' => 'Asia/Riyadh', 'currency_code' => $currency, 'review' => ['status' => 'approved'],
        ], static fn ($v) => $v !== null);
    }

    private function sync(): void
    {
        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);
        app()->call([new SyncAccountMetricsJob($this->account->id, '2026-11-01', '2026-11-05'), 'handle']);
    }

    private function providerReports(float $spend, ?string $accountCurrency): void
    {
        Http::fake(function ($request) use ($spend, $accountCurrency) {
            $url = $request->url();
            if (str_contains($url, '/conversions/insights')) {
                return Http::response(['object' => 'list', 'data' => [], 'count' => 0]);
            }
            if (str_contains($url, '/ad_account/insights')) {
                if (! str_contains($url, 'aggregation_level=campaign')) {
                    return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
                }

                return Http::response(['object' => 'list', 'has_more' => false, 'data' => [[
                    'id' => 'r1', 'readable_time' => '2026-11-02', 'campaign_id' => 'cmp_1',
                    'impressions' => 9000, 'clicks' => 150, 'spend' => $spend,
                ]]]);
            }
            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response($this->adAccount($accountCurrency));
        });
    }
}
