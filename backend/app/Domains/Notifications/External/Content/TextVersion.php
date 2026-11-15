<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

/**
 * The plain-text part of an external message, in the same order as the HTML (spec §11), with every chart written out as
 * text (each platform's figure and share, each day's spend) so a reader without images loses nothing.
 */
final class TextVersion
{
    /** @param array<string, mixed> $m ReportView::build() */
    public static function of(array $m): string
    {
        $l = [];
        if ($m['copy_band']) {
            $l[] = '['.$m['copy_band']['title'].']'.($m['copy_band']['note'] ? ' '.$m['copy_band']['note'] : '');
        }
        $l[] = $m['workspace'].' — '.$m['badge'];
        $l[] = $m['managed_by'].' · '.$m['period'];
        $l[] = '';
        $l[] = $m['greeting'];
        $l[] = $m['privacy'];
        $l[] = $m['tone'].($m['event_line'] ? ' '.$m['event_line'] : '');
        if ($m['updating']) {
            $l[] = $m['updating'];
        }
        if ($m['opening']) {
            $l[] = '';
            $l[] = $m['opening']['label'].': '.$m['opening']['value'];
            $l[] = $m['opening']['sentence'];
            if ($m['opening']['ring']) {
                $l[] = $m['opening']['ring']['label'];
            }
        }
        if ($m['kpis'] !== []) {
            $l[] = '';
            $l[] = $m['kpis_title'];
            foreach ($m['kpis'] as $g) {
                $l[] = '- '.$g['objective'].': '.implode(' | ', array_map(static fn (array $k): string => $k['label'].' '.$k['value'], $g['cards']));
            }
        }
        if ($m['trend']) {
            $l[] = '';
            $l[] = $m['trend']['title'].' — '.$m['trend']['alt'];
            foreach ($m['trend']['rows'] as $r) {
                $l[] = '  '.$r['date'].': '.$r['spend'];
            }
        }
        if ($m['platforms']) {
            $l[] = '';
            $l[] = $m['platforms']['title'].' — '.$m['platforms']['alt'];
        }
        if ($m['project_card']) {
            $l[] = '';
            $l[] = $m['project'].' — '.$m['project_card']['status_label'];
            if ($b = $m['project_card']['budget']) {
                $l[] = $m['labels']['approved'].' '.$b['approved'].' | '.$m['labels']['spent'].' '.$b['spent'].' | '.$m['labels']['remaining'].' '.$b['remaining'].' | '.$m['labels']['pace'].' '.$b['pace'];
                foreach (['lasts_until', 'equivalent', 'advisory'] as $k) {
                    if ($b[$k]) {
                        $l[] = $b[$k];
                    }
                }
            } elseif ($m['project_card']['no_budget']) {
                $l[] = $m['project_card']['no_budget'];
            }
        }
        if ($m['top']) {
            $l[] = '';
            $l[] = $m['top']['title'];
            foreach ($m['top']['rows'] as $i => $r) {
                $l[] = ($i + 1).'. '.$r['name'].' — '.$r['detail'];
            }
        }
        foreach ([['achievements_title', 'achievements'], ['suggestions_title', 'suggestions']] as [$title, $key]) {
            if ($m[$key] !== []) {
                $l[] = '';
                $l[] = $m[$title];
                foreach ($m[$key] as $a) {
                    $l[] = '- '.$a;
                }
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
}
