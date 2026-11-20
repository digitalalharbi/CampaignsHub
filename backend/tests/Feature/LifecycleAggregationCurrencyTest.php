<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Metrics\Fx\FxMonitor;
use App\Domains\Metrics\Fx\FxRecompute;
use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Platform\Models\PlatformAlert;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFxApplyWorld;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 §7 + rule 9 (builder N4) — the Owner's live-client shape, as a local fixture (no production id):
 *
 *  - a client in «onboarding» with a LIVE project whose Google account spends in EUR, and a SAR budget of 50,000, open-ended;
 *  - EUR→USD recorded MANUALLY, dated 2026-11-09; SAR→USD pegged; EUR spend on days before the rate (no rate: withheld) and
 *    after it — three of those days ingested BEFORE the rate was recorded (stored NULL, the «missing spend» shape);
 *  - an ARCHIVED second project under the same client, with USD spend and a USD campaign budget of 8,750.
 *
 * Every page — budgets, clients list, dashboard, projects summary, campaigns — shows the SAME spend for the same period
 * (≤ 0.01 apart once in one currency), excludes only the part with no rate and NAMES it, never counts the archived project,
 * and the dashboard's denominator is the budgets page's 50,000 SAR.
 */
final class LifecycleAggregationCurrencyTest extends TestCase
{
    use BuildsFxApplyWorld;
    use RefreshDatabase;

    private const FROM = '2026-11-01';

    private const TO = '2026-11-15';

    private ExternalAccount $eur;

    private ExternalCampaign $eurCampaign;

    private Project $archived;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFxApplyWorld();
        // Client state never hides spend (rule 4): the live client is still «onboarding».
        DB::table('client_workspaces')->where('id', $this->client->id)->update(['client_status' => 'onboarding']);
    }

    /** The Owner's world. `$expiresOn` gives the manual rate a validity end. */
    private function ownerWorld(?string $expiresOn = null, string $paidOn = self::FROM): void
    {
        $this->eur = $this->adAccount('EUR', 'google');
        [, $this->eurCampaign] = $this->campaign($this->eur, 'Search EU', 20000.0);

        foreach (['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11'] as $day) {
            $this->moneyRow($this->eur, $this->eurCampaign, 100.0, $day); // stored before any EUR rate existed: NULL
        }
        app(RateLedger::class)->record('EUR', 'USD', 1.08, '2026-11-09', CurrencyRate::MANUAL, effectiveTo: $expiresOn, by: $this->owner);
        foreach (['2026-11-12', '2026-11-13', '2026-11-14'] as $day) {
            $this->moneyRow($this->eur, $this->eurCampaign, 100.0, $day);
        }

        // The archived test project under the same client: USD spend and an 8,750 USD campaign budget.
        $this->archived = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Archived test', 'status' => 'archived']);
        $usd = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->eur->provider_connection_id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'acc-'.Str::random(8), 'name' => 'Archived USD', 'status' => 'active', 'currency' => 'USD']);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->archived->id, 'external_account_id' => $usd->id, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => true]);
        $unified = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->archived->id, 'client_workspace_id' => $this->client->id,
            'name' => 'Old test', 'objective' => 'traffic', 'status' => 'active', 'total_budget' => 8750, 'budget_currency' => 'USD', 'platforms' => ['meta']]);
        $external = ExternalCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->archived->id, 'external_account_id' => $usd->id, 'provider' => 'meta',
            'external_id' => (string) random_int(100000, 999999), 'name' => 'Old test', 'status' => 'active', 'unified_campaign_id' => $unified->id]);
        foreach (['2026-11-05', '2026-11-09', '2026-11-14'] as $day) {
            DailyMetric::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->archived->id,
                'external_account_id' => $usd->id, 'external_campaign_id' => $external->id, 'unified_campaign_id' => $unified->id, 'provider' => 'meta', 'metric_key' => 'spend',
                'metric_date' => $day, ...app(ReportingCurrency::class)->normalise(500.0, 'USD', 'USD', Carbon::parse($day))]);
        }

        // The SAR budget, 50,000, open-ended.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/funding/payments', [
            'client_workspace_id' => $this->client->id, 'project_id' => $this->project->id, 'amount' => 50000, 'currency' => 'SAR',
            'paid_on' => $paidOn, 'reference' => 'TRX-1', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
    }

    /** @return array{budgets: array<string,mixed>, clients: array<string,mixed>, dashboard: array<string,mixed>, home: array<string,mixed>, summary: array<string,mixed>, campaigns: array<string,mixed>} */
    private function pages(): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $window = ['from' => self::FROM, 'to' => self::TO];
        $as = $this->actingAs($this->owner, 'sanctum');

        return [
            'budgets' => collect($as->getJson('/api/v1/funding')->assertOk()->json('data'))->firstWhere('project_id', $this->project->id),
            'clients' => collect($as->getJson('/api/v1/app/clients')->assertOk()->json('data'))->firstWhere('id', $this->client->id),
            'dashboard' => $as->getJson('/api/v1/me/dashboard')->assertOk()->json('data.money'),
            'home' => collect($as->getJson('/api/v1/agency/client-budgets')->assertOk()->json('data'))->firstWhere('client_id', $this->client->id),
            'summary' => $as->getJson('/api/v1/portfolio/overview?'.http_build_query($window))->assertOk()->json('data.spend'),
            'campaigns' => $as->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?".http_build_query($window))->assertOk()->json('data'),
        ];
    }

    /** SAR → USD through the engine's peg (the same conversion every page uses). */
    private static function usd(float $sar): float
    {
        return $sar * 0.266666666667;
    }

    public function test_five_pages_show_one_spend_for_the_same_period_and_name_the_part_with_no_rate(): void
    {
        $this->ownerWorld();
        $p = $this->pages();

        // 6 converted days × 100 EUR × 1.08 = 648.00 USD = 2,430.00 SAR. The 4 days before the rate (400 EUR) are excluded, named.
        $this->assertEqualsWithDelta(2430.00, $p['budgets']['spent'], 0.001, 'budgets page');
        $figures = [
            'budgets (SAR→USD)' => self::usd((float) $p['budgets']['spent']),
            'clients list' => (float) $p['clients']['spend'],
            'dashboard (SAR→USD)' => self::usd((float) $p['dashboard']['spent']),
            'projects summary' => (float) $p['summary']['by_currency'][0]['spend'],
            'campaigns' => (float) $p['campaigns']['current']['spend'],
            'campaigns (ledger)' => (float) $p['campaigns']['spend_reading']['amount'],
        ];
        foreach ($figures as $page => $usd) {
            $this->assertEqualsWithDelta(648.00, $usd, 0.01, "{$page} must read 648.00 USD");
        }
        $this->assertLessThanOrEqual(0.01, max($figures) - min($figures), 'max difference between pages: 0.01');

        // The excluded part, on every page that carries money: currency, account, amount, and the explicit statement.
        foreach ([
            'budgets' => $p['budgets']['fx'],
            'clients list' => $p['clients']['spend_fx'],
            'dashboard' => $p['dashboard']['fx'],
            'projects summary' => $p['summary']['reading'],
            'campaigns' => $p['campaigns']['spend_reading'],
        ] as $page => $fx) {
            $this->assertCount(1, $fx['excluded'], $page);
            $this->assertSame('EUR', $fx['excluded'][0]['currency'], $page);
            $this->assertSame('Account EUR', $fx['excluded'][0]['account_name'], $page);
            $this->assertEqualsWithDelta(400.0, $fx['excluded'][0]['original_amount'], 0.001, $page);
            $this->assertStringContainsString('سعر صرف غير متوفر', (string) $fx['statement'], $page);
            $this->assertStringContainsString('مستبعد من الإجمالي', (string) $fx['statement'], $page);
            $this->assertStringContainsString('Account EUR', (string) $fx['statement'], $page);
        }
        $this->assertFalse($p['clients']['spend_fx']['withheld'], 'a partial total is shown, never «—» and never zero');
        $this->assertTrue($p['clients']['spend_fx']['partial']);

        // The dashboard's denominator is the budgets page's budget — 50,000 SAR, converted by the engine — never 8.75K.
        $this->assertEqualsWithDelta(50000.0, $p['dashboard']['budget'], 0.001);
        $this->assertSame('SAR', $p['dashboard']['currency']);
        $this->assertEqualsWithDelta(13333.33, $p['dashboard']['budget_fx']['converted_amount'], 0.01);
        $this->assertSame('USD', $p['dashboard']['budget_fx']['currency']);
        $this->assertEqualsWithDelta(50000.0, $p['home']['project_budget']['approved'], 0.001);
        $this->assertEqualsWithDelta(2430.0, $p['home']['project_budget']['spent'], 0.001);
        $this->assertEqualsWithDelta(0.0486, $p['budgets']['used_ratio'], 0.0001);
    }

    public function test_the_archived_project_is_never_counted_and_the_onboarding_client_still_shows_its_spend(): void
    {
        $this->ownerWorld();
        $p = $this->pages();

        // 1,500 USD of archived spend and its 8,750 USD budget appear nowhere.
        $this->assertNotNull($p['clients']['spend'], 'an «onboarding» client with a live spending project shows its spend');
        $this->assertEqualsWithDelta(648.0, (float) $p['clients']['spend'], 0.01);
        $this->assertSame(1, $p['summary']['by_currency'][0]['projects']);
        $this->assertArrayNotHasKey((string) $this->archived->id, $p['summary']['reading']['by_project']);
        $this->assertNull(collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/funding')->json('data'))->firstWhere('project_id', $this->archived->id));
        foreach ([$p['dashboard']['budget'], $p['home']['project_budget']['approved']] as $denominator) {
            $this->assertNotEqualsWithDelta(8750.0, (float) $denominator, 0.5);
        }
    }

    public function test_an_expired_manual_rate_is_used_with_the_old_rate_label_and_the_admin_is_told_three_days_before(): void
    {
        // The manual rate is valid until 2026-11-11; days after it still convert at it — flagged, never withheld.
        $this->ownerWorld(expiresOn: '2026-11-11');
        $p = $this->pages();

        $this->assertEqualsWithDelta(648.00, (float) $p['campaigns']['spend_reading']['amount'], 0.01);
        $this->assertEqualsWithDelta(648.00, (float) $p['campaigns']['current']['spend'], 0.01);
        $this->assertEqualsWithDelta(2430.00, (float) $p['budgets']['spent'], 0.01);
        foreach ([$p['campaigns']['spend_reading'], $p['budgets']['fx'], $p['summary']['reading']] as $fx) {
            $this->assertNotEmpty($fx['stale']);
            // Owner 2026-11-19: the old-rate wording is gone from every client-facing payload; the internal flags stay.
            $this->assertArrayNotHasKey('stale_label', $fx);
            $this->assertArrayNotHasKey('label', $fx['stale'][0]);
            $this->assertSame('2026-11-09', $fx['stale'][0]['rate_date']);
            $this->assertTrue($fx['stale'][0]['expired']);
        }

        // 2026-11-09: the rate ends in two days → one internal admin alert; once a later open-ended rate exists it clears.
        app(FxMonitor::class)->sweep(Carbon::parse('2026-11-09 08:00:00'));
        $alert = PlatformAlert::query()->open()->where('kind', FxMonitor::EXPIRING)->first();
        $this->assertNotNull($alert);
        $this->assertSame('2026-11-11', $alert->facts['effective_to']);
        $this->assertSame('EUR→USD', $alert->facts['pair']);
        app(RateLedger::class)->record('EUR', 'USD', 1.09, '2026-11-10', CurrencyRate::MANUAL, by: $this->owner);
        app(FxMonitor::class)->sweep(Carbon::parse('2026-11-10 08:00:00'));
        $this->assertSame(0, PlatformAlert::query()->open()->where('kind', FxMonitor::EXPIRING)->count());
    }

    public function test_a_deleted_rate_excludes_only_the_affected_part_with_a_flag(): void
    {
        $this->ownerWorld();
        // A second, SAR account on the live project: 375 SAR a day on the same six days (pegged — never affected).
        $sar = $this->adAccount('SAR', 'snapchat');
        [, $sarCampaign] = $this->campaign($sar, 'Snap SA');
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'] as $day) {
            $this->moneyRow($sar, $sarCampaign, 375.0, $day);
        }

        DB::table('currency_rates')->where('base_currency', 'EUR')->where('quote_currency', 'USD')->delete();
        // The cycle every rate change goes through: the admin's «إعادة حساب» and the 30-minute budget refresh.
        app(FxRecompute::class)->run(Carbon::parse(self::FROM), Carbon::parse(self::TO), true);
        app(FundingSpendRefresher::class)->refresh(ProjectFunding::query()->where('project_id', $this->project->id)->firstOrFail());
        $p = $this->pages();

        // 6 × 375 SAR = 2,250 SAR = 600.00 USD stays; all 1,000 EUR are excluded and named — nothing became zero.
        $this->assertEqualsWithDelta(2250.00, (float) $p['budgets']['spent'], 0.001);
        foreach ([(float) $p['clients']['spend'], self::usd((float) $p['dashboard']['spent']), (float) $p['summary']['by_currency'][0]['spend'],
            (float) $p['campaigns']['current']['spend'], (float) $p['campaigns']['spend_reading']['amount']] as $usd) {
            $this->assertEqualsWithDelta(600.00, $usd, 0.01);
        }
        foreach ([$p['budgets']['fx'], $p['clients']['spend_fx'], $p['dashboard']['fx'], $p['summary']['reading'], $p['campaigns']['spend_reading']] as $fx) {
            $this->assertSame(['EUR'], array_values(array_unique(array_column($fx['excluded'], 'currency'))));
            $this->assertEqualsWithDelta(1000.0, array_sum(array_column($fx['excluded'], 'original_amount')), 0.001);
            $this->assertStringContainsString('سعر صرف غير متوفر', (string) $fx['statement']);
        }
    }

    public function test_an_open_ended_budget_counts_from_the_first_real_spend_even_when_that_day_has_no_rate(): void
    {
        // The budget was typed in after the spend began: its period starts at the first REAL spend (2026-11-05, no rate yet),
        // not at the first converted day — so the days with no rate are named as excluded instead of silently uncounted.
        $this->ownerWorld(paidOn: '2026-11-14');
        $budget = $this->pages()['budgets'];

        $this->assertSame('2026-11-05', $budget['period_start']);
        $this->assertEqualsWithDelta(2430.00, (float) $budget['spent'], 0.001);
        $this->assertSame(4, $budget['spend_without_rate']);
        $this->assertSame('incomplete', $budget['status']);
        $this->assertEqualsWithDelta(400.0, $budget['fx']['excluded'][0]['original_amount'], 0.001);
    }
}
