<?php

declare(strict_types=1);

namespace App\Domains\Automation\Support;

/**
 * AUTO-RULES-001 — the closed vocabulary a rule is written in.
 *
 * A metric is read from the platform's own reported figures for the object and window; when a platform
 * did not report what a condition needs (revenue on an awareness campaign, for instance) the condition is
 * «no figure» and the object is NOT matched — a rule never acts on a number nobody measured.
 */
final class RuleCatalog
{
    /** key => [ar, en, kind] — kind: money | ratio | percent | count | days | status */
    public const METRICS = [
        'spend' => ['الإنفاق', 'Spend', 'money'],
        'cpa' => ['تكلفة النتيجة', 'Cost per result', 'money'],
        'cpc' => ['تكلفة النقرة', 'CPC', 'money'],
        'cpm' => ['تكلفة الألف ظهور', 'CPM', 'money'],
        'revenue' => ['الإيرادات', 'Revenue', 'money'],
        'roas' => ['العائد على الإنفاق', 'ROAS', 'ratio'],
        'ctr' => ['نسبة النقر %', 'CTR %', 'percent'],
        'conversions' => ['النتائج', 'Results', 'count'],
        'impressions' => ['مرات الظهور', 'Impressions', 'count'],
        'clicks' => ['النقرات', 'Clicks', 'count'],
        'budget_utilization' => ['استهلاك الميزانية اليومية %', 'Daily budget used %', 'percent'],
        'age_days' => ['عمر الحملة بالأيام', 'Days since start', 'days'],
        'spend_change_pct' => ['تغير الإنفاق % عن الفترة السابقة', 'Spend change % vs previous', 'percent'],
        'cpa_change_pct' => ['تغير تكلفة النتيجة %', 'Cost per result change %', 'percent'],
        'roas_change_pct' => ['تغير العائد %', 'ROAS change %', 'percent'],
        'delivery_status' => ['حالة العرض', 'Delivery status', 'status'],
    ];

    /**
     * The reported platform figures each metric is computed from (`daily_metrics` keys).
     *
     * A rule may only be judged on what a platform actually reports: ROAS needs both revenue and spend,
     * so a platform that sends no revenue can never match a ROAS condition. Metrics read from the object
     * itself (its age, its status, its daily budget) need no reported figure. The trend metrics need the
     * figures of the metric they compare.
     *
     * @var array<string, list<string>>
     */
    public const NEEDS = [
        'spend' => ['spend'],
        'cpa' => ['spend', 'conversions'],
        'cpc' => ['spend', 'clicks'],
        'cpm' => ['spend', 'impressions'],
        'revenue' => ['revenue'],
        'roas' => ['revenue', 'spend'],
        'ctr' => ['clicks', 'impressions'],
        'conversions' => ['conversions'],
        'impressions' => ['impressions'],
        'clicks' => ['clicks'],
        'budget_utilization' => ['spend'],
        'age_days' => [],
        'spend_change_pct' => ['spend'],
        'cpa_change_pct' => ['spend', 'conversions'],
        'roas_change_pct' => ['revenue', 'spend'],
        'delivery_status' => [],
    ];

    public const OPERATORS = ['gt', 'gte', 'lt', 'lte', 'eq'];

    public const WINDOWS = [1, 3, 7, 14, 30];

    /** Actions, and the gateway vocabulary each becomes. bid_adjust has none: it is named, and refused. */
    public const ACTIONS = [
        'notify' => ['تنبيه', 'Notify', null],
        'pause' => ['إيقاف', 'Turn off', 'pause'],
        'resume' => ['تشغيل', 'Turn on', 'resume'],
        'budget_decrease' => ['خفض الميزانية اليومية', 'Decrease daily budget', 'set_daily_budget'],
        'budget_increase' => ['رفع الميزانية اليومية', 'Increase daily budget', 'set_daily_budget'],
        'bid_adjust' => ['تعديل المزايدة', 'Adjust bid', null],
    ];

    public const FREQUENCIES = ['every_15m' => 15, 'hourly' => 60, 'every_3h' => 180, 'every_6h' => 360, 'daily' => 1440];

    /** @return array<string, mixed> the catalogue for the builder */
    public static function forBuilder(): array
    {
        return [
            'metrics' => array_map(static fn (string $k, array $m): array => ['key' => $k, 'ar' => $m[0], 'en' => $m[1], 'kind' => $m[2], 'needs' => self::NEEDS[$k] ?? []], array_keys(self::METRICS), self::METRICS),
            'operators' => self::OPERATORS,
            'windows' => self::WINDOWS,
            'actions' => array_map(static fn (string $k, array $a): array => ['key' => $k, 'ar' => $a[0], 'en' => $a[1], 'executable' => $a[2] !== null], array_keys(self::ACTIONS), self::ACTIONS),
            'frequencies' => array_keys(self::FREQUENCIES),
            'modes' => ['monitor', 'recommend', 'auto'],
            'levels' => ['campaign', 'ad_set', 'ad'],
        ];
    }
}
