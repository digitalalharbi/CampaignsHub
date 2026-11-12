<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Metrics\Support\FinancialRatios;

/**
 * OBJECTIVE-RESULT-001 — the ONE table that says what «النتائج» means for a campaign.
 *
 * ## The defect
 *
 * Every surface read `conversions` and called it «النتائج». A traffic campaign is bought for visits,
 * not orders, so 1KCOFFEE — Snapchat TRAFFIC, 2,881 landing page views and 18,464 clicks in thirty
 * days — showed «النتائج 0» and «لا نتائج في الفترة» on its own page. Fifteen campaigns on
 * Production were in that state while their own objective's metric was above zero.
 *
 * ## The rule (Owner, MASTER_FINAL §هـ and Appendix A task 1)
 *
 *   - awareness  → reach; impressions when reach is not stated (reach is never additive)
 *   - traffic    → landing page views when reported and above zero, else clicks, NAMED «النقرات»
 *   - engagement → engagements
 *   - video      → video views
 *   - leads      → leads, else the platform's form leads
 *   - sales      → orders (`conversions`, the product's one definition of an order — KPI-PARITY-001);
 *                  revenue and ROAS belong to this family only
 *   - app        → installs
 *   - unknown    → no result, and the reason «الهدف غير محدد»
 *
 * Unlike objectives are never summed into one «النتائج»: {@see combine()} refuses a total whose
 * rows resolved to different units, and keeps the family's fallback only where every row has it.
 *
 * `frontend/src/features/campaigns/campaignResult.ts` mirrors this table for the screens that read
 * totals rather than these rows; `campaignResult.test.ts` and `ObjectiveResultTest` hold them to
 * the same cases.
 */
final class ObjectiveResult
{
    /**
     * family => the result key, the key used when the first is absent, and the cost unit
     * (per one, or per thousand for an awareness buy, which is priced per thousand).
     *
     * `then` is a second fallback, used by leads only: the Owner's own definition of a lead is «النماذج +
     * أحداث صفحة الهبوط (مهتمين الهبوط)», so a leads campaign whose platform reports its landing-page
     * events as conversions has those as its result — named «مهتمين الهبوط», never «الطلبات».
     *
     * @var array<string, array{key: string|null, fallback: string|null, per: float, then?: string}>
     */
    public const TABLE = [
        'awareness' => ['key' => 'reach', 'fallback' => 'impressions', 'per' => 1000.0],
        'traffic' => ['key' => 'landing_page_views', 'fallback' => 'clicks', 'per' => 1.0],
        'engagement' => ['key' => 'engagements', 'fallback' => null, 'per' => 1.0],
        'video' => ['key' => 'video_views', 'fallback' => null, 'per' => 1.0],
        'leads' => ['key' => 'leads', 'fallback' => 'form_leads', 'per' => 1.0, 'then' => 'conversions'],
        'sales' => ['key' => 'conversions', 'fallback' => null, 'per' => 1.0],
        'app' => ['key' => 'installs', 'fallback' => null, 'per' => 1.0],
        'unknown' => ['key' => null, 'fallback' => null, 'per' => 1.0],
    ];

    /**
     * The words for each result key and for its cost — the style guide's names (Appendix A).
     *
     * @var array<string, array{ar: string, en: string, cost_ar: string, cost_en: string}>
     */
    public const LABELS = [
        'reach' => ['ar' => 'الوصول', 'en' => 'Reach', 'cost_ar' => 'تكلفة الألف وصول', 'cost_en' => 'Cost per 1,000 reached'],
        'impressions' => ['ar' => 'الظهور', 'en' => 'Impressions', 'cost_ar' => 'تكلفة الألف ظهور (CPM)', 'cost_en' => 'Cost per 1,000 impressions (CPM)'],
        'landing_page_views' => ['ar' => 'زيارات الصفحة', 'en' => 'Landing page views', 'cost_ar' => 'تكلفة الزيارة', 'cost_en' => 'Cost per visit'],
        'clicks' => ['ar' => 'النقرات', 'en' => 'Clicks', 'cost_ar' => 'تكلفة النقرة (CPC)', 'cost_en' => 'Cost per click (CPC)'],
        'engagements' => ['ar' => 'التفاعلات', 'en' => 'Engagements', 'cost_ar' => 'تكلفة التفاعل (CPE)', 'cost_en' => 'Cost per engagement (CPE)'],
        'video_views' => ['ar' => 'المشاهدات', 'en' => 'Video views', 'cost_ar' => 'تكلفة المشاهدة (CPV)', 'cost_en' => 'Cost per view (CPV)'],
        'leads' => ['ar' => 'المهتمين', 'en' => 'Leads', 'cost_ar' => 'تكلفة المهتم (CPL)', 'cost_en' => 'Cost per lead (CPL)'],
        'form_leads' => ['ar' => 'مهتمين النماذج', 'en' => 'Form leads', 'cost_ar' => 'تكلفة المهتم (CPL)', 'cost_en' => 'Cost per lead (CPL)'],
        'conversions' => ['ar' => 'الطلبات', 'en' => 'Orders', 'cost_ar' => 'تكلفة الطلب (CPA)', 'cost_en' => 'Cost per order (CPA)'],
        'installs' => ['ar' => 'التثبيتات', 'en' => 'Installs', 'cost_ar' => 'تكلفة التثبيت (CPI)', 'cost_en' => 'Cost per install (CPI)'],
    ];

    /** A key whose meaning depends on the family: a leads campaign's conversions are landing-page leads. */
    private const FAMILY_LABELS = [
        'leads' => ['conversions' => ['ar' => 'مهتمين الهبوط', 'en' => 'Landing page leads', 'cost_ar' => 'تكلفة المهتم (CPL)', 'cost_en' => 'Cost per lead (CPL)']],
    ];

    /** @var array<string, array{ar: string, en: string}> */
    public const MISSING = [
        'objective_unknown' => ['ar' => 'الهدف غير محدد', 'en' => 'Objective not set'],
        'not_reported' => ['ar' => 'بدون بيانات: المنصة لم ترسل هذا المؤشر', 'en' => 'No data: the platform did not send this metric'],
        'mixed' => ['ar' => 'أهداف مختلفة: كل حملة بنتيجتها', 'en' => 'Different objectives: each campaign has its own result'],
    ];

    /** The family a stored objective value belongs to; anything unrecognised is Unknown, never guessed. */
    public static function familyOf(?string $objective): ObjectiveFamily
    {
        if ($objective === null || $objective === '') {
            return ObjectiveFamily::Unknown;
        }

        return CampaignObjective::tryFrom($objective)?->family() ?? ObjectiveFamily::Unknown;
    }

    /**
     * The result of ONE row (a campaign, a creative, a family total) for its family.
     *
     * `$reported` is the row's own «which keys did a platform send» map when the caller has it.
     * Without it, a positive figure counts as reported and a zero stays a zero — the caller has no
     * way to tell the two apart, and inventing «not reported» would be the opposite guess.
     *
     * @param  array<string, mixed>  $row  base sums, plus `spend` and `spend_withheld_rows` when known
     * @param  array<string, bool>|null  $reported
     * @return array{result_family: string, result_key: string|null, result: float|null, result_label: array{ar: string, en: string}|null, cost_per_result: float|null, cost_per_result_label: array{ar: string, en: string}|null, result_missing_reason: string|null, result_missing_label: array{ar: string, en: string}|null}
     */
    public static function resolve(ObjectiveFamily|string $family, array $row, ?array $reported = null): array
    {
        $family = $family instanceof ObjectiveFamily ? $family : (ObjectiveFamily::tryFrom($family) ?? ObjectiveFamily::Unknown);
        $spec = self::TABLE[$family->value];

        if ($spec['key'] === null) {
            return self::shape($family, null, null, $row, 'objective_unknown');
        }

        $primary = $spec['key'];
        $fallbacks = array_values(array_filter([$spec['fallback'], $spec['then'] ?? null]));
        $value = static fn (string $k): ?float => isset($row[$k]) && is_numeric($row[$k]) ? (float) $row[$k] : null;
        $flag = static fn (string $k): ?bool => $reported === null ? null : ($reported[$k] ?? false) === true;

        // The objective's own figure whenever it is above zero, else its stated fallbacks in order —
        // the traffic rule: LPV when reported and above zero, else clicks.
        foreach ([$primary, ...$fallbacks] as $k) {
            if (($value($k) ?? 0.0) > 0) {
                return self::shape($family, $k, $value($k), $row, null);
            }
        }

        // A reported zero is a real measurement and is said as one.
        foreach ([$primary, ...$fallbacks] as $k) {
            if ($flag($k) === true && $value($k) !== null) {
                return self::shape($family, $k, 0.0, $row, null);
            }
        }

        // No map to consult: the figure is whatever the caller has, and a zero stays a zero.
        if ($reported === null) {
            $key = $primary;
            foreach ([$primary, ...$fallbacks] as $k) {
                if ($value($k) !== null) {
                    $key = $k;
                    break;
                }
            }

            return self::shape($family, $key, $value($key), $row, $value($key) === null ? 'not_reported' : null);
        }

        // Nobody sent the figure this objective is judged on — «بدون بيانات», never «0».
        return self::shape($family, $primary, null, $row, 'not_reported');
    }

    /**
     * The result of a SET of rows that share one family — a family's total, a platform's share of it.
     *
     * Each row is resolved first. When they all resolved to one key, the results add up. When they
     * did not (one traffic campaign reported landing page views, another only clicks), the total
     * uses the family's fallback for every row where every row has it, so the sum is one unit; and
     * where there is no common unit there is no total («أهداف مختلفة»), never a sum of unlike things.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, bool>|null>  $reported  one entry per row, or empty
     * @return array<string, mixed> the {@see resolve()} shape, over the summed figures
     */
    public static function combine(ObjectiveFamily|string $family, array $rows, array $reported = []): array
    {
        $family = $family instanceof ObjectiveFamily ? $family : (ObjectiveFamily::tryFrom($family) ?? ObjectiveFamily::Unknown);
        $spec = self::TABLE[$family->value];
        $sum = static function (string $k) use ($rows): float {
            return array_sum(array_map(static fn (array $r): float => (float) ($r[$k] ?? 0), $rows));
        };
        $money = [
            'spend' => $sum('spend'),
            'spend_withheld_rows' => (int) $sum('spend_withheld_rows'),
        ];

        if ($spec['key'] === null || $rows === []) {
            return self::shape($family, null, null, $money, $spec['key'] === null ? 'objective_unknown' : 'not_reported');
        }

        $resolved = [];
        foreach ($rows as $i => $row) {
            $resolved[] = self::resolve($family, $row, $reported[$i] ?? null);
        }
        $keys = array_values(array_unique(array_filter(array_column($resolved, 'result_key'), static fn ($k) => $k !== null)));

        if (count($keys) === 1) {
            return self::shape($family, $keys[0], $sum($keys[0]), $money, null);
        }

        /*
         * A lead is a lead whichever column the platform filed it under, so the leads family adds
         * each row's own result. Reach and impressions, or visits and clicks, are different units,
         * and for those the family's fallback is the one unit every row shares.
         */
        if ($family === ObjectiveFamily::Leads && $keys !== []) {
            return self::shape($family, $spec['key'], array_sum(array_map(static fn (array $r): float => (float) ($r['result'] ?? 0), $resolved)), $money, null);
        }

        if ($spec['fallback'] !== null && in_array($spec['fallback'], $keys, true)) {
            return self::shape($family, $spec['fallback'], $sum($spec['fallback']), $money, null);
        }

        return self::shape($family, null, null, $money, $keys === [] ? 'not_reported' : 'mixed');
    }

    /** @return array{ar: string, en: string}|null */
    public static function label(?string $key, ?ObjectiveFamily $family = null): ?array
    {
        $words = self::words($key, $family);

        return $words === null ? null : ['ar' => $words['ar'], 'en' => $words['en']];
    }

    /** @return array{ar: string, en: string}|null */
    public static function costLabel(?string $key, ?ObjectiveFamily $family = null): ?array
    {
        $words = self::words($key, $family);

        return $words === null ? null : ['ar' => $words['cost_ar'], 'en' => $words['cost_en']];
    }

    /** @return array{ar: string, en: string, cost_ar: string, cost_en: string}|null */
    private static function words(?string $key, ?ObjectiveFamily $family): ?array
    {
        if ($key === null) {
            return null;
        }

        return self::FAMILY_LABELS[$family === null ? '' : $family->value][$key] ?? self::LABELS[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{result_family: string, result_key: string|null, result: float|null, result_label: array{ar: string, en: string}|null, cost_per_result: float|null, cost_per_result_label: array{ar: string, en: string}|null, result_missing_reason: string|null, result_missing_label: array{ar: string, en: string}|null}
     */
    private static function shape(ObjectiveFamily $family, ?string $key, ?float $result, array $row, ?string $missing): array
    {
        $per = self::TABLE[$family->value]['per'];
        // Per thousand only for the awareness units; a click or a visit is priced one at a time.
        $per = in_array($key, ['reach', 'impressions'], true) ? $per : 1.0;
        // DERIVED-WITHHELD-001 — no cost over money that was partly withheld.
        $spend = (int) ($row['spend_withheld_rows'] ?? 0) > 0 || ! isset($row['spend']) ? null : (float) $row['spend'];

        return [
            'result_family' => $family->value,
            'result_key' => $key,
            'result' => $result === null ? null : round($result, 2),
            'result_label' => self::label($key, $family),
            'cost_per_result' => $result === null ? null : FinancialRatios::costPer($spend, $result, 2, $per),
            'cost_per_result_label' => self::costLabel($key, $family),
            'result_missing_reason' => $missing,
            'result_missing_label' => $missing === null ? null : self::MISSING[$missing],
        ];
    }
}
