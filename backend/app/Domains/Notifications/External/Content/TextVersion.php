<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

/**
 * The plain-text part of an external message, in the same order as the HTML (spec §11), with every chart written out as
 * text — each platform's full figure and share, each day's spend, each KPI with its change — so a reader without images
 * (or a text-only client) loses no number. The HTML shows compact figures (4.2K); the text and the alt text carry the exact ones.
 */
final class TextVersion
{
    /** @param array<string, mixed> $m ReportView::build() */
    public static function of(array $m): string
    {
        $l = [];
        if ($m['test_banner']) {
            $l[] = '['.$m['test_banner'].']';
        }
        if ($m['copy_band']) {
            $l[] = '['.$m['copy_band'].']';
        }
        $l[] = $m['workspace'].' — '.$m['badge'];
        $l[] = $m['managed_by'];
        $l[] = $m['title'].' · '.$m['period'].' ('.$m['period_range'].')';
        $l[] = '';
        $l[] = $m['greeting'].' '.$m['privacy'];
        if ($m['updating']) {
            $l[] = $m['updating'];
        }
        foreach ((array) $m['order'] as $block) {
            $part = match ($block) {
                'status' => $m['verdict'] === null ? [] : [$m['status']['label'].' — '.$m['verdict']['name'].': '.$m['verdict']['full'].' '.$m['verdict']['kpi']['label'].($m['verdict']['kpi']['change'] !== '' ? ' ('.$m['verdict']['kpi']['change'].')' : '')],
                'spend_total' => [$m['labels']['spend'].': '.$m['spend_total']['spend'], $m['spend_total']['left_label'].': '.$m['spend_total']['remaining']],
                'spend_card' => self::budget($m),
                'kpis' => array_map(static fn (array $g): string => '- '.$g['label'].': '.implode(' | ', array_map(static fn (array $k): string => $k['label'].' '.$k['value'].($k['change'] !== '' ? ' ('.$k['change'].')' : ''), $g['kpis'])), $m['kpi_groups']),
                'tiles' => [implode(' | ', array_map(static fn (array $k): string => $k['label'].' '.$k['value'].($k['change'] !== '' ? ' ('.$k['change'].')' : ''), $m['tiles']))],
                'trend' => $m['trend'] === null ? [] : array_merge([$m['trend']['title'].' — '.$m['trend']['alt']], array_map(static fn (array $r): string => '  '.$r['date'].': '.$r['spend'], $m['trend']['rows'])),
                'platforms' => $m['platforms'] === null ? [] : array_merge([$m['platforms']['title'].' — '.$m['platforms']['alt']], array_map(static fn (array $r): string => '  '.$r['label'].': '.$r['spend'].' ('.$r['share'].')'.($r['delta'] ? ' '.$r['delta']['text'] : ''), $m['platforms']['rows'])),
                'strip' => [implode(' · ', array_map(static fn (array $c): string => '✓ '.$c['short'], $m['strip']))],
                'best_worst' => array_map(static fn (array $b): string => $b['title'].': '.$b['name'].' '.$b['value'].' '.$b['sub'], $m['best_worst']),
                'top' => $m['top'] === null ? [] : array_merge([$m['top']['title']], array_map(static fn (array $r): string => ($r['rank'] !== null ? $r['rank'].'. ' : '- ').$r['label'].' — '.$r['value'].($r['delta'] ? ' '.$r['delta']['text'] : ''), $m['top']['rows'])),
                'chips' => $m['chips'] === [] ? [] : array_merge([$m['chips_title']], array_map(static fn (array $c): string => '- '.$c['text'], $m['chips'])),
                'ideas' => $m['ideas'] === [] ? [] : array_merge([$m['ideas_title']], array_map(static fn (string $i): string => '- '.$i, $m['ideas'])),
                default => [],
            };
            if ($part !== []) {
                $l[] = '';
                array_push($l, ...$part);
            }
        }
        $l[] = '';
        $l[] = $m['signature'];
        $l[] = '';
        $l[] = $m['why'];
        $l[] = $m['manage_label'].': '.$m['manage_url'];
        if ($m['contact']) {
            $l[] = $m['contact']['label'].': '.$m['contact']['url'];
        }
        if ($m['footprint']) {
            $l[] = $m['footprint']['text'].' — '.$m['footprint']['link'];
            if ($m['footprint']['invitation']) {
                $l[] = $m['footprint']['invitation'];
            }
        }
        $l[] = '';
        $l[] = $m['disclaimer'];
        $l[] = $m['created'].' · '.$m['sources'].' · '.$m['reference'];
        if ($m['fx']) {
            $l[] = $m['fx'];
        }

        return implode("\n", $l);
    }

    /** @param array<string, mixed> $m @return list<string> */
    private static function budget(array $m): array
    {
        $b = $m['spend_card'];
        if ($b === null) {
            return [];
        }
        if ($b['state'] === 'none') {
            return [$m['project'].' — '.$b['state_label'], $m['labels']['spend'].': '.$b['spend_text']];
        }

        return array_values(array_filter([
            $m['project'].' — '.$b['state_label'],
            $m['labels']['spend'].': '.$b['spend_text'],
            $b['cap_text'].' | '.$b['consumed_text'].' | '.$b['remaining_text'],
            $b['days_text'] !== '' ? $b['days_text'] : null,
            $b['equivalent'],
        ]));
    }
}
