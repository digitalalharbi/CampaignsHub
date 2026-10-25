<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;

/**
 * REPORT-KPI-VISIBILITY-001 — every metric a client can be shown is its own show/hide switch.
 *
 * ## One list, three scopes
 *
 * The HIDDEN metrics are stored as a list of keys from {@see self::CATALOG}: on the report (`config.hidden_metrics`), on a link
 * (`settings.hidden_metrics`, copied from the report when the link is made and editable after), and as the client's reusable
 * template (`client.settings.report_template.hidden_metrics`). A list that is absent means «this report predates the choice» and
 * hides nothing — so no report that exists today changes meaning. The CLIENT PRESET ({@see self::CLIENT_PRESET}) is what a NEW
 * client-facing report starts from: the sales KPIs, without the cost-per-result.
 *
 * ## Enforcement is the redaction the product already has
 *
 * `spend` and `revenue` are folded into the existing `hide_spend` / `hide_revenue` flags, so everything that already follows
 * those (prose, companions, attribution, creative rosters) follows these. Every other metric becomes a set of payload keys that
 * `HiddenMoney` REMOVES at any depth, from every client surface that sanitises through it — page payloads, shared links, the
 * print/PDF payload, CSV and XLSX.
 *
 * ## What hiding does NOT do
 *
 * Hiding a metric never hides its inputs: with CPA hidden the purchases, spend and revenue still show. The reverse is the only
 * disclosure that matters and it is the operator's call (spend visible and purchases visible make CPA one division away) — this
 * class does not pretend otherwise.
 */
final class ReportMetricVisibility
{
    /**
     * @var array<string, array{group: string, ar: string, en: string, keys: list<string>}>
     *
     * `keys` are the payload keys that ARE the metric, or give it back beside a figure the page still shows. `spend` and
     * `revenue` list none here: they ride the existing flags.
     */
    public const CATALOG = [
        'spend' => ['group' => 'delivery', 'ar' => 'الإنفاق', 'en' => 'Spend', 'keys' => []],
        'impressions' => ['group' => 'delivery', 'ar' => 'مرات الظهور', 'en' => 'Impressions', 'keys' => ['impressions']],
        'clicks' => ['group' => 'delivery', 'ar' => 'النقرات', 'en' => 'Clicks', 'keys' => ['clicks']],
        'ctr' => ['group' => 'delivery', 'ar' => 'نسبة النقر', 'en' => 'CTR', 'keys' => ['ctr']],
        'add_to_cart' => ['group' => 'results', 'ar' => 'إضافات للسلة', 'en' => 'Add to cart', 'keys' => ['add_to_cart', 'adds_to_cart', 'atc']],
        'purchases' => ['group' => 'results', 'ar' => 'عمليات الشراء', 'en' => 'Purchases', 'keys' => ['purchases']],
        'conversion_rate' => ['group' => 'results', 'ar' => 'معدل التحويل', 'en' => 'Conversion rate', 'keys' => ['conversion_rate', 'cvr']],
        'revenue' => ['group' => 'value', 'ar' => 'الإيرادات', 'en' => 'Revenue', 'keys' => []],
        'roas' => ['group' => 'value', 'ar' => 'العائد على الإنفاق', 'en' => 'ROAS', 'keys' => ['roas', 'blended_roas', 'attributed_roas']],
        'cpa' => ['group' => 'cost', 'ar' => 'تكلفة الشراء', 'en' => 'Cost per purchase (CPA)', 'keys' => ['cpa', 'blended_cpa', 'platform_by_cpa', 'cost_per_purchase', 'cost_per_result', 'cost_per']],
        'cpl' => ['group' => 'cost', 'ar' => 'تكلفة العميل المحتمل', 'en' => 'Cost per lead (CPL)', 'keys' => ['cpl']],
        'cpc' => ['group' => 'cost', 'ar' => 'تكلفة النقرة', 'en' => 'Cost per click (CPC)', 'keys' => ['cpc']],
        'cpm' => ['group' => 'cost', 'ar' => 'تكلفة الألف ظهور', 'en' => 'CPM', 'keys' => ['cpm']],
    ];

    /** What a NEW client-facing report hides until the operator says otherwise. */
    public const CLIENT_PRESET = ['cpa'];

    /** ReportColumns keys that ARE a hidden metric, so the table drops the column rather than draw it empty. */
    private const COLUMN_FOR = ['cpa' => 'cost', 'cpl' => 'cost', 'cpc' => 'cost', 'cpm' => 'cost'];

    /**
     * Cut a request's list to known keys, once each, in catalog order.
     *
     * @return list<string>
     */
    public static function normalise(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_intersect(array_keys(self::CATALOG), array_filter($raw, 'is_string')));
    }

    /** Whether a stored value is a CHOICE (even an empty one) rather than «never chosen». */
    public static function isChosen(mixed $raw): bool
    {
        return is_array($raw);
    }

    /**
     * The payload keys behind the hidden metrics other than spend and revenue.
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    public static function payloadKeys(array $hidden): array
    {
        $keys = [];
        foreach (self::normalise($hidden) as $metric) {
            $keys = array_merge($keys, self::CATALOG[$metric]['keys']);
        }

        return array_values(array_unique($keys));
    }

    /**
     * ReportColumns keys that should not be offered because the metric behind them is hidden.
     *
     * The shared `cost` column is the cost-per-result of whichever objective the table reads, so it goes when ANY cost metric is hidden
     * and the table cannot say which one the column is (a hidden CPA must not reappear as an unlabelled cost).
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    public static function hiddenColumns(array $hidden): array
    {
        $out = [];
        foreach (self::normalise($hidden) as $metric) {
            if (isset(self::COLUMN_FOR[$metric])) {
                $out[] = self::COLUMN_FOR[$metric];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The words a generated sentence uses for the hidden metrics — so a sentence that NAMES one can be struck.
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    public static function labels(array $hidden): array
    {
        $labels = [];
        foreach (self::normalise($hidden) as $metric) {
            if (in_array($metric, ['spend', 'revenue'], true)) {
                continue;
            }
            $labels[] = self::CATALOG[$metric]['ar'];
            $labels[] = self::CATALOG[$metric]['en'];
            if (RankingMetric::isRankable($metric)) {
                $labels[] = RankingMetric::of($metric)->labelAr;
            }
            if ($metric === 'cpa') {
                array_push($labels, 'CPA', 'تكلفة النتيجة', 'تكلفة الشراء', 'تكلفة التحميل', 'تكلفة العميل', 'cost per result', 'cost per purchase');
            }
        }

        return array_values(array_unique(array_filter($labels, static fn (string $l): bool => $l !== '')));
    }

    /** @return list<string> */
    public static function clientPreset(): array
    {
        return self::CLIENT_PRESET;
    }

    /** @return list<array{key: string, group: string, ar: string, en: string}> the catalog as the selector shows it */
    public static function catalog(): array
    {
        $rows = [];
        foreach (self::CATALOG as $key => $meta) {
            $rows[] = ['key' => $key, 'group' => $meta['group'], 'ar' => $meta['ar'], 'en' => $meta['en']];
        }

        return $rows;
    }

    /**
     * The metrics hidden on this document: the link's own choice, else the report's, else none.
     *
     * A link that states a choice (even an empty one) owns it; a link that never did follows the report, so a report edited after a
     * link was made still governs a link that said nothing.
     *
     * @return list<string>
     */
    public static function effective(Report $report, ?ReportShare $share): array
    {
        if ($share !== null) {
            return $share->hiddenMetrics();
        }

        return self::normalise(((array) ($report->config ?? []))['hidden_metrics'] ?? null);
    }

    /**
     * Take the hidden metrics out of a client payload — values, lists that name them, sentences that state them and table columns.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    public static function redact(array $payload, array $hidden): array
    {
        $hidden = self::normalise($hidden);
        if ($hidden === []) {
            return $payload;
        }

        $payload = HiddenMoney::redact($payload, in_array('spend', $hidden, true), in_array('revenue', $hidden, true), self::payloadKeys($hidden));
        $payload = self::withoutSentencesNaming($payload, $hidden);

        return $payload + ['hidden_metrics' => $hidden];
    }

    /**
     * A generated sentence that NAMES a hidden metric is struck (the summary's opening line states the figure it judges on).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    public static function withoutSentencesNaming(array $payload, array $hidden): array
    {
        $words = self::labels($hidden);
        if ($words === [] || ! isset($payload['summary']) || ! is_array($payload['summary'])) {
            return $payload;
        }

        $payload['summary'] = array_values(array_filter(
            $payload['summary'],
            static function ($line) use ($words): bool {
                if (! is_string($line)) {
                    return true;
                }
                foreach ($words as $word) {
                    if (mb_stripos($line, $word) !== false) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return $payload;
    }
}
