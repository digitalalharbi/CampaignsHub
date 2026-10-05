<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\ResultAvailability;
use App\Domains\Campaigns\Services\CreativeMetrics;
use Tests\TestCase;

/**
 * CONTENT-RESULT-ATTRIBUTION-001 — «النتائج لا تظهر على المحتويات، أي الطلبات على كل محتوى».
 *
 * The content library exists to answer one question about a sales creative: did it sell. On the
 * owner's own account it was not asking it.
 *
 * `headline()` drops a metric the row cannot answer, and that rule is right — a cell that will never
 * fill is a promise nothing keeps. But it was asked of the VALUE alone, and a null value is three
 * different situations this service already tells apart. A sales creative whose orders are reported
 * only ABOVE its grain — the platform counts them for the campaign, not per ad — has a null, and had
 * `orders` struck from its headline before `availability` could say why. The card then fell back to
 * spend, impressions and clicks: the figures of a brand creative, on a creative bought to sell.
 *
 * Allocating campaign orders across creatives is forbidden and is NOT the fix. The cell carries the
 * verdict — «لا يمكن إسناد النتيجة لهذا المحتوى» — which is an answer, not a number.
 */
final class CreativeResultIsSpokenTest extends TestCase
{
    private CreativeMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(CreativeMetrics::class);
    }

    /**
     * A sales creative whose orders live only at CAMPAIGN grain still says so.
     *
     * The figure exists for this account in this window, over ads this creative's own rows do not
     * account for. That is `not_attributable`, and it is the single most important thing the card
     * can tell the reader about this creative.
     */
    public function test_orders_stay_on_a_sales_card_when_they_cannot_be_attributed(): void
    {
        $headline = $this->metrics->headline('sales', $this->row(ResultAvailability::NotAttributable));

        $this->assertContains('orders', $headline, 'the card dropped the one figure that answers «did it sell»');
    }

    /** The same for an account whose conversion measurement nobody has verified. */
    public function test_orders_stay_when_the_account_measurement_is_unverified(): void
    {
        $headline = $this->metrics->headline('sales', $this->row(ResultAvailability::MeasurementUnverified));

        $this->assertContains('orders', $headline);
    }

    /**
     * A metric the platform simply does not send stays OFF the card.
     *
     * This is the rule that removed four «—» cells from every sales creative, and it is not being
     * relaxed: `not_reported` has nothing to say, and a cell that says nothing is the defect.
     */
    public function test_a_metric_the_platform_never_sends_is_still_dropped(): void
    {
        $headline = $this->metrics->headline('sales', $this->row(ResultAvailability::NotReported));

        $this->assertNotContains('orders', $headline);
    }

    /** A measured zero is a measurement: it was always answerable and still is. */
    public function test_a_measured_zero_order_count_is_a_figure(): void
    {
        $figures = $this->row(ResultAvailability::RealZeroConfirmed);
        $figures['conversions'] = 0.0;
        $figures['orders'] = 0.0;
        $figures['reported']['orders'] = true;

        $this->assertContains('orders', $this->metrics->headline('sales', $figures));
    }

    /** And a reported count, which is the ordinary case and must not regress. */
    public function test_a_reported_order_count_is_a_figure(): void
    {
        $figures = $this->row(ResultAvailability::ReportedValue);
        $figures['conversions'] = 31.0;
        $figures['orders'] = 31.0;
        $figures['reported']['orders'] = true;

        $this->assertContains('orders', $this->metrics->headline('sales', $figures));
    }

    /**
     * OBJECTIVE-AWARE-KPI-001 — a brand creative is not judged on orders, verdict or no verdict.
     *
     * The account may well have orders it cannot attribute; that is not what an awareness buy was
     * bought to do, and putting the sentence on its card would judge it by another objective.
     */
    public function test_an_awareness_creative_is_not_given_orders(): void
    {
        $headline = $this->metrics->headline('brand_awareness', $this->row(ResultAvailability::NotAttributable));

        $this->assertNotContains('orders', $headline);
        $this->assertContains('impressions', $headline);
    }

    /**
     * One creative's verdict is not another's.
     *
     * `availability` is keyed per metric, so a row whose ORDERS cannot be attributed but whose
     * revenue was never reported keeps the first and drops the second — the states do not collapse
     * into one «unavailable» for the whole card.
     */
    public function test_the_verdict_is_per_metric_not_per_card(): void
    {
        $figures = $this->row(ResultAvailability::NotAttributable);
        $figures['availability']['revenue'] = ResultAvailability::NotReported->value;

        $headline = $this->metrics->headline('sales', $figures);

        $this->assertContains('orders', $headline);
        $this->assertNotContains('revenue', $headline);
    }

    /**
     * A sales creative with figures it CAN answer, and orders it cannot attribute.
     *
     * @return array<string, mixed>
     */
    private function row(ResultAvailability $ordersVerdict): array
    {
        return [
            'grain' => 'creative',
            'spend' => 1200.0,
            'impressions' => 90000.0,
            'clicks' => 1800.0,
            'conversions' => null,
            'orders' => null,
            'revenue' => null,
            'reported' => ['orders' => false, 'conversions' => false, 'revenue' => false],
            'availability' => [
                'orders' => $ordersVerdict->value,
                'conversions' => $ordersVerdict->value,
                'revenue' => ResultAvailability::NotReported->value,
            ],
        ];
    }
}
