<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * NOTIF-BELL-001 — what a bell notification REALLY is, named once.
 *
 * ## The defect this ends
 *
 * `AlertEvaluator::NOTIFICATION_TYPE` files five different findings (a cost rise, a return drop, spend with no
 * results, a campaign that does not spend, a budget at risk) under ONE notification type, `budget_risk`, because
 * that type is also the person's preference switch. The bell then named every row by its type — so «صرف بدون نتائج»
 * was titled «تجاوز حدود الميزانية» on a project whose spend was nowhere near its budget. A wrong reason is a defect.
 *
 * The type stays what it is (it is the preference category). The KIND is the finding itself, derived from the alert's
 * own type and figures, stored beside the type, and the only thing the bell names a row by.
 *
 * ## The budget kinds are measured, not guessed
 *
 * `budget_75` / `budget_90` / `budget_100` say the budget REACHED that share. «تجاوز حدود الميزانية» (`budget_over`)
 * is said only when the spend is actually past 100% (OWNER_NOTIFICATIONS_RULES §7: a reason needs evidence).
 */
final class BellKind
{
    /** Panel filters, in the order the panel shows them (the first, «الكل», is no filter). */
    public const GROUPS = ['critical', 'budget', 'performance', 'sync'];

    /** @var array<string, array{ar: string, en: string, group: string}> */
    private const KINDS = [
        'budget_75' => ['ar' => 'الميزانية 75%', 'en' => 'Budget at 75%', 'group' => 'budget'],
        'budget_90' => ['ar' => 'الميزانية 90%', 'en' => 'Budget at 90%', 'group' => 'budget'],
        'budget_100' => ['ar' => 'الميزانية 100%', 'en' => 'Budget at 100%', 'group' => 'budget'],
        'budget_over' => ['ar' => 'تجاوز حدود الميزانية', 'en' => 'Budget limit exceeded', 'group' => 'budget'],
        'budget_low' => ['ar' => 'الميزانية قربت تخلص', 'en' => 'Budget running low', 'group' => 'budget'],
        'budget_watch' => ['ar' => 'الميزانية تحتاج متابعة', 'en' => 'Budget needs a look', 'group' => 'budget'],
        'no_results' => ['ar' => 'صرف بدون نتائج', 'en' => 'Spend with no results', 'group' => 'performance'],
        'cost_up' => ['ar' => 'ارتفاع تكلفة النتيجة', 'en' => 'Cost per result up', 'group' => 'performance'],
        'roas_drop' => ['ar' => 'انخفاض العائد', 'en' => 'Return dropped', 'group' => 'performance'],
        'not_spending' => ['ar' => 'حملة ما تصرف', 'en' => 'Campaign is not spending', 'group' => 'performance'],
        'campaign_stopped' => ['ar' => 'حملة توقفت', 'en' => 'Campaign stopped', 'group' => 'performance'],
        'anomaly' => ['ar' => 'تغيّر غير معتاد في الأداء', 'en' => 'Unusual change in performance', 'group' => 'performance'],
        'store_signal' => ['ar' => 'تغيّر في بيانات المتجر', 'en' => 'Store data changed', 'group' => 'performance'],
        'sync_failed' => ['ar' => 'فشل المزامنة', 'en' => 'Sync failed', 'group' => 'sync'],
        'token_expiry' => ['ar' => 'الربط يحتاج تجديد', 'en' => 'Connection needs renewing', 'group' => 'sync'],
        'report_ready' => ['ar' => 'تقرير جاهز', 'en' => 'Report ready', 'group' => 'other'],
        'report_failed' => ['ar' => 'تعذّر إنشاء تقرير', 'en' => 'Report failed', 'group' => 'other'],
        'recovered' => ['ar' => 'رجعت الأمور طبيعية', 'en' => 'Back to normal', 'group' => 'other'],
        'budget_risk' => ['ar' => 'تنبيه الميزانية', 'en' => 'Budget alert', 'group' => 'budget'],
    ];

    /** The alert rule types that mean a cost per result went up / a sync or measurement broke. */
    private const BY_ALERT = [
        'cpa_increase' => 'cost_up',
        'cpl_increase' => 'cost_up',
        'roas_drop' => 'roas_drop',
        'no_results' => 'no_results',
        'not_spending' => 'not_spending',
        'campaign_stopped' => 'campaign_stopped',
        'sync_failure' => 'sync_failed',
        'sync_outage' => 'sync_failed',
        'measurement_outage' => 'sync_failed',
        'ga4_tracking_loss' => 'sync_failed',
        'tracking_health' => 'sync_failed',
        'token_expiry' => 'token_expiry',
        'report_failed' => 'report_failed',
        'metric_anomaly' => 'anomaly',
        'ga4_sales_drop' => 'store_signal',
        'ga4_bounce_spike' => 'store_signal',
        'ga4_cvr_drop' => 'store_signal',
        'ga4_aov_shift' => 'store_signal',
        'ga4_attribution_gap' => 'store_signal',
        'ga4_unmatched_spend' => 'store_signal',
    ];

    /**
     * The kind of a finding, from the alert's own type and figures.
     *
     * @param  array<string,mixed>  $context  the alert event's context
     */
    public static function forAlert(string $alertType, array $context = [], bool $recovered = false): ?string
    {
        if ($recovered) {
            return 'recovered';
        }

        if (isset(self::BY_ALERT[$alertType])) {
            return self::BY_ALERT[$alertType];
        }

        if ($alertType === 'budget_risk') {
            return self::budget($context);
        }

        return null;
    }

    /** The kind of a notification that did not come from an alert, by its type. */
    public static function forType(string $type): ?string
    {
        return match ($type) {
            'report_ready' => 'report_ready',
            'report_failed' => 'report_failed',
            'sync_failed' => 'sync_failed',
            'token_expiring' => 'token_expiry',
            default => null,
        };
    }

    /**
     * The budget kind of a measured figure.
     *
     * An internal spend limit states the threshold it crossed (75, 90, 100, or its own) and the consumed / limit pair;
     * a campaign budget states its spent / budget ratio. «Over» needs the spend to be PAST the limit.
     *
     * @param  array<string,mixed>  $context
     */
    public static function budget(array $context): string
    {
        $pct = null;
        $over = false;

        if (isset($context['consumed'], $context['limit']) && is_numeric($context['consumed']) && is_numeric($context['limit']) && (float) $context['limit'] > 0) {
            $pct = (float) $context['consumed'] / (float) $context['limit'] * 100;
            $over = (float) $context['consumed'] > (float) $context['limit'];
        } elseif (isset($context['ratio']) && is_numeric($context['ratio'])) {
            $pct = (float) $context['ratio'] * 100;
            $over = (float) $context['ratio'] > 1.0;
        } elseif (isset($context['threshold']) && is_numeric($context['threshold'])) {
            $pct = (float) $context['threshold'];
        }

        if ($pct === null) {
            return 'budget_watch';
        }
        if ($over) {
            return 'budget_over';
        }
        if ($pct >= 100) {
            return 'budget_100';
        }
        if ($pct >= 90) {
            return 'budget_90';
        }
        if ($pct >= 75) {
            return 'budget_75';
        }

        return 'budget_watch';
    }

    public static function has(?string $kind): bool
    {
        return $kind !== null && isset(self::KINDS[$kind]);
    }

    public static function label(?string $kind, bool $ar): ?string
    {
        return self::has($kind) ? self::KINDS[$kind][$ar ? 'ar' : 'en'] : null;
    }

    /** The panel filter a kind belongs to: budget | performance | sync | other (critical is by severity, not kind). */
    public static function group(?string $kind): string
    {
        return self::has($kind) ? self::KINDS[$kind]['group'] : 'other';
    }

    /** @return list<string> the kinds of a panel filter */
    public static function kindsOf(string $group): array
    {
        return array_keys(array_filter(self::KINDS, static fn (array $k): bool => $k['group'] === $group));
    }
}
