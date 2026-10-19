<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Rates\EcbReferenceRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * MONEY-FX-FLOATING-001 — the ECB reference rates, as an opt-in source: direct and inverse pairs against the
 * euro, a cross through the euro, the publication's own date (never a re-dated one), and a currency the ECB
 * does not publish is omitted rather than guessed.
 */
final class EcbReferenceRatesTest extends TestCase
{
    private function xml(array $days): string
    {
        $cubes = '';
        foreach ($days as $date => $rates) {
            $cubes .= '<Cube time="'.$date.'">';
            foreach ($rates as $currency => $rate) {
                $cubes .= '<Cube currency="'.$currency.'" rate="'.$rate.'"/>';
            }
            $cubes .= '</Cube>';
        }

        return '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube>'.$cubes.'</Cube></gesmes:Envelope>';
    }

    public function test_it_answers_direct_inverse_and_cross_pairs_from_one_publication(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        Http::fake(['*eurofxref-daily.xml' => Http::response($this->xml(['2026-10-16' => ['USD' => '1.0800', 'GBP' => '0.8400']]))]);

        $rates = collect((new EcbReferenceRates)->fetch([
            ['base' => 'EUR', 'quote' => 'USD'],
            ['base' => 'USD', 'quote' => 'EUR'],
            ['base' => 'GBP', 'quote' => 'USD'],
            ['base' => 'SAR', 'quote' => 'USD'],
        ], Carbon::parse('2026-10-16')))->keyBy(fn ($r) => $r['base'].$r['quote']);

        $this->assertEqualsWithDelta(1.08, $rates['EURUSD']['rate'], 1e-9);
        $this->assertEqualsWithDelta(1 / 1.08, $rates['USDEUR']['rate'], 1e-9);
        $this->assertEqualsWithDelta(1.08 / 0.84, $rates['GBPUSD']['rate'], 1e-9);
        $this->assertSame('2026-10-16', $rates['EURUSD']['rate_date']);
        $this->assertArrayNotHasKey('SARUSD', $rates->all(), 'a currency the ECB does not publish is omitted, never guessed');
        Carbon::setTestNow();
    }

    public function test_a_weekend_is_answered_with_the_publication_it_really_is(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');
        Http::fake(['*eurofxref-daily.xml' => Http::response($this->xml(['2026-10-16' => ['USD' => '1.0800']]))]);

        $rates = (new EcbReferenceRates)->fetch([['base' => 'EUR', 'quote' => 'USD']], Carbon::parse('2026-10-18'));

        $this->assertSame('2026-10-16', $rates[0]['rate_date'], 'the date asked for is not stamped onto an older publication');
        Carbon::setTestNow();
    }

    public function test_a_past_date_is_read_from_the_ninety_day_history(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        Http::fake(['*eurofxref-hist-90d.xml' => Http::response($this->xml(['2026-10-16' => ['USD' => '1.0900'], '2026-10-01' => ['USD' => '1.0500']]))]);

        $rates = (new EcbReferenceRates)->fetch([['base' => 'EUR', 'quote' => 'USD']], Carbon::parse('2026-10-05'));

        $this->assertEqualsWithDelta(1.05, $rates[0]['rate'], 1e-9);
        $this->assertSame('2026-10-01', $rates[0]['rate_date']);
        Carbon::setTestNow();
    }

    public function test_a_refused_publication_raises_so_the_feed_reports_the_gap_instead_of_writing_a_zero(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->expectException(RuntimeException::class);
        (new EcbReferenceRates)->fetch([['base' => 'EUR', 'quote' => 'USD']], Carbon::today());
    }

    public function test_it_names_itself_so_every_figure_traces_back(): void
    {
        $source = new EcbReferenceRates;

        $this->assertSame('ecb_reference', $source->key());
        $this->assertTrue($source->isConfigured());
        $this->assertSame([], $source->fetch([], Carbon::today()));
    }
}
