<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MONEY-CURRENCY-NEVER-ASSUMED-001 — `money:currency-audit`: the four conditions that precede or betray a
 * currency defect, per provider, read-only, with masked ids and no names or amounts.
 */
final class MoneyCurrencyAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);

        $this->tenant = Tenant::create(['name' => 'Agency Secret Name', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Secret Name', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'Project Secret Name', 'status' => 'active',
        ]);
    }

    public function test_it_reports_each_condition_per_provider_without_names_or_amounts(): void
    {
        // openai_ads: a bound account with no currency, holding money that was withheld for it.
        $noCurrency = $this->account('openai_ads', null, bound: true);
        $this->account('openai_ads', null, bound: false);
        $this->money($noCurrency, '2026-11-02', value: null, original: 245.07, currency: null);

        // tiktok: a riyal account whose stored rows say dollars — what an assumed currency leaves behind.
        $riyal = $this->account('tiktok', 'SAR', bound: true);
        $this->money($riyal, '2026-10-01', value: 90.0, original: 90.0, currency: 'USD');
        $this->money($riyal, '2026-10-03', value: 24.0, original: 90.0, currency: 'SAR');

        // meta: a euro account whose money is withheld because no EUR rate exists.
        $euro = $this->account('meta', 'EUR', bound: true);
        $this->money($euro, '2026-10-10', value: null, original: 50.0, currency: 'EUR');
        $this->money($euro, '2026-10-12', value: null, original: 60.0, currency: 'EUR');

        $before = [DB::table('daily_metrics')->count(), DB::table('external_accounts')->count(), DB::table('currency_rates')->count()];
        Http::fake();

        $this->assertSame(0, Artisan::call('money:currency-audit', ['--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['read_only']);
        $this->assertSame('USD', $report['reporting_currency']);

        $openai = $report['providers']['openai_ads'];
        $this->assertSame(2, $openai['accounts_without_currency']['total']);
        $this->assertSame(1, $openai['accounts_without_currency']['bound_to_a_project']);
        $this->assertSame(substr((string) $noCurrency->id, 0, 8), $openai['accounts_without_currency']['sample'][0], 'the bound account leads the sample');
        $this->assertSame(1, $openai['money_rows_without_currency']['campaign']['withheld']);
        $this->assertSame('2026-11-02', $openai['money_rows_without_currency']['campaign']['from']);

        $tiktok = $report['providers']['tiktok'];
        $this->assertSame(0, $tiktok['accounts_without_currency']['total']);
        $this->assertSame(1, $tiktok['row_currency_differs_from_account']['rows']);
        $this->assertSame(['account' => substr((string) $riyal->id, 0, 8), 'account_currency' => 'SAR', 'row_currency' => 'USD', 'rows' => 1, 'from' => '2026-10-01', 'to' => '2026-10-01'], $tiktok['row_currency_differs_from_account']['findings'][0]);
        $this->assertTrue($tiktok['account_currencies'][0]['converts_to_usd_today'], 'a riyal rate is on file');
        $this->assertTrue($tiktok['account_currencies'][0]['legal_peg']);

        $meta = $report['providers']['meta'];
        $this->assertSame(['pair' => 'EUR→USD', 'rows' => 2, 'from' => '2026-10-10', 'to' => '2026-10-12', 'rate_reaches_first_day' => false, 'rate_reaches_last_day' => false], $meta['pairs_without_rate'][0]);
        $this->assertFalse($meta['account_currencies'][0]['converts_to_usd_today']);
        $this->assertFalse($meta['account_currencies'][0]['legal_peg'], 'the euro floats: it needs a published rate');

        $this->assertSame(2, $report['totals']['accounts_without_currency']);
        $this->assertSame(1, $report['totals']['pairs_without_rate']);

        // Safe to paste into an issue: no names, no amounts, no full ids.
        foreach (['Secret Name', 'Riyadh', '245.07', (string) $noCurrency->id, (string) $riyal->id] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }

        // Read-only: nothing written, nothing asked of anybody.
        $this->assertSame($before, [DB::table('daily_metrics')->count(), DB::table('external_accounts')->count(), DB::table('currency_rates')->count()]);
        Http::assertNothingSent();

        // The table form is the same report.
        $this->assertSame(0, Artisan::call('money:currency-audit'));
        $this->assertStringContainsString('accounts without a currency: 2 (bound to a project: 1)', Artisan::output());
    }

    private function account(string $provider, ?string $currency, bool $bound): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-'.Str::random(6), 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider.'-'.Str::random(4),
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => 'act-'.Str::random(6),
            'name' => 'Riyadh Secret Name',
            'currency' => $currency,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        if ($bound) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'client_workspace_id' => $this->project->client_workspace_id,
                'project_id' => $this->project->id,
                'external_account_id' => $account->id,
                'provider' => $provider,
                'purpose' => 'advertising',
                'is_active' => true,
            ]);
        }

        return $account;
    }

    private function money(ExternalAccount $account, string $date, ?float $value, float $original, ?string $currency): void
    {
        $campaign = ExternalCampaign::withoutGlobalScopes()->firstOrCreate(
            ['external_account_id' => $account->id, 'external_id' => 'cmp-'.$account->id],
            ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => $account->provider, 'name' => 'Campaign Secret Name', 'status' => 'active'],
        );

        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => $campaign->id,
            'provider' => $account->provider, 'metric_key' => 'spend', 'metric_date' => $date,
            'attribution_window' => 'default', 'value' => $value, 'original_amount' => $original,
            'original_currency' => $currency, 'project_currency' => 'USD', 'is_demo' => false,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }
}
