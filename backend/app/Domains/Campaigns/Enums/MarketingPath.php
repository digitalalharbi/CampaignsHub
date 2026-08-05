<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Which job a campaign is doing — the axis every report figure is separated along (REPORT-OBJECTIVE-002).
 *
 * Three paths, not fourteen objectives, because the objective is what the platform was told and the
 * PATH is what the money is for. Awareness money buys attention, traffic money buys visits, and
 * conversion money buys orders; mixing them produces a number that is not conservative, it is wrong.
 *
 * The rule this enum exists to make expressible: `total spend ÷ sales orders` is not a cost per
 * order. A brand campaign that ran all month and was never meant to sell anything has spend in that
 * numerator and no orders in the denominator, so the figure a client reads as «what an order costs
 * me» is inflated by an amount nobody can see, and they set next month's budget on it.
 *
 * `Conversion` covers leads as well as purchases. Both are a result somebody paid to cause, and both
 * belong in the denominator of a cost-per figure — they differ in WHICH result, which is why the
 * split service reports leads and orders separately inside the path rather than summing them.
 */
enum MarketingPath: string
{
    case Awareness = 'awareness';
    case Traffic = 'traffic';
    case Conversion = 'conversion';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }

    public function labels(): array
    {
        return match ($this) {
            self::Awareness => ['ar' => 'الوعي', 'en' => 'Awareness'],
            self::Traffic => ['ar' => 'الاهتمام والزيارات', 'en' => 'Interest & traffic'],
            self::Conversion => ['ar' => 'التحويل والمبيعات', 'en' => 'Conversion & sales'],
        };
    }

    /**
     * The metrics that mean something for this path.
     *
     * An awareness report leading with CPA is not a report with an extra column; it is a report
     * answering a question nobody asked of that money, and the answer is always terrible. This is
     * what lets a layout follow its scope instead of printing every card for every path.
     *
     * @return list<string>
     */
    public function headlineMetrics(): array
    {
        return match ($this) {
            self::Awareness => ['spend', 'impressions', 'reach', 'frequency', 'cpm'],
            self::Traffic => ['spend', 'clicks', 'ctr', 'cpc', 'landing_page_views', 'cost_per_lpv'],
            self::Conversion => ['spend', 'orders', 'cpa', 'revenue', 'roas', 'conversion_rate', 'aov'],
        };
    }
}
