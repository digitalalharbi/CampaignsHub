<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\OpenAiAdsConnector;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OPENAI-ADS-CURRENCY-001 — a ChatGPT Ads account billed in riyals is converted, not read as dollars.
 *
 * Production, 2026-11-05: the Owner opened a client link whose ChatGPT Ads platform showed 245.07 under a
 * USD report; the platform's own spend is 245 SAR. All four stored OpenAI accounts had no currency, so
 * `InsightRowNormaliser` fell through to «the reporting currency», labelled every row `USD → USD` at
 * rate 1.0, and added riyals to dollars.
 *
 * The cause was a key. The Ad Account object documents its currency as `currency_code`
 * (developers.openai.com/ads/api-reference/ad-account); the connector read `currency`, which the API
 * never sends, and the fixtures here used the same wrong key, so every test was green. The fixtures in
 * this file are the DOCUMENTED shape.
 */
final class OpenAiAdsAccountCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProviderConnection $connection;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        // Production has the catalogue; without it `spend` is not money and nothing is converted.
        $this->seed(MetricDefinitionSeeder::class);
        // The peg Production stores (SAR per USD), which is what makes a riyal figure convertible.
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);

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

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-0000beef', null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        // The Production state: discovered, active, and with NO currency.
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
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    /** The account the API documents: `currency_code`, not `currency`. */
    public function test_the_account_currency_is_read_from_the_documented_currency_code_field(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response($this->adAccount('SAR'))]);

        $accounts = (new OpenAiAdsConnector)->withConnection($this->connection)->listAdAccounts();

        $this->assertSame('SAR', $accounts[0]['currency']);
    }

    /** A refresh fills in the currency of an account that was stored without one. */
    public function test_a_discovery_refresh_gives_a_currency_less_account_its_currency(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response($this->adAccount('SAR'))]);

        app(AccountDiscovery::class)->refresh($this->connection);

        $this->assertSame('SAR', $this->account->fresh()->currency);
    }

    /** The money in the report: riyals in, dollars out, and the riyal amount kept beside it. */
    public function test_riyal_spend_is_stored_converted_with_its_original_kept(): void
    {
        $this->account->forceFill(['currency' => 'SAR'])->save();
        $this->providerReports(245.07);

        $this->sync();

        $row = DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->first();
        $this->assertNotNull($row);
        $this->assertSame('SAR', $row->original_currency);
        $this->assertSame('USD', $row->project_currency);
        $this->assertEqualsWithDelta(245.07, (float) $row->original_amount, 0.001);
        $this->assertEqualsWithDelta(245.07 / 3.75, (float) $row->value, 0.01);
    }

    /**
     * The repair path: rows the old code wrote as «USD → USD» are rewritten, in place, by the ordinary
     * sync once the account has its currency — no second pipeline and no hand-edited history.
     */
    public function test_a_resync_rewrites_rows_that_were_stored_as_dollars(): void
    {
        $this->providerReports(245.07);
        $this->sync();

        $wrong = DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->first();
        $this->assertSame('USD', $wrong->original_currency, 'the fixture must reproduce the Production state first');
        $this->assertEqualsWithDelta(245.07, (float) $wrong->value, 0.001);

        Http::fake(['api.ads.openai.com/*' => Http::response($this->adAccount('SAR'))]);
        app(AccountDiscovery::class)->refresh($this->connection);
        $this->providerReports(245.07);
        $this->sync();

        $this->assertSame(1, DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->count(), 'the rewrite inserted a second row');
        $fixed = DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-11-02')->first();
        $this->assertSame('SAR', $fixed->original_currency);
        $this->assertEqualsWithDelta(245.07 / 3.75, (float) $fixed->value, 0.01);
    }

    /** @return array<string, mixed> */
    private function adAccount(string $currency): array
    {
        return [
            'id' => 'adacct_123', 'name' => 'Riyadh store', 'status' => 'active',
            'timezone' => 'Asia/Riyadh', 'currency_code' => $currency, 'review' => ['status' => 'approved'],
        ];
    }

    private function sync(): void
    {
        app()->call([new SyncAccountStructureJob($this->account->id), 'handle']);
        app()->call([new SyncAccountMetricsJob($this->account->id, '2026-11-01', '2026-11-05'), 'handle']);
    }

    private function providerReports(float $spend): void
    {
        Http::fake(function ($request) use ($spend) {
            $url = $request->url();
            if (str_contains($url, '/conversions/insights')) {
                return Http::response(['object' => 'list', 'data' => [], 'count' => 0, 'account_currency' => 'SAR']);
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

            return Http::response($this->adAccount('SAR'));
        });
    }
}
