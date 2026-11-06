<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

/**
 * GA4-VISIBILITY-001 — every element of the store / GA4 layer is its own SHOW/HIDE.
 *
 * The Owner manages campaigns; store sales are a different job. He needs the two merged in one place to
 * analyse, and a report he can send without what conflicts with his scope. So the layer is a catalogue of
 * ELEMENTS (each tile, chart, funnel stage, table, table column, platform row, the claimed-vs-measured
 * gap, the confidence chips, the buckets, each reason of the gap), each marked as a SALES figure or a
 * MARKETING figure, and one list of hidden keys travels with a report, a link, or a user's saved view.
 *
 *   all        nothing hidden — the default; nothing is hidden by default (permanent report rule)
 *   marketing  hides every sales element
 *   sales      hides every marketing element
 *   custom     any other hidden list
 *
 * Applied on the SERVER, to the finished payload: a hidden element's numbers are absent from the JSON —
 * and therefore from the PDF text layer and the CSV / XLSX, which are built from the same payload — not
 * merely not rendered. A hidden figure is set to null (the key stays so no reader trips); a hidden
 * element (tile, chart, table, row, bucket, reason) is removed.
 *
 * Without the `analytics.store.view` capability the SALES set is added to whatever the user hid: the cut
 * is theirs to make by role, not by remembering to click.
 */
final class Ga4Visibility
{
    public const PRESETS = ['all', 'marketing', 'sales', 'custom'];

    /** Reasons of the gap (see Ga4GapExplainer), each its own switch. */
    public const REASONS = ['no_trace', 'attribution_rule', 'untagged_channel', 'no_platform_trace'];

    /** @var list<string> */
    public const SALES = [
        'tile.transactions', 'tile.revenue', 'tile.aov', 'tile.conversion_rate',
        'chart.series.sales', 'stage.purchase', 'table.products',
        'col.purchases', 'col.revenue', 'col.aov', 'col.conversion_rate',
        'attr.sales', 'attr.gap', 'gap.headline',
        'gap.reason.no_trace', 'gap.reason.attribution_rule', 'gap.reason.untagged_channel', 'gap.reason.no_platform_trace',
    ];

    /** @var list<string> */
    public const MARKETING = [
        'tile.sessions', 'tile.users_daily_sum', 'tile.new_users', 'tile.bounce_rate', 'tile.engagement_rate', 'tile.avg_session_seconds', 'tile.add_to_carts', 'tile.checkouts',
        'chart.series.visits', 'chart.channels', 'chart.devices', 'chart.funnel',
        'stage.sessions', 'stage.add_to_cart', 'stage.checkout',
        'table.sources', 'table.campaigns', 'table.landing_pages', 'table.countries',
        'col.sessions', 'col.engagement_rate',
        'attr.confidence', 'attr.unattributed', 'attr.unmatched', 'attr.claimed',
        'dim.channel', 'dim.platform', 'dim.landing_page',
    ];

    /** Element keys that are not a fixed list: `platform:<key>` hides one platform's row everywhere. */
    private const PLATFORM = '/^platform:[a-z0-9_]{1,40}$/';

    /** @return list<string> */
    public static function catalogue(): array
    {
        return [...self::MARKETING, ...self::SALES];
    }

    /**
     * @param  mixed  $raw  a stored list, possibly hostile: unknown keys are dropped, never kept
     * @return list<string>
     */
    public static function normalise(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $known = array_flip(self::catalogue());
        $out = [];
        foreach ($raw as $key) {
            if (is_string($key) && (isset($known[$key]) || preg_match(self::PLATFORM, $key) === 1)) {
                $out[$key] = true;
            }
        }

        return array_keys($out);
    }

    /** @return list<string> */
    public static function forPreset(string $preset): array
    {
        return match ($preset) {
            'marketing' => self::SALES,
            'sales' => self::MARKETING,
            default => [],
        };
    }

    /** @param  list<string>  $hidden */
    public static function presetOf(array $hidden): string
    {
        $h = array_values(array_unique($hidden));
        sort($h);
        foreach (['all', 'marketing', 'sales'] as $name) {
            $set = self::forPreset($name);
            sort($set);
            if ($h === $set) {
                return $name;
            }
        }

        return 'custom';
    }

    /**
     * What a user without the store capability hides on top of their own list.
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    public static function withRole(array $hidden, bool $mayViewStoreSales): array
    {
        return $mayViewStoreSales ? $hidden : array_values(array_unique([...$hidden, ...self::SALES]));
    }

    // ---------------------------------------------------------------- the cut

    /**
     * The GA4 parts of a report or link payload, cut by the hidden list. Absent / null parts stay as they were.
     *
     * @param  array<string, mixed>  $sections
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    public static function applyToSections(array $sections, array $hidden): array
    {
        if ($hidden === []) {
            return $sections;
        }
        $h = array_flip($hidden);

        if (is_array($sections['ga4_overview'] ?? null)) {
            $o = $sections['ga4_overview'];
            $o['kpis'] = self::kpis((array) ($o['kpis'] ?? []), $h);
            $o['series'] = self::series((array) ($o['series'] ?? []), $h);
            $sections['ga4_overview'] = $o['kpis'] === [] && $o['series'] === [] ? null : $o;
        }
        if (is_array($sections['ga4_acquisition'] ?? null)) {
            $a = $sections['ga4_acquisition'];
            $a['channels'] = isset($h['chart.channels']) || isset($h['dim.channel']) ? null : self::slices($a['channels'] ?? null, $h);
            $a['devices'] = isset($h['chart.devices']) ? null : self::slices($a['devices'] ?? null, $h);
            $a['funnel'] = self::funnel((array) ($a['funnel'] ?? []), $h);
            $sections['ga4_acquisition'] = $a['channels'] === null && $a['devices'] === null && $a['funnel'] === [] ? null : $a;
        }
        if (is_array($sections['ga4_platform_gap'] ?? null)) {
            $sections['ga4_platform_gap'] = self::gap($sections['ga4_platform_gap'], $h);
        }
        if (is_array($sections['ga4_tables'] ?? null)) {
            $t = $sections['ga4_tables'];
            $t['tables'] = self::tables((array) ($t['tables'] ?? []), $h);
            $sections['ga4_tables'] = $t['tables'] === [] ? null : $t;
        }
        if (is_array($sections['ga4_attribution'] ?? null)) {
            $sections['ga4_attribution'] = self::attribution($sections['ga4_attribution'], $h);
        }
        if (is_array($sections['ga4_pulse'] ?? null)) {
            $p = $sections['ga4_pulse'];
            $p['kpis'] = self::kpis((array) ($p['kpis'] ?? []), $h);
            $p['funnel'] = self::funnel((array) ($p['funnel'] ?? []), $h);
            $sections['ga4_pulse'] = $p['kpis'] === [] && $p['funnel'] === [] ? null : $p;
        }
        if (is_array($sections['ga4_classification'] ?? null)) {
            $c = $sections['ga4_classification'];
            foreach (array_keys((array) $c['groups']) as $g) {
                if (isset($h['dim.'.$g])) {
                    unset($c['groups'][$g]);

                    continue;
                }
                $c['groups'][$g]['rows'] = array_map(static fn (array $r): array => self::cols($r, $h), (array) $c['groups'][$g]['rows']);
            }
            $sections['ga4_classification'] = $c['groups'] === [] ? null : $c;
        }
        if (is_array($sections['ga4_products'] ?? null)) {
            $sections['ga4_products'] = isset($h['table.products']) ? null : self::productsPart($sections['ga4_products'], $h);
        }
        if (is_array($sections['ga4_gap_reasons'] ?? null)) {
            $sections['ga4_gap_reasons'] = self::reasons($sections['ga4_gap_reasons'], $h);
        }

        return $sections;
    }

    /**
     * The Analytics reading (`Ga4StoreAnalytics::build`) cut by the same list.
     *
     * @param  array<string, mixed>  $reading
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    public static function applyToReading(array $reading, array $hidden): array
    {
        if ($hidden === [] || ($reading['state'] ?? null) !== 'ready') {
            return $reading;
        }
        $h = array_flip($hidden);
        $reading['kpis'] = self::kpis((array) ($reading['kpis'] ?? []), $h);
        $reading['series'] = self::series((array) ($reading['series'] ?? []), $h);
        foreach (['channels' => 'chart.channels', 'devices' => 'chart.devices'] as $k => $switch) {
            $reading[$k] = isset($h[$switch]) ? null : self::slices($reading[$k] ?? null, $h);
        }
        $reading['funnel'] = self::funnel((array) ($reading['funnel'] ?? []), $h);
        $reading['tables'] = self::tables((array) ($reading['tables'] ?? []), $h);
        if (is_array($reading['gap'] ?? null)) {
            $reading['gap'] = self::gap($reading['gap'], $h) ?? ['summed' => false, 'rows' => [], 'unpaid' => [], 'other_paid' => null, 'ga4_sessions' => null];
        }
        $reading['hidden'] = array_values($hidden);

        return $reading;
    }

    /**
     * Any other measurement payload (the site reading, a traffic table page): every key the hidden columns name is
     * nulled wherever it appears, and a hidden table's breakdown is dropped. Key-based, like the money layer, so a
     * reader added later cannot forget to cut.
     *
     * @param  list<string>  $hidden
     */
    public static function scrub(mixed $value, array $hidden): mixed
    {
        if ($hidden === [] || ! is_array($value)) {
            return $value;
        }
        $h = array_flip($hidden);
        $keys = [];
        foreach ([
            'col.sessions' => ['sessions', 'engaged_sessions', 'users', 'users_daily_sum', 'new_users'],
            'col.engagement_rate' => ['engagement_rate'],
            'col.purchases' => ['purchases', 'transactions', 'items_purchased', 'orders'],
            'col.revenue' => ['revenue', 'revenue_usd', 'item_revenue', 'current_usd', 'previous_usd'],
            'col.aov' => ['aov', 'aov_usd'],
            'col.conversion_rate' => ['conversion_rate'],
        ] as $switch => $list) {
            if (isset($h[$switch])) {
                array_push($keys, ...$list);
            }
        }
        $keys = array_flip($keys);
        $walk = static function (mixed $v) use (&$walk, $keys, $h): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $out = [];
            foreach ($v as $k => $x) {
                if (is_string($k) && isset($keys[$k])) {
                    $out[$k] = null;

                    continue;
                }
                if ($k === 'products' && isset($h['table.products'])) {
                    continue;
                }
                $out[$k] = $walk($x);
            }

            return $out;
        };

        return $walk($value);
    }

    /** @param  array<string, mixed>  $row */
    public static function cols(array $row, array $h): array
    {
        $cut = [
            'col.sessions' => ['sessions', 'engaged_sessions', 'users', 'share', 'items_viewed'],
            'col.engagement_rate' => ['engagement_rate'],
            'col.purchases' => ['purchases', 'items_purchased', 'orders'],
            'col.revenue' => ['revenue', 'revenue_usd', 'item_revenue', 'currency'],
            'col.aov' => ['aov', 'aov_usd'],
            'col.conversion_rate' => ['conversion_rate'],
        ];
        foreach ($cut as $switch => $keys) {
            if (isset($h[$switch])) {
                foreach ($keys as $k) {
                    if (array_key_exists($k, $row)) {
                        $row[$k] = null;
                    }
                }
            }
        }

        return $row;
    }

    private static function kpis(array $kpis, array $h): array
    {
        return array_values(array_filter($kpis, static fn ($k): bool => is_array($k) && ! isset($h['tile.'.($k['key'] ?? '')])));
    }

    private static function series(array $series, array $h): array
    {
        $visits = isset($h['chart.series.visits']);
        $sales = isset($h['chart.series.sales']);
        if ($visits && $sales) {
            return [];
        }

        return array_map(static function (array $d) use ($visits, $sales): array {
            if ($visits) {
                $d['sessions'] = null;
            }
            if ($sales) {
                foreach (['purchases', 'revenue', 'revenue_usd'] as $k) {
                    $d[$k] = null;
                }
            }

            return $d;
        }, $series);
    }

    private static function slices(?array $slices, array $h): ?array
    {
        return $slices === null ? null : array_map(static fn (array $s): array => self::cols($s, $h), $slices);
    }

    private static function funnel(array $steps, array $h): array
    {
        if (isset($h['chart.funnel'])) {
            return [];
        }
        $map = ['sessions' => 'stage.sessions', 'add_to_carts' => 'stage.add_to_cart', 'checkouts' => 'stage.checkout', 'purchases' => 'stage.purchase'];

        return array_values(array_filter($steps, static fn ($s): bool => is_array($s) && ! isset($h[$map[$s['stage'] ?? ''] ?? ''])));
    }

    private static function tables(array $tables, array $h): array
    {
        $out = [];
        foreach ($tables as $name => $table) {
            if (isset($h['table.'.$name]) || ! is_array($table)) {
                continue;
            }
            $table['rows'] = array_values(array_map(static fn (array $r): array => self::cols($r, $h), array_filter((array) ($table['rows'] ?? []), static fn ($r): bool => is_array($r) && ! isset($h['platform:'.($r['platform'] ?? '')]))));
            $out[$name] = $table;
        }

        return $out;
    }

    private static function productsPart(array $part, array $h): array
    {
        if (is_array($part['table']['rows'] ?? null)) {
            $part['table']['rows'] = array_map(static fn (array $r): array => self::cols($r, $h), $part['table']['rows']);
        }

        return $part;
    }

    /** @return array<string, mixed>|null */
    private static function gap(array $gap, array $h): ?array
    {
        $rows = [];
        foreach ((array) ($gap['rows'] ?? []) as $r) {
            if (isset($h['platform:'.($r['platform'] ?? '')])) {
                continue;
            }
            foreach (['reported', 'ga4'] as $side) {
                if (is_array($r[$side] ?? null)) {
                    $r[$side] = self::cols($r[$side], $h);
                }
            }
            if (isset($h['attr.sales']) || isset($h['attr.gap'])) {
                foreach (['conversions', 'conversions_share', 'revenue_usd', 'revenue_share'] as $k) {
                    $r['gap'][$k] = null;
                }
                if (isset($h['attr.sales']) && is_array($r['ga4'] ?? null)) {
                    foreach (['purchases', 'revenue', 'revenue_usd'] as $k) {
                        $r['ga4'][$k] = null;
                    }
                }
            }
            $rows[] = $r;
        }
        $gap['rows'] = $rows;
        $gap['unpaid'] = isset($h['attr.unattributed']) ? [] : array_map(static fn (array $r): array => self::cols($r, $h), (array) ($gap['unpaid'] ?? []));
        if (is_array($gap['other_paid'] ?? null)) {
            $gap['other_paid'] = self::cols($gap['other_paid'], $h);
        }

        return $rows === [] && $gap['unpaid'] === [] && ($gap['other_paid'] ?? null) === null ? null : $gap;
    }

    /** @return array<string, mixed>|null */
    private static function attribution(array $a, array $h): ?array
    {
        $salesFields = ['purchases', 'revenue', 'revenue_usd', 'aov_usd', 'conversion_rate'];
        $platforms = [];
        foreach ((array) ($a['platforms'] ?? []) as $p) {
            if (isset($h['platform:'.($p['platform'] ?? '')])) {
                continue;
            }
            $cut = static function (?array $side) use ($h, $salesFields): ?array {
                if ($side === null) {
                    return null;
                }
                if (isset($h['attr.sales'])) {
                    foreach ($salesFields as $k) {
                        if (array_key_exists($k, $side)) {
                            $side[$k] = null;
                        }
                    }
                }
                if (isset($h['attr.claimed'])) {
                    foreach (['conversions', 'spend_usd', 'clicks'] as $k) {
                        if (array_key_exists($k, $side)) {
                            $side[$k] = null;
                        }
                    }
                }

                return self::cols($side, $h);
            };
            $gapOff = isset($h['attr.gap']) || isset($h['attr.sales']);
            $p['claimed'] = isset($h['attr.claimed']) ? null : $cut($p['claimed'] ?? null);
            $p['ga4'] = $cut($p['ga4'] ?? null);
            if (is_array($p['channel'] ?? null)) {
                $p['channel']['ga4'] = $cut($p['channel']['ga4'] ?? null);
                if (isset($h['attr.confidence'])) {
                    $p['channel']['confidence'] = null;
                }
            }
            if (isset($p['confidence']) && isset($h['attr.confidence'])) {
                $p['confidence'] = null;
            }
            if (isset($p['gap']) && $gapOff) {
                $p['gap'] = array_map(static fn () => null, $p['gap']);
            }
            foreach ((array) ($p['campaigns'] ?? []) as $i => $c) {
                $c['claimed'] = isset($h['attr.claimed']) ? null : $cut($c['claimed'] ?? null);
                $c['ga4'] = $cut($c['ga4'] ?? null);
                if (isset($h['attr.confidence'])) {
                    $c['confidence'] = null;
                }
                if ($gapOff) {
                    $c['gap'] = array_map(static fn () => null, (array) ($c['gap'] ?? []));
                }
                if (isset($h['attr.sales'])) {
                    $c['roas_ga4'] = null;
                    $c['cost_per_purchase_usd'] = null;
                }
                $p['campaigns'][$i] = $c;
            }
            $platforms[] = $p;
        }
        $a['platforms'] = $platforms;
        if (isset($h['attr.unattributed'])) {
            $a['unattributed'] = null;
            $a['other_paid'] = null;
        } elseif (is_array($a['unattributed'] ?? null)) {
            $a['unattributed']['classes'] = array_map(static fn (array $r): array => self::cols($r, $h), (array) ($a['unattributed']['classes'] ?? []));
        }
        if (isset($h['attr.unmatched'])) {
            $a['unmatched'] = null;
        }

        return $platforms === [] && ($a['unattributed'] ?? null) === null && ($a['unmatched'] ?? null) === null ? null : $a;
    }

    /** @return array<string, mixed>|null */
    private static function reasons(array $r, array $h): ?array
    {
        if (isset($h['gap.headline'])) {
            foreach (['gap', 'claimed', 'measured'] as $k) {
                $r['headline'][$k] = null;
            }
        }
        $r['reasons'] = array_values(array_filter((array) ($r['reasons'] ?? []), static fn ($x): bool => is_array($x) && ! isset($h['gap.reason.'.($x['key'] ?? '')])));
        $r['hidden_reasons'] = true;

        return $r['reasons'] === [] && ($r['headline']['gap'] ?? null) === null ? null : $r;
    }
}
