<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Support\BudgetFx;
use App\Domains\Metrics\Fx\FxDisplay;
use App\Domains\Metrics\Fx\FxMonitor;
use App\Domains\Metrics\Fx\FxRecompute;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportFxFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsFxApplyWorld;
use Tests\TestCase;

/**
 * FX-APPLY-001 (builder G) — the Owner's explicit acceptance cases for the currency engine applied to every money path.
 *
 * 253 EUR at EUR→USD 1.12 is 283.36 USD in the campaigns table, on the campaign page, in totals, in the export, in a rule and
 * in a budget — never «253 $». A project with USD + EUR + SAR sums after conversion. EUR→SAR goes through USD (1,062.60). Each
 * amount uses its own date's rate. No rate → withheld (null, never zero, never raw) + an alert. A rate older than 7 days is
 * used and flagged. Editing a rate and recomputing updates the stored numbers. A 13.33 USD rule limit on a EUR account is
 * compared after conversion.
 */
final class FxApplyCasesTest extends TestCase
{
    use BuildsFxApplyWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFxApplyWorld();
    }

    private function eurWorld(): array
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        $eur = $this->adAccount('EUR');
        [$unified, $external] = $this->campaign($eur, 'Search EU', 253.0, 253.0);
        $this->moneyRow($eur, $external, 253.0, '2026-11-09');
        $this->countRow($eur, $external, 'clicks', 100, '2026-11-09');

        return [$eur, $unified, $external];
    }

    public function test_253_eur_is_283_36_usd_in_the_table_the_page_and_the_totals_never_253_dollars(): void
    {
        [, $unified] = $this->eurWorld();
        $p = $this->project->id;

        // Campaigns table: the budget as FxConversion.
        $row = collect($this->actingAs($this->owner)->getJson("/api/v1/projects/{$p}/campaigns")->assertOk()->json('data'))->firstWhere('id', $unified->id);
        $this->assertEquals(253, $row['total_budget'], 'the platform figure is untouched');
        $this->assertSame('EUR', $row['budget_currency']);
        $this->assertSame('USD', $row['budget_fx']['currency']);
        $this->assertSame(283.36, round((float) $row['budget_fx']['converted_amount'], 2));
        $this->assertSame('fx.convert', $row['budget_fx']['via']);
        $this->assertSame('manual', $row['budget_fx']['kind']);

        // Campaign page.
        $show = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$p}/campaigns/{$unified->id}")->assertOk()->json('data');
        $this->assertSame(283.36, round((float) $show['budget_fx']['converted_amount'], 2));
        $this->assertEquals(253, $show['budget_fx']['original_amount']);
        $this->assertSame('EUR', $show['budget_fx']['original_currency']);

        // Totals: the spend total is the converted figure, and it says it includes a converted currency.
        $summary = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$p}/metrics/summary?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertSame(283.36, round((float) $summary['current']['spend'], 2));
        $this->assertSame('USD', $summary['fx']['reporting_currency']);
        $this->assertTrue($summary['fx']['includes_converted']);
        $this->assertSame(['EUR'], $summary['fx']['currencies']);

        // The per-campaign row in the table.
        $rows = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$p}/metrics/campaigns?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertSame(283.36, round((float) collect($rows)->firstWhere('campaign_id', $unified->id)['spend'], 2));
    }

    public function test_the_export_carries_the_original_currency_and_the_rate_in_its_header(): void
    {
        $this->eurWorld();
        $report = new Report;
        $report->forceFill(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-15']);

        $facts = ReportFxFacts::for($report, '2026-11-01', '2026-11-15');
        $this->assertSame(['google' => ['EUR']], $facts['by_provider']);
        $this->assertTrue($facts['includes_converted']);
        $this->assertStringContainsString('EUR→USD 1.12 (2026-11-09) manual', $facts['header']);
        $this->assertStringContainsString('Figures in USD', $facts['header']);
        $this->assertStringContainsString('withheld: none', $facts['header']);
    }

    public function test_a_budget_converts_through_the_engine(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        $r = app(BudgetFx::class)->convert(253.0, 'EUR', 'USD', Carbon::parse('2026-11-09'));
        $this->assertSame(283.36, $r['amount']);
        $this->assertSame('fx.convert', $r['conversion']['via']);
        $this->assertNull(app(BudgetFx::class)->convert(253.0, 'GBP', 'USD', Carbon::parse('2026-11-09')), 'no rate: null, never the raw figure');
    }

    public function test_a_project_with_usd_eur_and_sar_sums_after_conversion(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        foreach ([['USD', 'meta', 100.0], ['EUR', 'google', 253.0], ['SAR', 'snapchat', 375.0]] as [$currency, $provider, $amount]) {
            $account = $this->adAccount($currency, $provider);
            [, $campaign] = $this->campaign($account, $currency.' campaign');
            $this->moneyRow($account, $campaign, $amount, '2026-11-09');
        }

        // 100 + 283.36 + 100 (375 SAR at the SAMA peg) = 483.36 USD — never 728 «of something».
        $summary = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertSame(483.36, round((float) $summary['current']['spend'], 2));
        $this->assertSame(['EUR', 'SAR', 'USD'], $summary['fx']['currencies']);
        $this->assertTrue($summary['fx']['includes_converted']);

        $total = app(FxDisplay::class)->total([
            ['amount' => 100, 'currency' => 'USD', 'date' => '2026-11-09'],
            ['amount' => 253, 'currency' => 'EUR', 'date' => '2026-11-09'],
            ['amount' => 375, 'currency' => 'SAR', 'date' => '2026-11-09'],
        ], 'USD');
        $this->assertSame(483.36, round((float) $total['converted_amount'], 2));
        $this->assertTrue($total['includes_converted']);
        $this->assertSame(['EUR', 'SAR', 'USD'], $total['currencies']);
    }

    public function test_eur_to_sar_goes_through_usd_and_is_1062_60(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        $c = app(FxDisplay::class)->one(253, 'EUR', '2026-11-09', 'SAR');
        $this->assertSame(1062.60, round((float) $c['converted_amount'], 2));
        $this->assertSame('cross', $c['kind']);
    }

    public function test_each_amount_uses_the_rate_of_its_own_date(): void
    {
        $this->rate('EUR', 'USD', 1.10, '2026-11-02');
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        $eur = $this->adAccount('EUR');
        [, $c] = $this->campaign($eur, 'EU');
        $this->moneyRow($eur, $c, 100.0, '2026-11-02');
        $this->moneyRow($eur, $c, 100.0, '2026-11-09');

        $summary = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertSame(222.0, round((float) $summary['current']['spend'], 2), '110 + 112, not 2 × 112');
    }

    public function test_no_rate_withholds_the_figure_and_raises_an_alert_never_zero_nor_raw(): void
    {
        $gbp = $this->adAccount('GBP');
        [$unified, $c] = $this->campaign($gbp, 'UK', 253.0);
        $this->moneyRow($gbp, $c, 253.0, '2026-11-09');

        $fx = app(FxDisplay::class)->one(253, 'GBP', '2026-11-09', 'USD');
        $this->assertTrue($fx['withheld']);
        $this->assertNull($fx['converted_amount']);
        $this->assertSame('/admin/settings/currency-rates', $fx['rates_page']);

        $row = collect($this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/campaigns")->assertOk()->json('data'))->firstWhere('id', $unified->id);
        $this->assertTrue($row['budget_fx']['withheld']);
        $this->assertNull($row['budget_fx']['converted_amount']);

        $summary = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertSame(1, $summary['fx']['withheld_rows']);
        $this->assertSame(['GBP'], $summary['fx']['withheld_currencies']);

        $clients = collect($this->actingAs($this->owner)->getJson('/api/v1/app/clients')->assertOk()->json('data'))->firstWhere('id', $this->client->id);
        $this->assertTrue($clients['spend_fx']['withheld'], 'a client total with a withheld part is withheld, not the smaller sum');
        $this->assertNull($clients['spend_fx']['converted_amount']);

        app(FxMonitor::class)->sweep();
        $this->assertContains('GBP', array_column(app(FxMonitor::class)->open(), 'currency'), 'a currency in use with no rate has an open admin alert');
    }

    public function test_a_rate_older_than_seven_days_is_used_and_flagged(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-10-20');
        $eur = $this->adAccount('EUR');
        [, $c] = $this->campaign($eur, 'EU');
        $this->moneyRow($eur, $c, 253.0, '2026-11-09');

        $one = app(FxDisplay::class)->one(253, 'EUR', '2026-11-09', 'USD');
        $this->assertTrue($one['stale']);
        $this->assertSame(283.36, round((float) $one['converted_amount'], 2));

        $summary = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertTrue($summary['fx']['stale']);
        $this->assertSame(283.36, round((float) $summary['current']['spend'], 2));
    }

    public function test_editing_a_rate_and_recomputing_updates_the_stored_numbers(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');
        $eur = $this->adAccount('EUR');
        [, $c] = $this->campaign($eur, 'EU');
        $row = $this->moneyRow($eur, $c, 253.0, '2026-11-09');
        $this->assertSame(283.36, round((float) $row->value, 2));

        $this->rate('EUR', 'USD', 1.15, '2026-11-09'); // a corrected manual rate for the same day: a new version
        $this->assertSame(283.36, round((float) DailyMetric::withoutGlobalScopes()->find($row->id)->value, 2), 'stored until «إعادة حساب»');

        app(FxRecompute::class)->run(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-15'), true);

        $after = DailyMetric::withoutGlobalScopes()->find($row->id);
        $this->assertSame(290.95, round((float) $after->value, 2));
        $this->assertSame(253.0, (float) $after->original_amount, 'the platform figure is never modified');
        $summary = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?".http_build_query($this->window()))->assertOk()->json('data');
        $this->assertSame(290.95, round((float) $summary['current']['spend'], 2));
    }

    public function test_a_13_33_usd_rule_limit_on_a_eur_account_is_compared_after_conversion(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-08');
        $eur = $this->adAccount('EUR');
        [, $c] = $this->campaign($eur, 'EU daily');
        // 12 EUR yesterday = 13.44 USD: above a 13.33 USD limit. Compared raw (12) it would not be.
        $this->moneyRow($eur, $c, 12.0, Carbon::now('Asia/Riyadh')->subDay()->toDateString());

        $rule = fn (array $over) => (string) $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules", array_replace([
            'name' => 'Spend cap', 'enabled' => true, 'mode' => 'monitor', 'level' => 'campaign', 'logic' => 'all',
            'conditions' => [['metric' => 'spend', 'operator' => 'gt', 'value' => 13.33, 'window_days' => 7]],
            'action' => ['type' => 'notify'], 'schedule' => ['frequency' => 'daily', 'timezone' => 'Asia/Riyadh'], 'cooldown_hours' => 24,
        ], $over))->assertCreated()->json('data.id');
        $dry = fn (string $id) => collect($this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk()->json('data.results'))->keyBy('name')['EU daily'];

        $usd = $dry($rule(['currency' => 'USD']));
        $this->assertTrue($usd['matched']);
        $this->assertEqualsWithDelta(13.44, $usd['readings'][0]['value'], 0.001);
        $this->assertSame('USD', $usd['readings'][0]['currency']);

        // The same rule in SAR: 13.44 USD is 50.40 SAR at the peg; a 50 SAR limit is crossed.
        $sar = $dry($rule(['currency' => 'SAR', 'conditions' => [['metric' => 'spend', 'operator' => 'gt', 'value' => 50, 'window_days' => 7]]]));
        $this->assertTrue($sar['matched']);
        $this->assertEqualsWithDelta(50.40, $sar['readings'][0]['value'], 0.01);
        $this->assertSame('SAR', $sar['readings'][0]['currency']);
    }

    public function test_a_rule_never_compares_money_with_a_withheld_part(): void
    {
        $gbp = $this->adAccount('GBP');
        [, $c] = $this->campaign($gbp, 'UK daily');
        $this->moneyRow($gbp, $c, 500.0, Carbon::now('Asia/Riyadh')->subDay()->toDateString());

        $id = (string) $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules", [
            'name' => 'Cap', 'enabled' => true, 'mode' => 'monitor', 'level' => 'campaign', 'logic' => 'all',
            'conditions' => [['metric' => 'spend', 'operator' => 'lt', 'value' => 1000, 'window_days' => 7]],
            'action' => ['type' => 'notify'], 'schedule' => ['frequency' => 'daily', 'timezone' => 'Asia/Riyadh'], 'cooldown_hours' => 24,
        ])->assertCreated()->json('data.id');
        $r = collect($this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk()->json('data.results'))->keyBy('name')['UK daily'];

        $this->assertFalse($r['matched'], 'a withheld spend is not «0 < 1000»');
        $this->assertNull($r['readings'][0]['value']);
        $this->assertSame('no_rate_money', $r['readings'][0]['reason_code']);
    }

    public function test_the_production_report_is_count_only_and_anonymous(): void
    {
        $this->eurWorld();
        $this->assertSame(0, Artisan::call('fx:conversion-report', ['--json' => true, '--months' => '2026-11']));
        $out = json_decode(Artisan::output(), true);

        $this->assertSame(1, $out['accounts'][0]['account']);
        $this->assertSame('EUR', $out['accounts'][0]['currency']);
        $this->assertEquals(253, $out['accounts'][0]['raw_spend']);
        $this->assertSame(283.36, $out['accounts'][0]['converted_usd']);
        $this->assertSame(0, $out['accounts'][0]['stored_currency_not_account_currency']);
        $this->assertSame(0, $out['accounts'][0]['rate_differs_from_engine_today']);
        $this->assertEquals(253, $out['projects'][0]['before_raw_added']);
        $this->assertSame(283.36, $out['projects'][0]['after_converted_usd']);
        $text = Artisan::output();
        foreach ([$this->project->id, $this->project->name, $this->client->name, 'Account EUR', 'Search EU', $this->owner->email] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $text, 'no id, name or email');
        }
    }
}
