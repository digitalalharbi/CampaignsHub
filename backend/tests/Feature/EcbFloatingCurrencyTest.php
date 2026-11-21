<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\EcbReferenceRates;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MONEY-CURRENCY-NEVER-ASSUMED-001 / MONEY-FX-FLOATING-001 — a floating currency (EUR, TRY) converts to USD
 * through the ECB reference rates, from RECORDED publications, and a withheld row converts itself.
 *
 * The fixtures are the ECB's own files as published on 2026-11-04 (`eurofxref-daily.xml`, and the 90-day
 * history trimmed to its ten most recent days, values untouched). They settle, from the data rather than
 * from memory, which of Production's account currencies the ECB covers: USD and TRY yes (EUR is the base);
 * EGP and SAR no — the riyal is `OfficialPegs`' job, the Egyptian pound needs another source or a hand
 * entered rate in /admin.
 *
 * No live call is ever made here: every request is `Http::fake`d to the recorded file.
 */
final class EcbFloatingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const DAILY = __DIR__.'/../Fixtures/ecb/eurofxref-daily-2026-11-04.xml';

    private const HISTORY = __DIR__.'/../Fixtures/ecb/eurofxref-hist-90d-2026-11-04-trimmed.xml';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_recorded_publication_answers_eur_and_try_to_usd_and_omits_egp_and_sar(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        Http::fake(['www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response((string) file_get_contents(self::DAILY))]);

        $rates = collect((new EcbReferenceRates)->fetch([
            ['base' => 'EUR', 'quote' => 'USD'],
            ['base' => 'USD', 'quote' => 'EUR'],
            ['base' => 'TRY', 'quote' => 'USD'],
            ['base' => 'EGP', 'quote' => 'USD'],
            ['base' => 'SAR', 'quote' => 'USD'],
        ], Carbon::parse('2026-11-04')))->keyBy(fn (array $r): string => $r['base'].'→'.$r['quote']);

        // The publication of 2026-11-04: 1 EUR = 1.1267 USD, 1 EUR = 56.3598 TRY.
        $this->assertEqualsWithDelta(1.1267, $rates['EUR→USD']['rate'], 1e-9);
        $this->assertEqualsWithDelta(1 / 1.1267, $rates['USD→EUR']['rate'], 1e-9);
        $this->assertEqualsWithDelta(1.1267 / 56.3598, $rates['TRY→USD']['rate'], 1e-9, 'a cross through the euro');
        $this->assertSame('2026-11-04', $rates['EUR→USD']['rate_date']);
        $this->assertSame('2026-11-04', $rates['TRY→USD']['rate_date']);
        $this->assertArrayNotHasKey('EGP→USD', $rates->all(), 'the ECB does not publish the Egyptian pound');
        $this->assertArrayNotHasKey('SAR→USD', $rates->all(), 'the ECB does not publish the riyal; OfficialPegs does');
    }

    /**
     * The whole chain the Owner asked for, EUR → USD: a euro figure withheld on 2026-10-27, the scheduled
     * `fx:rates` with the ECB driver chosen, and `metrics:renormalise-currency` converting the row.
     *
     * The feed used to ask the source for ONE date — today's — so the first rate it recorded was dated
     * 2026-11-04 and «nearest on or before 2026-10-27» found nothing: the row stayed withheld for ever, with
     * a perfectly good rate sitting in the table. It now also asks for each pair's EARLIEST withheld day.
     */
    public function test_fx_rates_reaches_back_to_the_earliest_withheld_day_so_renormalise_converts_the_row(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        config()->set('fx.rates.driver', EcbReferenceRates::class);
        Http::fake([
            'www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response((string) file_get_contents(self::DAILY)),
            'www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml' => Http::response((string) file_get_contents(self::HISTORY)),
        ]);
        $rowId = $this->withheldEuroSpend('2026-10-27', 100.0);

        $this->assertSame(0, Artisan::call('fx:rates'));

        $recorded = CurrencyRate::query()->where('base_currency', 'EUR')->where('quote_currency', 'USD')->orderBy('rate_date')->get();
        $this->assertNotEmpty($recorded, 'the ECB answered and nothing was recorded');
        $this->assertTrue($recorded->every(fn (CurrencyRate $r): bool => $r->source === 'ecb_reference'));
        $this->assertLessThanOrEqual('2026-10-27', Carbon::parse((string) $recorded->first()->rate_date)->toDateString(), 'no rate reaches the withheld day');
        // The publication for 2026-10-27 in the recorded history: 1 EUR = 1.115 USD.
        $this->assertEqualsWithDelta(1.115, (float) $recorded->first()->rate, 1e-9);

        $this->assertSame(0, Artisan::call('metrics:renormalise-currency', ['--apply' => true]));

        $row = DB::table('daily_metrics')->where('id', $rowId)->first();
        $this->assertEqualsWithDelta(100.0 * 1.115, (float) $row->value, 1e-6);
        $this->assertSame('EUR', $row->original_currency);
        $this->assertSame('USD', $row->project_currency);
        $this->assertSame('ecb_reference', $row->rate_source);
    }

    private function withheldEuroSpend(string $date, float $amount): string
    {
        $tenant = Tenant::create(['name' => 'FX', 'slug' => 'fx-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        $id = (string) Str::uuid();
        DB::table('daily_metrics')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => $date, 'attribution_window' => 'default',
            'value' => null, 'original_amount' => $amount, 'original_currency' => 'EUR', 'project_currency' => 'USD',
            'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
