<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\SharedCreativeView;
use App\Domains\Reports\Support\CreativeVisibility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CLIENT-REPORT-MONEY-REDACTION-001 — a hidden figure must take its ORIGINAL with it.
 *
 * ## The defect
 *
 * `SharedCreativeView`'s own docblock states the contract: «a client who opens the network tab must
 * not find the spend their agency chose not to show them». `redactRow()` honoured it for the
 * converted column — it unsets `metrics['spend']` and `metrics['reported']['spend']` — and did not
 * know about the six money-truth fields `CreativeMetrics::MONEY_TRUTH` has emitted alongside them
 * since CREATIVE-MONEY-TRUTH-001.
 *
 * On production that is not a corner: every Snapchat account is USD with no USD→SAR rate, so
 * `spend` is null by FX-001's design and the REAL amount lives in `spend_original`, with
 * `money_original_currency` naming it. A client link with spend withheld therefore shipped the
 * withheld amount, to the exact byte, in the payload — unsetting a null and leaving the figure.
 *
 * ## Why the UI was not showing it, and why that is not a defence
 *
 * Nothing rendered it, because every client-report surface read money through `metricState`, which
 * looks at the converted column alone — the same bug CONTENT-SPEND-ALWAYS-001 is about. So the two
 * defects were hiding each other: the payload leaked and the renderer could not read what leaked.
 * Fixing the renderer first would have turned a payload leak into a printed one, on a client's
 * report. This test exists so that cannot happen in either order.
 *
 * The `previous` bag is the same shape and was leaking the same way.
 */
final class ClientReportMoneyRedactionTest extends TestCase
{
    /** A production-shaped row: nothing converted, the originals intact. */
    private function row(): array
    {
        $money = [
            'spend' => null,
            'revenue' => null,
            'impressions' => 90_000,
            'spend_original' => 412.5,
            'revenue_original' => 1980.0,
            'spend_withheld_rows' => 3,
            'revenue_withheld_rows' => 3,
            'money_original_currency' => 'USD',
            'money_original_currencies' => 1,
            'reported' => ['spend' => true, 'revenue' => true, 'impressions' => true],
        ];

        return [
            'id' => 'cr-1',
            'name' => 'The film',
            'headline_metrics' => ['spend', 'revenue', 'roas', 'impressions'],
            'metrics' => $money,
            'previous' => $money,
        ];
    }

    private function visibility(array $over = []): CreativeVisibility
    {
        return CreativeVisibility::fromArray(array_merge([
            'creatives' => true,
            'spend' => false,
            'revenue' => false,
            'cpa' => false,
            'roas' => false,
        ], $over));
    }

    #[Test]
    public function a_hidden_spend_does_not_leave_its_original_amount_in_the_payload(): void
    {
        $out = app(SharedCreativeView::class)->redactRow($this->row(), $this->visibility());

        foreach (['metrics', 'previous'] as $bag) {
            $this->assertArrayNotHasKey('spend', $out[$bag], "«{$bag}» still carries the converted spend");
            $this->assertArrayNotHasKey('spend_original', $out[$bag], "«{$bag}» carries the withheld spend the agency hid");
            $this->assertArrayNotHasKey('spend_withheld_rows', $out[$bag], "«{$bag}» states how many rows the hidden spend came from");
        }
    }

    #[Test]
    public function a_hidden_revenue_does_not_leave_its_original_amount_in_the_payload(): void
    {
        $out = app(SharedCreativeView::class)->redactRow($this->row(), $this->visibility());

        foreach (['metrics', 'previous'] as $bag) {
            $this->assertArrayNotHasKey('revenue_original', $out[$bag], "«{$bag}» carries the withheld revenue");
            $this->assertArrayNotHasKey('revenue_withheld_rows', $out[$bag], "«{$bag}» states how many rows the hidden revenue came from");
        }
    }

    /**
     * The currency name goes only when NOTHING it could describe survives.
     *
     * It is shared by both money keys, so removing it whenever either is hidden would strip the
     * label off a figure the client is still entitled to read — and a withheld amount with no
     * currency is «412.50» of something, which is worse than either state.
     */
    #[Test]
    public function the_original_currency_survives_while_a_visible_money_figure_still_needs_it(): void
    {
        $out = app(SharedCreativeView::class)->redactRow($this->row(), $this->visibility(['spend' => true, 'cpa' => true]));

        $this->assertSame(412.5, $out['metrics']['spend_original'], 'spend is permitted on this link and lost its amount');
        $this->assertSame('USD', $out['metrics']['money_original_currency'], 'the visible spend lost the name of its currency');
        $this->assertArrayNotHasKey('revenue_original', $out['metrics'], 'revenue is hidden and kept its amount');
    }

    #[Test]
    public function the_original_currency_goes_when_both_money_figures_are_hidden(): void
    {
        $out = app(SharedCreativeView::class)->redactRow($this->row(), $this->visibility());

        foreach (['metrics', 'previous'] as $bag) {
            $this->assertArrayNotHasKey('money_original_currency', $out[$bag], "«{$bag}» names a currency for figures it does not carry");
            $this->assertArrayNotHasKey('money_original_currencies', $out[$bag], "«{$bag}» counts currencies for figures it does not carry");
        }
    }

    /** A link that withholds nothing must be left exactly as the aggregator wrote it. */
    #[Test]
    public function a_link_that_hides_no_money_changes_nothing(): void
    {
        $visibility = $this->visibility(['spend' => true, 'revenue' => true, 'cpa' => true, 'roas' => true]);

        $out = app(SharedCreativeView::class)->redactRow($this->row(), $visibility);

        $this->assertSame($this->row()['metrics'], $out['metrics']);
        $this->assertSame($this->row()['previous'], $out['previous']);
    }
}
