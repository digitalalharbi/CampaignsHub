<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Support\ObjectiveResult;
use App\Domains\Reports\Services\CreativeRankingService;

/**
 * CONTENT-CARDS-001 — where each piece of content stands among the content bought for the SAME objective.
 *
 * It adds no ranking rule. The order, the «material» gate, the «positive figure» gate and the two headline lists are the
 * report's own: {@see CreativeRankingService::rank()} gives «الأفضل أداءً» and {@see CreativeRankingService::worst()} gives
 * «محتوى بفرصة للتحسين», called with the rows shaped the way the report's ads section shapes them, so the same content in
 * the same scope gets the same class here as on the live report. What this adds is the POSITION («3 of 12») and the
 * objective's own result (orders, visits or leads — {@see ObjectiveResult}).
 *
 * Nothing is estimated: a row the ranker leaves out (not measured, not material, no positive figure) has no rank and no
 * class, and the card says «غير مُقاس» instead of placing it last.
 */
final class ContentStanding
{
    /** The report's headline lists hold five each; so do these. */
    public const HEADLINE = 5;

    public function __construct(private readonly CreativeRankingService $ranker) {}

    /**
     * @param  list<array<string, mixed>>  $present  rows as `CreativeRows::present()` returns them
     * @return array{items: list<array<string, mixed>>, why_rows: list<array<string, mixed>>}
     */
    public function for(array $present): array
    {
        $byFamily = [];
        $flat = [];
        foreach ($present as $row) {
            $family = $this->familyOf($row['objective'] ?? null);
            $metrics = (array) ($row['metrics'] ?? []);
            // Shaped like `ReportAds`' rankable rows: the figures spread, the four sums cast the way the report casts them.
            $flat[(string) $row['id']] = $metrics + [
                'id' => (string) $row['id'], 'provider' => (string) $row['provider'], 'objective' => $row['objective'] ?? null,
            ];
            $flat[(string) $row['id']]['impressions'] = (float) ($metrics['impressions'] ?? 0);
            $flat[(string) $row['id']]['clicks'] = (float) ($metrics['clicks'] ?? 0);
            $flat[(string) $row['id']]['conversions'] = (float) ($metrics['conversions'] ?? 0);
            $flat[(string) $row['id']]['revenue'] = (float) ($metrics['revenue'] ?? 0);
            $byFamily[$family->value][] = (string) $row['id'];
        }

        $standing = [];
        foreach ($byFamily as $familyValue => $ids) {
            $rows = array_map(static fn (string $id): array => $flat[$id], $ids);
            $family = ObjectiveFamily::from($familyValue);
            $ranked = $family === ObjectiveFamily::Unknown ? [] : $this->ranker->rank($familyValue, $rows, PHP_INT_MAX);
            $best = $family === ObjectiveFamily::Unknown ? [] : array_column($this->ranker->rank($familyValue, $rows, self::HEADLINE), 'id');
            $worst = $family === ObjectiveFamily::Unknown ? [] : array_column($this->ranker->worst($familyValue, $rows, self::HEADLINE), 'id');
            $metric = $ranked === [] ? null : $this->ranker->metricFor($familyValue, $rows);
            $spec = $metric === null ? null : RankingMetric::of($metric);
            $position = array_flip(array_column($ranked, 'id'));

            foreach ($ids as $id) {
                $class = in_array($id, $best, true) ? 'best' : (in_array($id, $worst, true) ? 'improve' : null);
                $standing[$id] = [
                    'family' => $familyValue,
                    'metric' => $metric,
                    'metric_label_ar' => $spec?->labelAr,
                    'metric_label_en' => $spec?->labelEn,
                    'higher_is_better' => $spec === null ? null : $spec->direction === RankingDirection::HigherIsBetter,
                    'rank' => isset($position[$id]) ? $position[$id] + 1 : null,
                    'of' => $ranked === [] ? null : count($ranked),
                    'class' => $class,
                ];
            }
        }

        $items = [];
        $why = [];
        foreach ($present as $row) {
            $id = (string) $row['id'];
            $metrics = (array) ($row['metrics'] ?? []);
            $family = ObjectiveFamily::from($standing[$id]['family']);
            $result = ObjectiveResult::resolve($family, $metrics, is_array($metrics['reported'] ?? null) ? $metrics['reported'] : null);
            $items[] = ['id' => $id, 'objective' => $row['objective'] ?? null, 'provider' => (string) $row['provider']] + $standing[$id] + [
                'result' => [
                    'key' => $result['result_key'], 'value' => $result['result'],
                    'label_ar' => $result['result_label']['ar'] ?? null, 'label_en' => $result['result_label']['en'] ?? null,
                    'missing_ar' => $result['result_missing_label']['ar'] ?? null, 'missing_en' => $result['result_missing_label']['en'] ?? null,
                ],
            ];
            // The inputs «ليش نجح / ليش ما نجح» reads — the SAME fields, over the whole scope rather than one page of it.
            $why[] = [
                'id' => $id, 'name' => (string) $row['name'], 'provider' => (string) $row['provider'], 'objective' => $row['objective'] ?? null,
                'metrics' => [
                    'impressions' => $metrics['impressions'] ?? null, 'clicks' => $metrics['clicks'] ?? null,
                    'spend' => $metrics['spend'] ?? null, 'frequency' => $metrics['frequency'] ?? null,
                ],
                'fatigue' => ['status' => $row['fatigue']['status'] ?? 'insufficient_data', 'signals' => array_values((array) ($row['fatigue']['signals'] ?? []))],
            ];
        }

        return ['items' => $items, 'why_rows' => $why];
    }

    /** The family a row's objective belongs to — the report's own reading (`ReportAds::familyKeyOf`). */
    private function familyOf(mixed $objective): ObjectiveFamily
    {
        $objective = (string) $objective;

        return ObjectiveFamily::tryFrom($objective) ?? (CampaignObjective::tryFrom($objective)?->family() ?? ObjectiveFamily::Unknown);
    }
}
