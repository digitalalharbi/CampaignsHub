<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Metrics\Fx\FxConversion;
use App\Domains\Metrics\Fx\FxEngine;
use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsFxWorld;
use Tests\TestCase;

/**
 * FX-ENGINE-001 — one `convert(amount, from, to, date)`, base USD, the rate in force on the amount's own date.
 */
final class FxEngineTest extends TestCase
{
    use BuildsFxWorld;
    use RefreshDatabase;

    private function engine(): FxEngine
    {
        return new FxEngine(new CurrencyConverter);
    }

    private function rate(string $base, string $quote, float $rate, string $date, string $kind = CurrencyRate::MANUAL): void
    {
        app(RateLedger::class)->record($base, $quote, $rate, $date, $kind);
    }

    /**
     * The confirmed defect, as a fixture: a Google account billed in EUR, 253 spent, EUR→USD 1.12 recorded. The same
     * figure must never come out as «253» in dollars — it is 283.36 USD, with the rate that made it.
     */
    public function test_253_eur_at_1_12_is_283_36_usd_and_never_253_dollars(): void
    {
        $this->fxAdAccount('EUR');
        $this->rate('EUR', 'USD', 1.12, '2026-11-10');

        $c = $this->engine()->convert(253, 'EUR', 'USD', '2026-11-10');

        $this->assertFalse($c->withheld);
        $this->assertSame(283.36, round((float) $c->converted, 2));
        $this->assertNotSame(253.0, round((float) $c->converted, 2));
        $this->assertSame(1.12, $c->rate);
        $this->assertSame('2026-11-10', $c->rateDate);
        $this->assertSame('manual', $c->kind);
        $this->assertSame(['amount' => 253.0, 'currency' => 'EUR', 'date' => '2026-11-10'], $c->original->toArray());
        $this->assertSame('fx.convert', $c->toArray()['via']);
    }

    public function test_eur_to_sar_goes_through_usd_with_the_sar_peg(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-10');
        // The SAR peg (0.266666666667, SAMA) is a platform pegged row from the migration of the workspace default.
        $this->assertTrue(CurrencyRate::query()->where('base_currency', 'SAR')->where('quote_currency', 'USD')->where('kind', 'pegged')->exists());

        $c = $this->engine()->convert(253, 'EUR', 'SAR', '2026-11-10');

        $this->assertSame(1062.60, round((float) $c->converted, 2));
        $this->assertSame('cross', $c->kind);
        $this->assertCount(2, $c->legs);
        $this->assertSame('2026-11-10', $c->rateDate, 'a cross is no fresher than its older leg; the peg is undated');
    }

    public function test_the_rate_in_force_on_the_amounts_own_date_is_used_not_the_latest(): void
    {
        $this->rate('EUR', 'USD', 1.10, '2026-11-06');
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');

        $this->assertSame(1.10, $this->engine()->convert(100, 'EUR', 'USD', '2026-11-06')->rate);
        $this->assertSame(1.10, $this->engine()->convert(100, 'EUR', 'USD', '2026-11-08')->rate, 'nearest earlier within 7 days');
        $this->assertSame(1.12, $this->engine()->convert(100, 'EUR', 'USD', '2026-11-09')->rate);
        $this->assertSame(1.12, $this->engine()->convert(100, 'EUR', 'USD', '2026-11-16')->rate);
        $this->assertFalse($this->engine()->convert(100, 'EUR', 'USD', '2026-11-16')->stale, '7 days old is still valid');
    }

    public function test_a_rate_older_than_seven_days_is_used_and_flagged_stale(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-01');

        $c = $this->engine()->convert(100, 'EUR', 'USD', '2026-11-10');

        $this->assertFalse($c->withheld);
        $this->assertTrue($c->stale);
        $this->assertSame(112.0, round((float) $c->converted, 2));
    }

    public function test_no_rate_withholds_the_figure_never_zero_never_the_raw_number(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-09');

        $c = $this->engine()->convert(253, 'EUR', 'USD', '2026-11-05');

        $this->assertTrue($c->withheld);
        $this->assertNull($c->converted);
        $this->assertSame('بانتظار سعر صرف (EUR)', $c->pendingLabel());
        $this->assertSame(FxConversion::RATES_PAGE, $c->toArray()['rates_page']);
        $this->assertNull($c->toArray()['converted_amount']);
    }

    /**
     * PROJECT-LIFECYCLE-001 §7 (Owner spec 2026-11-15) supersedes «past its end date → withheld»: an expired manual rate is the
     * last rate recorded, so it is USED, flagged stale + expired internally (never worded to a client, Owner 2026-11-19), never left to withhold the spend.
     */
    public function test_a_rate_past_its_end_date_is_used_and_flagged_as_an_old_rate(): void
    {
        app(RateLedger::class)->record('EUR', 'USD', 1.12, '2026-11-01', CurrencyRate::MANUAL, effectiveTo: '2026-11-03');

        $inForce = $this->engine()->convert(1, 'EUR', 'USD', '2026-11-03');
        $this->assertFalse($inForce->withheld);
        $this->assertFalse($inForce->expired);

        $expired = $this->engine()->convert(100, 'EUR', 'USD', '2026-11-04');
        $this->assertFalse($expired->withheld);
        $this->assertTrue($expired->stale);
        $this->assertTrue($expired->expired);
        $this->assertEqualsWithDelta(112.0, $expired->converted, 0.0001);
        $this->assertSame('2026-11-01', $expired->rateDate);
        $this->assertArrayNotHasKey('stale_label', $expired->toArray());
        $this->assertStringNotContainsString('سعر قديم', json_encode($expired->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_pegged_rate_applies_to_every_date_and_is_never_stale(): void
    {
        $this->rate('AED', 'USD', 1 / 3.6725, '2026-11-10', CurrencyRate::PEGGED);

        $early = $this->engine()->convert(100, 'AED', 'USD', '2020-01-01');
        $late = $this->engine()->convert(100, 'AED', 'USD', '2027-03-01');

        $this->assertFalse($early->withheld);
        $this->assertFalse($late->stale);
        $this->assertSame('pegged', $late->kind);
        $this->assertSame(27.23, round((float) $early->converted, 2));
    }

    public function test_a_manual_rate_wins_over_an_automatic_one_for_the_same_day(): void
    {
        $this->rate('EUR', 'USD', 1.12, '2026-11-10', CurrencyRate::MANUAL);
        $result = app(RateLedger::class)->record('EUR', 'USD', 1.08, '2026-11-10', CurrencyRate::AUTOMATIC, 'ecb_reference');

        $this->assertSame('shadowed', $result['outcome']);
        $this->assertSame(1.12, $this->engine()->convert(1, 'EUR', 'USD', '2026-11-10')->rate);
        $this->assertSame(2, DB::table('currency_rate_versions')->where('base_currency', 'EUR')->count(), 'the automatic quote is kept, not dropped');

        // …and an automatic rate is replaced by a manual one for the same day.
        app(RateLedger::class)->record('GBP', 'USD', 1.25, '2026-11-10', CurrencyRate::AUTOMATIC, 'ecb_reference');
        app(RateLedger::class)->record('GBP', 'USD', 1.30, '2026-11-10', CurrencyRate::MANUAL);
        $this->assertSame(1.30, $this->engine()->convert(1, 'GBP', 'USD', '2026-11-10')->rate);
    }

    public function test_a_rate_edit_creates_a_new_version_and_an_audit_row_never_an_overwrite(): void
    {
        $admin = $this->fxAdmin();
        app(RateLedger::class)->record('EUR', 'USD', 1.10, '2026-11-10', CurrencyRate::MANUAL, by: $admin, note: 'ref A');
        $edit = app(RateLedger::class)->record('EUR', 'USD', 1.12, '2026-11-10', CurrencyRate::MANUAL, by: $admin, note: 'ref B');

        $this->assertSame(2, $edit['version']);
        $versions = app(RateLedger::class)->history('EUR', 'USD');
        $this->assertCount(2, $versions);
        $this->assertSame([1.12, 1.10], array_column($versions, 'rate'));
        $this->assertSame(1.10, $versions[0]['previous_rate']);
        $this->assertSame('fx-admin@example.test', $versions[0]['recorded_by']);
        $this->assertSame(1, CurrencyRate::query()->where('base_currency', 'EUR')->count(), 'one live row per pair and day');

        $audit = AuditLog::query()->where('action', 'fx.rate.edited')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertEquals(1.10, $audit->before['rate']);
        $this->assertEquals(1.12, $audit->after['rate']);
    }
}
