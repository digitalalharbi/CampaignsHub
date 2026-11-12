<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Support\ObjectiveResult;
use PHPUnit\Framework\TestCase;

/**
 * OBJECTIVE-RESULT-001 — the one table that decides what «النتائج» is for each objective.
 *
 * The Production case it was written from: 1KCOFFEE, Snapchat TRAFFIC, 2,881 landing page views,
 * 18,464 clicks, 0 conversions — every surface read conversions and printed «النتائج 0».
 */
final class ObjectiveResultTest extends TestCase
{
    private const COFFEE = ['spend' => 1440.5, 'spend_withheld_rows' => 0, 'clicks' => 18464, 'landing_page_views' => 2881, 'conversions' => 0, 'impressions' => 900000];

    public function test_a_traffic_campaign_is_judged_on_its_landing_page_views_not_on_orders(): void
    {
        $r = ObjectiveResult::resolve(ObjectiveFamily::Traffic, self::COFFEE, ['landing_page_views' => true, 'clicks' => true, 'conversions' => true]);

        $this->assertSame('landing_page_views', $r['result_key']);
        $this->assertSame(2881.0, $r['result']);
        $this->assertSame('زيارات الصفحة', $r['result_label']['ar']);
        $this->assertSame(round(1440.5 / 2881, 2), $r['cost_per_result']);
        $this->assertSame('تكلفة الزيارة', $r['cost_per_result_label']['ar']);
        $this->assertNull($r['result_missing_reason']);
    }

    /** «LPV عند توفره وإلا النقرات مع تسميتها صراحةً «نقرات»» — never called visits. */
    public function test_traffic_without_landing_page_views_falls_back_to_clicks_named_clicks(): void
    {
        $row = ['landing_page_views' => 0] + self::COFFEE;

        foreach ([null, ['landing_page_views' => false, 'clicks' => true], ['landing_page_views' => true, 'clicks' => true]] as $reported) {
            $r = ObjectiveResult::resolve('traffic', $row, $reported);

            $this->assertSame('clicks', $r['result_key']);
            $this->assertSame(18464.0, $r['result']);
            $this->assertSame('النقرات', $r['result_label']['ar']);
            $this->assertStringNotContainsString('زيارات', $r['result_label']['ar']);
            $this->assertSame('تكلفة النقرة (CPC)', $r['cost_per_result_label']['ar']);
        }
    }

    public function test_every_family_names_its_own_result(): void
    {
        $row = ['reach' => 10, 'impressions' => 20, 'landing_page_views' => 3, 'clicks' => 4, 'engagements' => 5, 'video_views' => 6, 'leads' => 7, 'form_leads' => 1, 'conversions' => 8, 'installs' => 9, 'spend' => 100];

        $expected = [
            'awareness' => ['reach', 10.0], 'traffic' => ['landing_page_views', 3.0], 'engagement' => ['engagements', 5.0],
            'video' => ['video_views', 6.0], 'leads' => ['leads', 7.0], 'sales' => ['conversions', 8.0], 'app' => ['installs', 9.0],
        ];

        foreach ($expected as $family => [$key, $value]) {
            $r = ObjectiveResult::resolve($family, $row);
            $this->assertSame($key, $r['result_key'], $family);
            $this->assertSame($value, $r['result'], $family);
            $this->assertNotNull($r['result_label'], $family);
        }

        // Every family in the enum has a row in the table — a family added later fails here.
        $this->assertSame(ObjectiveFamily::values(), array_keys(ObjectiveResult::TABLE));
    }

    public function test_awareness_without_a_stated_reach_reads_impressions_priced_per_thousand(): void
    {
        $r = ObjectiveResult::resolve('awareness', ['reach' => null, 'impressions' => 50000, 'spend' => 500]);

        $this->assertSame('impressions', $r['result_key']);
        $this->assertSame(10.0, $r['cost_per_result']);
        $this->assertSame('تكلفة الألف ظهور (CPM)', $r['cost_per_result_label']['ar']);
    }

    public function test_leads_fall_back_to_form_leads(): void
    {
        $r = ObjectiveResult::resolve('leads', ['leads' => 0, 'form_leads' => 12, 'spend' => 120]);

        $this->assertSame('form_leads', $r['result_key']);
        $this->assertSame(10.0, $r['cost_per_result']);
    }

    /** «مهتمين = النماذج + أحداث صفحة الهبوط» — a leads campaign's landing-page conversions are its leads, never «الطلبات». */
    public function test_leads_reported_only_as_landing_page_conversions_are_landing_page_leads(): void
    {
        $r = ObjectiveResult::resolve('leads', ['leads' => 0, 'form_leads' => 0, 'conversions' => 62, 'spend' => 620]);

        $this->assertSame('conversions', $r['result_key']);
        $this->assertSame(62.0, $r['result']);
        $this->assertSame('مهتمين الهبوط', $r['result_label']['ar']);
        $this->assertSame('تكلفة المهتم (CPL)', $r['cost_per_result_label']['ar']);
        $this->assertSame(10.0, $r['cost_per_result']);

        // The same key on a sales campaign is still an order.
        $this->assertSame('الطلبات', ObjectiveResult::resolve('sales', ['conversions' => 3])['result_label']['ar']);
    }

    public function test_an_unknown_objective_has_no_result_and_says_why(): void
    {
        foreach ([null, 'other', 'store_visits', 'SALES'] as $objective) {
            $family = ObjectiveResult::familyOf($objective);
            $r = ObjectiveResult::resolve($family, self::COFFEE);

            $this->assertSame(ObjectiveFamily::Unknown, $family, (string) $objective);
            $this->assertNull($r['result_key']);
            $this->assertNull($r['result']);
            $this->assertNull($r['cost_per_result']);
            $this->assertSame('objective_unknown', $r['result_missing_reason']);
            $this->assertSame('الهدف غير محدد', $r['result_missing_label']['ar']);
        }

        $this->assertSame(ObjectiveFamily::Traffic, ObjectiveResult::familyOf(CampaignObjective::Traffic->value));
    }

    /** A metric nobody sent is «بدون بيانات», never a zero result. */
    public function test_an_unreported_result_is_no_data_not_zero(): void
    {
        $r = ObjectiveResult::resolve('app', ['installs' => 0, 'spend' => 50], ['installs' => false]);

        $this->assertNull($r['result']);
        $this->assertSame('not_reported', $r['result_missing_reason']);

        $zero = ObjectiveResult::resolve('app', ['installs' => 0, 'spend' => 50], ['installs' => true]);
        $this->assertSame(0.0, $zero['result']);
        $this->assertNull($zero['cost_per_result']);
        $this->assertNull($zero['result_missing_reason']);
    }

    public function test_no_cost_is_stated_over_withheld_money(): void
    {
        $r = ObjectiveResult::resolve('traffic', ['spend_withheld_rows' => 2] + self::COFFEE);

        $this->assertSame(2881.0, $r['result']);
        $this->assertNull($r['cost_per_result']);
    }

    /** Unlike units are never added: one campaign's visits and another's clicks total as clicks. */
    public function test_a_family_total_keeps_one_unit(): void
    {
        $withLpv = ['landing_page_views' => 100, 'clicks' => 400, 'spend' => 50];
        $clicksOnly = ['landing_page_views' => 0, 'clicks' => 300, 'spend' => 50];

        $same = ObjectiveResult::combine('traffic', [$withLpv, $withLpv]);
        $this->assertSame('landing_page_views', $same['result_key']);
        $this->assertSame(200.0, $same['result']);

        $mixed = ObjectiveResult::combine('traffic', [$withLpv, $clicksOnly]);
        $this->assertSame('clicks', $mixed['result_key']);
        $this->assertSame(700.0, $mixed['result']);
        $this->assertSame(round(100 / 700, 2), $mixed['cost_per_result']);

        $leads = ObjectiveResult::combine('leads', [['leads' => 3, 'spend' => 1], ['leads' => 0, 'form_leads' => 2, 'spend' => 1]]);
        $this->assertSame('leads', $leads['result_key']);
        $this->assertSame(5.0, $leads['result']);

        // Reach and impressions are not one unit: the total is impressions for every row.
        $aware = ObjectiveResult::combine('awareness', [['reach' => 10, 'impressions' => 40], ['reach' => null, 'impressions' => 60]]);
        $this->assertSame('impressions', $aware['result_key']);
        $this->assertSame(100.0, $aware['result']);
    }
}
