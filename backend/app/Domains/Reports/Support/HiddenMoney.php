<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Campaigns\Creative\RankingMetric;

/**
 * SHARED-PDF-HIDE-FLAGS-001 — the ONE definition of what a link that hides spend or revenue withholds.
 *
 * Both sanitisers (`ShareService::sanitize()` for the snapshot and every file, `sanitizeLive()` for the
 * live link) read this class, so the two paths cannot drift apart again — they had: the snapshot path
 * missed `platform_series`, the live path missed `funnel_spend`, and neither knew `blended_cpa`.
 *
 * ## Two kinds of disclosure, handled two ways
 *
 * 1. **A figure under a key.** Every key that IS the money, or gives it back beside a figure the link
 *    still shows (a ROAS beside revenue is spend; a cost per stage beside the stage count is spend), is
 *    nulled at any depth. So is a `{metric, value}` pair whose metric is hidden.
 * 2. **A figure written into prose.** Never scrubbed out of a sentence. The generator states what each
 *    sentence reveals (`reveals`, `reason_basis`, a note's `neutral` variant); a sentence that reveals a
 *    hidden figure is replaced by its neutral variant, or dropped where it has none. A sentence written
 *    before those tags existed carries no statement of what it reveals, and is dropped — fail closed.
 */
final class HiddenMoney
{
    /** Spend, and everything that is spend or returns it beside a figure the link still publishes. */
    public const SPEND = [
        'spend', 'spend_original', 'spend_withheld_rows', 'spend_withheld',
        'cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe', 'cac', 'cost_per_view', 'cost_per_lpv', 'cost_per_result', 'cost_per',
        'funnel_spend', 'blended_cpa', 'roas', 'blended_roas', 'attributed_roas',
        'spent', 'remaining', 'consumed_pct', 'pace', 'projected_spend', 'daily_average', 'over_under',
        'excluded_spend', 'includes_non_sales_spend', 'spend_share', 'platform_value',
    ];

    /** Revenue, and everything that is revenue or returns it. */
    public const REVENUE = [
        'revenue', 'revenue_original', 'revenue_withheld_rows', 'gross_revenue', 'attributed_revenue',
        'roas', 'blended_roas', 'attributed_roas', 'aov', 'platform_value',
    ];

    /** Shared by both money figures; withheld only when neither survives. */
    public const CURRENCY = ['money_original_currency', 'money_original_currencies'];

    /** Keys whose value is a SENTENCE about money, handled by what it reveals rather than nulled blind. */
    private const PROSE_LISTS = ['recommendations', 'next_steps'];

    /** @return list<string> */
    public static function keys(bool $hideSpend, bool $hideRevenue): array
    {
        return array_values(array_unique(array_merge(
            $hideSpend ? self::SPEND : [],
            $hideRevenue ? self::REVENUE : [],
            $hideSpend && $hideRevenue ? self::CURRENCY : [],
        )));
    }

    /**
     * Redact a client payload for a link hiding spend and/or revenue. A no-op when neither is hidden.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data, bool $hideSpend, bool $hideRevenue): array
    {
        if (! $hideSpend && ! $hideRevenue) {
            return $data;
        }

        $keys = self::keys($hideSpend, $hideRevenue);

        foreach (self::PROSE_LISTS as $list) {
            if (isset($data[$list]) && is_array($data[$list])) {
                $data[$list] = array_values(array_filter(
                    $data[$list],
                    static fn ($item): bool => ! is_array($item) || ! self::revealsHidden($item, $keys),
                ));
            }
        }

        if (isset($data['observations']) && is_array($data['observations'])) {
            $data['observations'] = array_values(array_filter(
                $data['observations'],
                static fn ($note): bool => ! is_array($note) || array_intersect((array) ($note['reveals'] ?? ['spend', 'revenue']), $keys) === [],
            ));
        }

        if (isset($data['platform_notes']) && is_array($data['platform_notes'])) {
            foreach ($data['platform_notes'] as $provider => $note) {
                if (is_array($note) && self::revealsHidden($note, $keys)) {
                    $neutral = (array) ($note['neutral'] ?? []);
                    $note['strengths'] = array_values((array) ($neutral['strengths'] ?? []));
                    $note['weaknesses'] = array_values((array) ($neutral['weaknesses'] ?? []));
                    $data['platform_notes'][$provider] = $note;
                }
            }
        }

        return self::walk($data, $keys);
    }

    /**
     * @param  array<string|int, mixed>  $node
     * @param  list<string>  $keys
     * @return array<string|int, mixed>
     */
    private static function walk(array $node, array $keys): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true) && ! is_array($value)) {
                $node[$key] = null;
            }
        }

        // A figure named by its neighbour: {metric: 'cpa', value: 41.2} — an objective leader, a signal.
        if (isset($node['metric']) && is_string($node['metric']) && in_array($node['metric'], $keys, true) && array_key_exists('value', $node) && ! is_array($node['value'])) {
            $node['value'] = null;
        }

        // The best-platform line states its ranking figure, and says which figure it is.
        if (isset($node['basis']['key']) && is_string($node['basis']['key']) && in_array($node['basis']['key'], $keys, true) && array_key_exists('platform_value', $node)) {
            $node['platform_value'] = null;
        }

        // A ranked ad's reason states the figure it was ranked on («ROAS 5.21×»).
        if (isset($node['reason']) && is_string($node['reason']) && self::isRankedRow($node)) {
            $basis = $node['reason_basis'] ?? null;
            if (! is_string($basis) || in_array($basis, $keys, true)) {
                $node['reason'] = null;
            }
        }

        // A finding states its figure in `value` (and its evidence): the neutral variant is the finding without it.
        if (array_key_exists('severity', $node) && array_key_exists('value', $node) && self::revealsHidden($node, $keys)) {
            $node['value'] = null;
            if (isset($node['evidence']) && is_array($node['evidence']) && array_key_exists('value', $node['evidence'])) {
                $node['evidence']['value'] = null;
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::walk($value, $keys);
            }
        }

        return $node;
    }

    /** A row that names a piece of content — the only kind whose `reason` is a ranking sentence. */
    private static function isRankedRow(array $node): bool
    {
        return array_key_exists('reason_basis', $node)
            || (isset($node['name']) && (array_key_exists('content_key', $node) || array_key_exists('preview', $node) || array_key_exists('grain', $node) || array_key_exists('format', $node)));
    }

    /**
     * Whether a generated sentence reveals a hidden figure.
     *
     * Its own `reveals` when the generator stated it. Otherwise its `kpi` label, mapped back to the
     * metric it names. With neither, it cannot say, and a link hiding money does not print it.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     */
    private static function revealsHidden(array $item, array $keys): bool
    {
        if (isset($item['reveals']) && is_array($item['reveals'])) {
            return array_intersect($item['reveals'], $keys) !== [];
        }

        if (isset($item['kpi']) && is_string($item['kpi'])) {
            $metric = self::metricForLabel($item['kpi']);

            return $metric === null || in_array($metric, $keys, true);
        }

        return true;
    }

    /** The metric key an Arabic KPI label names, or null when it names none this product ranks on. */
    private static function metricForLabel(string $label): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'الإنفاق' => 'spend', 'الإيرادات' => 'revenue', 'النتائج' => 'conversions',
                // ReportObjectiveLens words for the same figures.
                'تكلفة العميل المحتمل' => 'cpa', 'تكلفة التحميل' => 'cpa', 'تكلفة النقرة' => 'cpc',
                'تكلفة الألف ظهور' => 'cpm', 'نسبة النقر' => 'ctr', 'العائد على الإنفاق' => 'roas',
            ];
            foreach (['impressions', 'reach', 'cpm', 'clicks', 'landing_page_views', 'ctr', 'cpc', 'engagements', 'engagement_rate', 'cpe',
                'video_views', 'video_completions', 'video_completion_rate', 'cost_per_view', 'leads', 'qualified_leads', 'cpl',
                'conversion_rate', 'purchases', 'conversions', 'revenue', 'roas', 'cpa', 'aov', 'installs', 'registrations',
                'in_app_events', 'cpi', 'spend'] as $key) {
                $map[RankingMetric::of($key)->labelAr] ??= $key;
            }
        }

        return $map[trim($label)] ?? null;
    }
}
