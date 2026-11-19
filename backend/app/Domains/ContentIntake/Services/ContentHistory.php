<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\Campaigns\Creative\CreativeRanking;
use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Campaigns\Support\ObjectiveResult;
use App\Domains\ContentIntake\Models\ContentIntakeItem;
use Illuminate\Support\Carbon;

/**
 * CONTENT-INTAKE-001 — what the project's MEASURED history says about content like this item.
 *
 * It does not rank by its own rule: the peers are the project's synced creatives that ran for the same objective
 * family on the same platform, and they are ordered by the one canonical {@see CreativeRanking} on the objective's
 * own metric. Nothing is read from a model; a figure is either in the synced data or it is not stated.
 *
 * With fewer than MIN_PEERS measured peers the answer is `insufficient`, with the count — «we cannot tell yet» is
 * a state, not a low score.
 */
final class ContentHistory
{
    public const MIN_PEERS = 5;

    public const MIN_GROUP = 3;

    private const WINDOW_DAYS = 90;

    private const MAX_PEERS = 300;

    public function __construct(private readonly CreativeRows $rows, private readonly CreativeRanking $ranking) {}

    /**
     * Before launch: the item's format against the project's measured peers.
     *
     * @return array<string, mixed>
     */
    public function beforeLaunch(ContentIntakeItem $item): array
    {
        $family = ObjectiveFamily::tryFrom((string) $item->objective);
        if ($family === null || $family === ObjectiveFamily::Unknown || $item->provider === null) {
            return ['state' => 'no_target'];
        }
        $peers = $this->peers($item->project_id, $family, $item->provider, null);
        $out = $this->summarise($peers, $family);
        if ($out['state'] !== 'ok') {
            return $out + ['family' => $family->value, 'provider' => $item->provider];
        }

        $format = in_array($item->kind, ['image', 'video'], true) ? $item->kind : null;
        $ranked = $out['_ranked'];
        $metric = (string) $out['metric'];
        unset($out['_ranked']);
        $out['family'] = $family->value;
        $out['provider'] = $item->provider;
        $out['format'] = $format;

        if ($format !== null) {
            $top = array_slice($ranked, 0, max(1, (int) ceil(count($ranked) / 4)));
            $out['top_quartile_count'] = count($top);
            $out['top_quartile_same_format'] = count(array_filter($top, static fn (array $r): bool => ($r['format'] ?? null) === $format));
            $same = array_values(array_filter($ranked, static fn (array $r): bool => ($r['format'] ?? null) === $format));
            $other = array_values(array_filter($ranked, static fn (array $r): bool => ($r['format'] ?? null) !== $format));
            $out['same_format'] = ['count' => count($same), 'average' => $this->avg($same, $metric)];
            $out['other_formats'] = ['count' => count($other), 'average' => $this->avg($other, $metric)];
            $out['fit_percent'] = (int) round(100 * $out['top_quartile_same_format'] / max(1, $out['top_quartile_count']));
        }

        return $out;
    }

    /**
     * After launch: the linked creative against the same objective + platform average, on the same window.
     *
     * @return array<string, mixed>
     */
    public function afterLaunch(ContentIntakeItem $item): array
    {
        if ($item->linked_creative_id === null) {
            return ['state' => 'not_linked'];
        }
        $family = ObjectiveFamily::tryFrom((string) $item->objective);
        if ($family === null || $family === ObjectiveFamily::Unknown || $item->provider === null) {
            return ['state' => 'no_target'];
        }
        $peers = $this->peers($item->project_id, $family, $item->provider, 30, $item->linked_creative_id);
        $self = array_values(array_filter($peers, static fn (array $r): bool => ($r['id'] ?? null) === $item->linked_creative_id));
        if ($self === []) {
            return ['state' => 'no_delivery', 'family' => $family->value];
        }
        $summary = $this->summarise($peers, $family);
        if ($summary['state'] !== 'ok') {
            return $summary + ['family' => $family->value];
        }
        $ranked = $summary['_ranked'];
        $metric = (string) $summary['metric'];
        $mine = $self[0][$metric] ?? null;
        if (! is_numeric($mine)) {
            return ['state' => 'not_reported', 'metric' => $metric, 'family' => $family->value];
        }
        $others = array_values(array_filter($ranked, static fn (array $r): bool => ($r['id'] ?? null) !== $item->linked_creative_id));
        $average = $this->avg($others, $metric);
        $position = 0;
        foreach ($ranked as $i => $r) {
            if (($r['id'] ?? null) === $item->linked_creative_id) {
                $position = $i + 1;
            }
        }

        return [
            'state' => 'ok',
            'family' => $family->value,
            'metric' => $metric,
            'value' => (float) $mine,
            'average' => $average,
            'delta_percent' => $average !== null && $average > 0 ? round(((float) $mine - $average) / $average * 100, 1) : null,
            'higher_is_better' => RankingMetric::of($metric)->direction === RankingDirection::HigherIsBetter,
            'peers' => count($others),
            'rank' => $position === 0 ? null : $position,
            'ranked' => count($ranked),
            'window_days' => 30,
        ];
    }

    /**
     * @return list<array<string, mixed>> flat rows: id, name, format, provider, objective + every metric key
     */
    private function peers(string $projectId, ObjectiveFamily $family, string $provider, ?int $days, ?string $includeId = null): array
    {
        $to = Carbon::today();
        $from = $to->copy()->subDays(($days ?? self::WINDOW_DAYS) - 1);
        $query = ExternalCreative::query()->where('project_id', $projectId)->where('provider', $provider);
        $this->rows->applyPeriodActivity($query, $from, $to);
        $creatives = $query->orderBy('id')->limit(self::MAX_PEERS)->get();
        $lean = $this->rows->lean($creatives, $from, $to);

        $out = [];
        foreach ($lean as $row) {
            if (ObjectiveResult::familyOf($row['objective'] ?? null) !== $family) {
                continue;
            }
            $out[] = array_merge($row, (array) ($row['metrics'] ?? []));
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $peers
     * @return array<string, mixed>
     */
    private function summarise(array $peers, ObjectiveFamily $family): array
    {
        if (count($peers) < self::MIN_PEERS) {
            return ['state' => 'insufficient', 'peers' => count($peers), 'needed' => self::MIN_PEERS];
        }
        $metric = $this->ranking->resolveMetric($peers, $family);
        if ($metric === null) {
            return ['state' => 'not_reported', 'peers' => count($peers)];
        }
        $ranked = $this->ranking->rank($peers, $family, $metric);
        if (count($ranked['ranked']) < self::MIN_PEERS) {
            return ['state' => 'insufficient', 'peers' => count($ranked['ranked']), 'needed' => self::MIN_PEERS];
        }
        $best = $ranked['ranked'][0];

        return [
            'state' => 'ok',
            'metric' => $metric,
            'peers' => count($ranked['ranked']),
            'excluded' => count($ranked['excluded']),
            'window_days' => self::WINDOW_DAYS,
            'average' => $this->avg($ranked['ranked'], $metric),
            'best' => ['name' => (string) ($best['name'] ?? ''), 'format' => $best['format'] ?? null, 'value' => (float) $best[$metric]],
            '_ranked' => $ranked['ranked'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function avg(array $rows, string $metric): ?float
    {
        $vals = array_values(array_filter(array_map(static fn (array $r) => $r[$metric] ?? null, $rows), 'is_numeric'));

        return $vals === [] ? null : round(array_sum(array_map('floatval', $vals)) / count($vals), 4);
    }
}
