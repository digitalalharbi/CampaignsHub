<?php

declare(strict_types=1);

namespace App\Domains\Reports\Measurement;

use App\Domains\Integrations\Measurement\Ga4AttributionMatch;
use App\Domains\Integrations\Measurement\Ga4Classification;
use App\Domains\Integrations\Measurement\Ga4GapExplain;
use App\Domains\Integrations\Measurement\Ga4StoreAnalytics;
use App\Domains\Integrations\Measurement\Ga4Visibility;
use Illuminate\Support\Carbon;

/**
 * GA4-REPORT-SECTIONS-001 — the store reading as four report sections, each one a SHOW/HIDE of its own.
 *
 *   ga4_overview      the KPI tiles with their change, and the sessions / sales series
 *   ga4_acquisition   channels, devices and the shopping funnel
 *   ga4_platform_gap  GA4 beside what each ad platform reported, and the difference
 *   ga4_tables        sources, landing pages, products, countries (campaigns for an internal reader only)
 *
 * The live link and the saved report call this with the same arguments' kinds, so the two cannot
 * differ in shape; the snapshot stores what it returns, so a saved report keeps the figures it was
 * saved with.
 *
 * What a CLIENT may read is decided here by an allow-list, not by trimming afterwards:
 *   - no campaign names: the campaigns table is never built for a client-facing report;
 *   - no spend: nothing in the reading is spend, and the platform side carries conversions and
 *     revenue only;
 *   - `hideRevenue` takes every money figure out — tiles, series, slices, tables and the gap's money —
 *     because GA4's revenue is still revenue.
 *
 * The platform side comes from the report's OWN platform rows, so the report's source ceiling is
 * the gap's ceiling too. GA4 and platform figures are compared and never added.
 */
final class Ga4ReportSections
{
    public const KEYS = ['ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables', 'ga4_attribution', 'ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons'];

    /** The four parts every property with figures has; the measurement parts need campaigns or items besides. */
    public const CORE = ['ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables'];

    /** GA4-ATTRIBUTION-MATCH-001 — the groupings the Classification part carries, each as a short fixed list. */
    public const CLASSIFICATION_GROUPS = ['channel', 'platform', 'landing_page'];

    /** The tiles Store pulse reads: visits, bounce, sales, AOV, conversion. */
    private const PULSE_TILES = ['sessions', 'bounce_rate', 'transactions', 'revenue', 'aov', 'conversion_rate'];

    private const MONEY_TILES = ['revenue', 'aov'];

    public function __construct(
        private readonly Ga4StoreAnalytics $store,
        private readonly Ga4AttributionMatch $match,
        private readonly Ga4Classification $classification,
        private readonly Ga4GapExplain $explain,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $platformRows  the report's own per-platform rows (`provider`, `conversions`, `revenue`)
     * @return array<string, array<string, mixed>|null> every key of KEYS; null where the property has nothing for it
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $platformRows, ?string $reportCurrency, bool $hideRevenue, bool $clientFacing, bool $wholeProject = true, ?array $platformCeiling = null, array $hidden = []): array
    {
        $empty = array_fill_keys(self::KEYS, null);
        $hidden = Ga4Visibility::normalise($hidden);
        $reading = $this->store->build($tenantId, $projectId, $from, $to, $this->reported($platformRows, $reportCurrency, $hideRevenue));

        if (($reading['state'] ?? null) !== 'ready') {
            return $empty;
        }

        $tables = $reading['tables'];
        if ($clientFacing) {
            // CLIENT-REPORT: no campaign names. Not emptied — absent, so no renderer can draw a tab for it.
            unset($tables['campaigns']);
        }
        $tables = array_filter($tables, static fn ($t): bool => is_array($t) && $t['rows'] !== []);

        $gap = $reading['gap'];
        unset($gap['platforms_available']);

        $sections = [
            'ga4_overview' => [
                'currency' => $reading['currency'],
                'usd' => $reading['usd'],
                'period' => $reading['period'],
                'previous_period' => $reading['previous_period'],
                'property' => ['name' => $reading['property']['name']],
                'last_synced_at' => $reading['last_synced_at'],
                'revenue_hidden' => false,
                'kpis' => $reading['kpis'],
                'series' => $reading['series'],
            ],
            'ga4_acquisition' => $reading['channels'] === null && $reading['devices'] === null && $this->funnelIsEmpty($reading['funnel'])
                ? null
                : ['currency' => $reading['currency'], 'channels' => $reading['channels'], 'devices' => $reading['devices'], 'channels_meta' => $reading['channels_meta'] ?? null, 'devices_meta' => $reading['devices_meta'] ?? null, 'funnel' => $reading['funnel']],
            'ga4_platform_gap' => $gap['rows'] === [] && $gap['unpaid'] === [] && $gap['other_paid'] === null
                ? null
                : $gap + ['currency' => $reading['currency']],
            'ga4_tables' => $tables === [] ? null : ['currency' => $reading['currency'], 'tables' => $tables],
        ] + $this->measurementParts($tenantId, $projectId, $from, $to, $reading, $clientFacing, $wholeProject, $platformCeiling);

        $sections = Ga4Visibility::applyToSections($sections, $hidden);

        return $hideRevenue ? self::withoutMoney($sections) : $sections;
    }

    /**
     * GA4-ATTRIBUTION-MATCH-001 — the four measurement parts: Attribution match, Store pulse, Classification, Products.
     *
     * Link scope, decided here and not by a new scope axis: Store pulse, Classification, Products and the reasons of the gap
     * read the WHOLE property (GA4 visits are not split by campaign), so a link that names specific campaigns never receives
     * them and gets no attribution part either; a link that names platforms gets the attribution part for those platforms
     * only. A client-facing report gets the client framing: platform and source names only, no spend, no cost, no ROAS,
     * no campaign names.
     *
     * @param  array<string, mixed>  $reading
     * @param  list<string>|null  $platformCeiling  the platforms a narrower link names, or null for «all»
     * @return array<string, array<string, mixed>|null>
     */
    private function measurementParts(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $reading, bool $clientFacing, bool $wholeProject, ?array $platformCeiling): array
    {
        $attribution = $this->match->build($tenantId, $projectId, $from, $to, ['internal' => ! $clientFacing]);
        if (($attribution['state'] ?? null) === 'ready') {
            if ($platformCeiling !== null) {
                $attribution['platforms'] = array_values(array_filter($attribution['platforms'], static fn (array $p): bool => in_array($p['platform'], $platformCeiling, true)));
            }
            unset($attribution['filters'], $attribution['evidence']['campaigns_without_trace']);
        } else {
            $attribution = null;
        }
        if (! $wholeProject && $platformCeiling === null) {
            $attribution = null;
        }

        $pulse = null;
        $classification = null;
        $products = null;
        if ($wholeProject) {
            $tiles = array_values(array_filter((array) $reading['kpis'], static fn ($k): bool => is_array($k) && in_array($k['key'] ?? null, self::PULSE_TILES, true)));
            $funnel = $reading['funnel'] ?? [];
            $pulse = $tiles === [] && $this->funnelIsEmpty((array) $funnel) ? null : [
                'currency' => $reading['currency'], 'usd' => $reading['usd'], 'period' => $reading['period'], 'previous_period' => $reading['previous_period'],
                'last_synced_at' => $reading['last_synced_at'], 'kpis' => $tiles, 'funnel' => $funnel,
            ];

            $groups = [];
            foreach (self::CLASSIFICATION_GROUPS as $group) {
                $page = $this->classification->page($tenantId, $projectId, $from, $to, ['group' => $group, 'per_page' => 10]);
                if (($page['state'] ?? null) === 'ready') {
                    $groups[$group] = ['total' => $page['total'], 'rows' => $page['rows']];
                }
            }
            $classification = $groups === [] ? null : ['currency' => $reading['currency'], 'groups' => $groups];

            $p = $reading['tables']['products'] ?? null;
            $products = is_array($p) && ($p['rows'] ?? []) !== [] ? ['currency' => $reading['currency'], 'table' => $p] : null;
        }

        $reasons = null;
        if ($wholeProject) {
            $r = $this->explain->build($tenantId, $projectId, $from, $to, internal: ! $clientFacing);
            $reasons = ($r['state'] ?? null) === 'ready' ? array_diff_key($r, ['state' => 1]) : null;
        }

        return ['ga4_attribution' => $attribution, 'ga4_pulse' => $pulse, 'ga4_classification' => $classification, 'ga4_products' => $products, 'ga4_gap_reasons' => $reasons];
    }

    /**
     * The report's own platform rows as the gap's platform side.
     *
     * Only a NUMBER is taken: a row whose conversions the client boundary withheld carries none, and
     * an absent figure stays absent rather than becoming a zero the gap would subtract from.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{conversions: ?float, revenue: ?float, currency: ?string}>
     */
    private function reported(array $rows, ?string $currency, bool $hideRevenue): array
    {
        $out = [];
        foreach ($rows as $row) {
            $provider = is_array($row) ? (string) ($row['provider'] ?? '') : '';
            if ($provider === '') {
                continue;
            }
            $conversions = is_numeric($row['conversions'] ?? null) ? (float) $row['conversions'] : null;
            $revenue = ! $hideRevenue && is_numeric($row['revenue'] ?? null) ? (float) $row['revenue'] : null;
            if ($conversions === null && $revenue === null) {
                continue;
            }
            $out[$provider] = ['conversions' => $conversions, 'revenue' => $revenue, 'currency' => $revenue === null ? null : $currency];
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $funnel */
    private function funnelIsEmpty(array $funnel): bool
    {
        return array_filter($funnel, static fn (array $s): bool => $s['count'] !== null) === [];
    }

    /**
     * Every money figure out of the four parts — for a link that hides revenue, and for a KPI list
     * that left revenue out (`ChosenFigures`). The counts stay: sessions and purchases are not money.
     *
     * @param  array<string, mixed>  $sections  a payload, or just the four parts; other keys are left alone
     * @return array<string, mixed>
     */
    public static function withoutMoney(array $sections): array
    {
        $blank = static function (array $row): array {
            foreach (['revenue', 'revenue_usd', 'currency'] as $k) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = null;
                }
            }

            return $row;
        };
        $side = static fn ($s) => is_array($s) ? $blank($s) : $s;

        if (is_array($sections['ga4_overview'] ?? null)) {
            $o = $sections['ga4_overview'];
            $o['kpis'] = array_values(array_filter((array) $o['kpis'], static fn ($k): bool => is_array($k) && ! in_array($k['key'] ?? null, self::MONEY_TILES, true)));
            $o['series'] = array_map($blank, (array) $o['series']);
            $o['currency'] = null;
            $o['usd'] = ['available' => false, 'reason' => 'hidden'];
            $o['revenue_hidden'] = true;
            $sections['ga4_overview'] = $o;
        }
        if (is_array($sections['ga4_acquisition'] ?? null)) {
            foreach (['channels', 'devices'] as $k) {
                if (is_array($sections['ga4_acquisition'][$k] ?? null)) {
                    $sections['ga4_acquisition'][$k] = array_map($blank, $sections['ga4_acquisition'][$k]);
                }
            }
            $sections['ga4_acquisition']['currency'] = null;
        }
        if (is_array($sections['ga4_platform_gap'] ?? null)) {
            $g = $sections['ga4_platform_gap'];
            $g['rows'] = array_map(static function (array $row) use ($side): array {
                $row['reported'] = $side($row['reported'] ?? null);
                $row['ga4'] = $side($row['ga4'] ?? null);
                $row['gap']['revenue_usd'] = null;
                $row['gap']['revenue_share'] = null;

                return $row;
            }, (array) $g['rows']);
            $g['unpaid'] = array_map($blank, (array) $g['unpaid']);
            $g['other_paid'] = $side($g['other_paid'] ?? null);
            $g['currency'] = null;
            $sections['ga4_platform_gap'] = $g;
        }
        if (is_array($sections['ga4_attribution'] ?? null)) {
            $a = $sections['ga4_attribution'];
            $a['platforms'] = array_map(static function (array $p) use ($blank): array {
                foreach (['claimed', 'ga4', 'channel'] as $k) {
                    if (is_array($p[$k] ?? null)) {
                        $p[$k] = $blank($p[$k]);
                        $p[$k]['aov_usd'] = null;
                        $p[$k]['revenue_usd'] = null;
                    }
                }
                if (isset($p['gap'])) {
                    $p['gap']['revenue_usd'] = null;
                    $p['gap']['revenue_share'] = null;
                }
                foreach (($p['campaigns'] ?? []) as $i => $c) {
                    foreach (['claimed', 'ga4'] as $k) {
                        if (is_array($c[$k] ?? null)) {
                            $p['campaigns'][$i][$k] = $blank($c[$k]);
                            $p['campaigns'][$i][$k]['aov_usd'] = null;
                        }
                    }
                    $p['campaigns'][$i]['gap']['revenue_usd'] = null;
                    $p['campaigns'][$i]['gap']['revenue_share'] = null;
                    $p['campaigns'][$i]['roas_ga4'] = null;
                }

                return $p;
            }, (array) $a['platforms']);
            $a['unattributed']['classes'] = array_map(static function (array $c) use ($blank): array {
                $c = $blank($c);
                $c['aov_usd'] = null;

                return $c;
            }, (array) ($a['unattributed']['classes'] ?? []));
            if (is_array($a['other_paid']['ga4'] ?? null)) {
                $a['other_paid']['ga4'] = $blank($a['other_paid']['ga4']);
            }
            if (is_array($a['property'] ?? null)) {
                $a['property']['currency'] = null;
            }
            $sections['ga4_attribution'] = $a;
        }
        if (is_array($sections['ga4_pulse'] ?? null)) {
            $o = $sections['ga4_pulse'];
            $o['kpis'] = array_values(array_filter((array) $o['kpis'], static fn ($k): bool => is_array($k) && ! in_array($k['key'] ?? null, self::MONEY_TILES, true)));
            $o['currency'] = null;
            $o['usd'] = ['available' => false, 'reason' => 'hidden'];
            $sections['ga4_pulse'] = $o;
        }
        if (is_array($sections['ga4_classification'] ?? null)) {
            foreach ((array) $sections['ga4_classification']['groups'] as $g => $block) {
                $sections['ga4_classification']['groups'][$g]['rows'] = array_map(static function (array $r): array {
                    foreach (['revenue', 'revenue_usd', 'aov_usd'] as $k) {
                        if (array_key_exists($k, $r)) {
                            $r[$k] = null;
                        }
                    }

                    return $r;
                }, (array) $block['rows']);
            }
            $sections['ga4_classification']['currency'] = null;
        }
        if (is_array($sections['ga4_products']['table']['rows'] ?? null)) {
            $sections['ga4_products']['table']['rows'] = array_map($blank, $sections['ga4_products']['table']['rows']);
            $sections['ga4_products']['currency'] = null;
        }
        if (is_array($sections['ga4_gap_reasons'] ?? null)) {
            $g = $sections['ga4_gap_reasons'];
            $g['headline']['gap_usd'] = null;
            $g['reasons'] = array_map(static function (array $r): array {
                $r['value_usd'] = null;

                return $r;
            }, (array) $g['reasons']);
            if (is_array($g['property'] ?? null)) {
                $g['property']['currency'] = null;
            }
            $g['context'] = array_map(static function (array $c): array {
                if (array_key_exists('currency', $c)) {
                    $c['currency'] = null;
                    $c['usd'] = null;
                    $c['measured'] = false;
                }

                return $c;
            }, (array) ($g['context'] ?? []));
            $sections['ga4_gap_reasons'] = $g;
        }
        if (is_array($sections['ga4_tables']['tables'] ?? null)) {
            foreach ($sections['ga4_tables']['tables'] as $name => $table) {
                if (is_array($table['rows'] ?? null)) {
                    $sections['ga4_tables']['tables'][$name]['rows'] = array_map($blank, $table['rows']);
                }
            }
            $sections['ga4_tables']['currency'] = null;
        }

        return $sections;
    }
}
