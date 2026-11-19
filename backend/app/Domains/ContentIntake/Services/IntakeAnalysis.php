<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\ContentIntake\Analysis\AiProviderAnalyzer;
use App\Domains\ContentIntake\Analysis\DeterministicAnalyzer;
use App\Domains\ContentIntake\Models\ContentIntakeItem;
use Illuminate\Support\Carbon;

/**
 * CONTENT-INTAKE-001 — runs the pre-checks, the deterministic facts, the history comparison and the (parked) AI
 * adapter for one item and stores the result on it.
 *
 * Readiness = the share of MEASURED checks that pass (a warning counts half). A check that could not be measured is
 * left out of the score and listed, so a file the server could not read does not score 100. The history fit is a
 * separate figure beside it — never folded into the score.
 *
 * Every recommendation cites the measured fact that triggers it (a check's own reason, or the history figures with the
 * peer counts). None states a cause.
 */
final class IntakeAnalysis
{
    public function __construct(
        private readonly IntakePreChecks $checks,
        private readonly ContentHistory $history,
        private readonly DeterministicAnalyzer $facts,
        private readonly AiProviderAnalyzer $ai,
    ) {}

    public function run(ContentIntakeItem $item): ContentIntakeItem
    {
        $out = $this->checks->run($item);
        $history = $this->history->beforeLaunch($item);

        $measured = array_values(array_filter($out['checks'], static fn (array $c): bool => $c['status'] !== IntakePreChecks::NOT_MEASURED));
        $points = 0.0;
        foreach ($measured as $c) {
            $points += $c['status'] === IntakePreChecks::PASS ? 1.0 : ($c['status'] === IntakePreChecks::WARN ? 0.5 : 0.0);
        }

        $item->forceFill([
            'checks' => $out['checks'],
            'check_result' => $out['result'],
            'readiness_score' => $measured === [] ? null : (int) round(100 * $points / count($measured)),
            'analysis' => [
                'facts' => $this->facts->analyze($item),
                'history' => $history,
                'recommendations' => $this->recommendations($out['checks'], $history),
                'coverage' => ['measured' => count($measured), 'total' => count($out['checks'])],
            ],
            'ai' => $this->ai->analyze($item),
            'analyzed_at' => Carbon::now(),
        ])->save();

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>  $history
     * @return list<array<string, mixed>>
     */
    private function recommendations(array $checks, array $history): array
    {
        $out = [];
        foreach ($checks as $c) {
            if ($c['status'] === IntakePreChecks::FAIL || $c['status'] === IntakePreChecks::WARN) {
                $out[] = [
                    'key' => 'fix_'.$c['key'],
                    'severity' => $c['status'] === IntakePreChecks::FAIL ? 'must' : 'should',
                    'fact_ar' => $c['reason_ar'],
                    'fact_en' => $c['reason_en'],
                ];
            }
        }

        if (($history['state'] ?? null) === 'insufficient') {
            $out[] = $this->rec('history_insufficient', 'info', ['peers' => (int) $history['peers'], 'needed' => (int) $history['needed']]);
        }
        if (($history['state'] ?? null) === 'ok' && isset($history['same_format'], $history['other_formats'])) {
            $same = $history['same_format'];
            $other = $history['other_formats'];
            if ($same['count'] >= ContentHistory::MIN_GROUP && $other['count'] >= ContentHistory::MIN_GROUP
                && $same['average'] !== null && $other['average'] !== null) {
                $spec = RankingMetric::of((string) $history['metric']);
                $better = $spec->direction === RankingDirection::HigherIsBetter
                    ? $same['average'] > $other['average']
                    : $same['average'] < $other['average'];
                $out[] = $this->rec($better ? 'format_stronger' : 'format_weaker', 'info', [
                    'format' => (string) $history['format'],
                    'metric_ar' => $spec->labelAr, 'metric_en' => $spec->labelEn,
                    'same' => $same['average'], 'other' => $other['average'],
                    'n_same' => $same['count'], 'n_other' => $other['count'], 'days' => (int) $history['window_days'],
                ]);
            }
        }

        return $out;
    }

    /** @param array<string, scalar> $params @return array<string, mixed> */
    private function rec(string $key, string $severity, array $params): array
    {
        $p = $params;
        $ar = trans("content_intake.recommendations.{$key}", $p + ['metric' => $params['metric_ar'] ?? ''], 'ar');
        $en = trans("content_intake.recommendations.{$key}", $p + ['metric' => $params['metric_en'] ?? ''], 'en');

        return ['key' => $key, 'severity' => $severity, 'fact_ar' => $ar, 'fact_en' => $en, 'params' => $params];
    }
}
