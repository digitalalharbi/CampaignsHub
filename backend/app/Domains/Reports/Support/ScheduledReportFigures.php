<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * What a scheduled report's email may say, from the client view of its snapshot — EMAIL-GAPS.
 *
 * An allow-list on purpose. HiddenMoney and ClientReportView remove things from a snapshot; this picks
 * the few results a decision needs, so a key added to the snapshot next year cannot reach an inbox by
 * being forgotten here. No spend and nothing derived from it (no CPA, CPL, CPM, CPC or ROAS). No campaign
 * names: the only names are content names, cleaned by {@see ClientContentName}.
 */
final class ScheduledReportFigures
{
    /** In the order a reader wants them. Revenue is a result; the money that bought it is not. */
    private const RESULTS = [
        'conversions' => ['ar' => 'النتائج', 'en' => 'Results'],
        'revenue' => ['ar' => 'الإيرادات', 'en' => 'Revenue'],
        'reach' => ['ar' => 'الوصول', 'en' => 'Reach'],
        'impressions' => ['ar' => 'مرات الظهور', 'en' => 'Impressions'],
        'clicks' => ['ar' => 'النقرات', 'en' => 'Clicks'],
    ];

    private const MAX_CARDS = 4;

    /**
     * @param  array<string,mixed>  $data  the output of `ClientReportView::filter()`
     * @return array{cards: list<array{label: string, value: string, unit: ?string, up: ?string}>, best: ?string, improve: ?string}
     */
    public static function from(array $data, bool $ar): array
    {
        return ['cards' => self::cards($data, $ar)] + self::content((array) ($data['ads'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array{label: string, value: string, unit: ?string, up: ?string}>
     */
    private static function cards(array $data, bool $ar): array
    {
        $kpis = (array) ($data['kpis'] ?? []);
        $reported = (array) ($data['reported'] ?? []);
        $cards = [];

        foreach (self::RESULTS as $key => $label) {
            $value = $kpis[$key] ?? null;

            // Missing is not zero, and a figure no platform reported has no cell.
            if (! is_numeric($value) || (float) $value <= 0 || ($reported !== [] && ($reported[$key] ?? false) !== true)) {
                continue;
            }

            $change = $data['delta'][$key] ?? null;

            $cards[] = [
                'label' => $label[$ar ? 'ar' : 'en'],
                // Whole units in a four-column strip: «96,500.00 SAR» wrapped and broke the row's alignment.
                'value' => number_format((float) $value),
                'unit' => $key === 'revenue' ? (string) ($data['currency'] ?? '') : null,
                'up' => is_numeric($change) && (float) $change > 0 ? '+'.rtrim(rtrim(number_format((float) $change, 1), '0'), '.').'%' : null,
            ];

            if (count($cards) === self::MAX_CARDS) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param  array<int|string,mixed>  $ads
     * @return array{best: ?string, improve: ?string}
     */
    private static function content(array $ads): array
    {
        $rows = array_values(array_filter($ads, static fn (mixed $row): bool => is_array($row)
            && is_string($row['name'] ?? null) && (float) ($row['impressions'] ?? 0) > 0));

        usort($rows, static fn (array $a, array $b): int => [(float) ($b['conversions'] ?? 0), (float) ($b['clicks'] ?? 0)]
            <=> [(float) ($a['conversions'] ?? 0), (float) ($a['clicks'] ?? 0)]);

        if ($rows === []) {
            return ['best' => null, 'improve' => null];
        }

        return [
            'best' => ClientContentName::clean($rows[0]['name']),
            'improve' => count($rows) > 1 ? ClientContentName::clean($rows[count($rows) - 1]['name']) : null,
        ];
    }
}
