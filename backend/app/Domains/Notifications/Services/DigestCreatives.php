<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePulse;
use App\Domains\Campaigns\Services\CreativeRows;
use Illuminate\Support\Carbon;

/**
 * The content half of a digest — MAIL-005.
 *
 * ## Why this reads the product's own services rather than its own query
 *
 * «أفضل محتوى» and «المحتويات المتراجعة» in an email have to be the same creatives the reader will
 * see when they open the link underneath. A second query, however careful, is a second definition
 * of «best» — and the first time the two disagree the email is the thing that gets distrusted,
 * because it is the copy nobody can check without switching windows.
 *
 * So: `CreativeRows` presents exactly as the library does, and `CreativePulse` ranks exactly as the
 * dashboard does. This class only picks the three or four lines an email has room for.
 *
 * ## Silence is a valid answer
 *
 * A project with no creatives, or with none above the evidence floor, returns nothing and the email
 * omits the section. A «best content: —» row is a heading that promises an answer and then admits it
 * has none, which is worse than not asking.
 */
final class DigestCreatives
{
    /** How many lines an email can carry per list before it stops being scannable. */
    private const LIMIT = 3;

    public function __construct(
        private readonly CreativeRows $rows,
        private readonly CreativePulse $pulse,
    ) {}

    /**
     * @return array<string,mixed>|null null when there is nothing worth a section
     */
    public function forProject(string $projectId, Carbon $from, Carbon $to): ?array
    {
        $creatives = ExternalCreative::query()
            ->where('project_id', $projectId)
            ->limit(500)
            ->get();

        if ($creatives->isEmpty()) {
            return null;
        }

        // `withPrevious` is what makes «declining» mean anything: without the earlier window there
        // is no direction, only a level.
        $presented = $this->rows->present($creatives, $from, $to, withFatigue: true, withPrevious: true);
        $pulse = $this->pulse->build($presented, $from, $to);

        /*
         * Each of these arrives in the shape the DASHBOARD reads, and is unwrapped here rather than
         * reshaped upstream. `best_by_objective` is a group per objective with its winner nested
         * inside, `declining` wraps its rows in a move record, and `fatigue.fatigued` is a capped
         * list — three shapes because three questions, and flattening them at the source would cost
         * the dashboard the context it renders.
         */
        $best = $this->line($this->firstOf($pulse['best_by_objective'] ?? []));
        $declining = $this->lines(array_map(
            static fn (array $move): array => (array) ($move['creative'] ?? []),
            (array) (($pulse['declining'] ?? [])['items'] ?? []),
        ));
        $fatigued = $this->lines((array) ((($pulse['fatigue'] ?? [])['fatigued'] ?? [])['items'] ?? []));

        if ($best === null && $declining === [] && $fatigued === []) {
            return null;
        }

        return [
            'best' => $best,
            'declining' => $declining,
            'fatigued' => $fatigued,
            'counted' => (int) ($pulse['totals']['with_metrics'] ?? 0),
        ];
    }

    /**
     * The highest-spending entry of an objective-keyed map.
     *
     * An email has room for one «best content». Picking by spend rather than by rank across
     * objectives avoids comparing a brand creative's CPM with a sales creative's ROAS, which is the
     * §14.6 mistake one level down.
     *
     * @param  array<string,mixed>  $byObjective
     */
    private function firstOf(array $byObjective): ?array
    {
        // Only groups that actually produced a winner — a group with enough spend to be listed and
        // not enough evidence to name one is a real state, and it is not an answer.
        $groups = array_values(array_filter(
            $byObjective,
            static fn ($g): bool => is_array($g) && is_array($g['creative'] ?? null),
        ));
        usort($groups, static fn (array $a, array $b): int => (float) ($b['spend'] ?? 0) <=> (float) ($a['spend'] ?? 0));

        if ($groups === []) {
            return null;
        }

        // The group carries WHY it won — the metric and its value — and the creative carries the
        // name. The email needs both, so they are stitched here rather than at the template.
        $winner = (array) $groups[0]['creative'];
        $winner['reason'] = $this->reason($groups[0]);

        return $winner;
    }

    /** «أعلى ROAS (8.42×)» — the metric the group was judged on, and what it scored. */
    private function reason(array $group): ?string
    {
        $metric = $group['metric'] ?? null;
        $value = $group['value'] ?? null;
        if ($metric === null || ! is_numeric($value)) {
            return null;
        }

        return match ((string) $metric) {
            'roas' => sprintf('أعلى عائد على الإنفاق (%s×)', number_format((float) $value, 2)),
            'cpa' => sprintf('أقل تكلفة نتيجة (%s)', number_format((float) $value, 2)),
            'cpm' => sprintf('أقل تكلفة ألف ظهور (%s)', number_format((float) $value, 2)),
            'ctr' => sprintf('أعلى معدل نقر (%s%%)', number_format((float) $value * 100, 2)),
            default => null,
        };
    }

    /** @param array<string,mixed>|null $row */
    private function line(?array $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $name = $row['name'] ?? $row['creative_name'] ?? null;
        if ($name === null || $name === '') {
            return null;
        }

        return [
            'name' => (string) $name,
            'provider' => (string) ($row['provider'] ?? ''),
            // The reason the ranking service produced, carried verbatim: an email that says «best»
            // without saying on what is a claim the reader cannot check.
            'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function lines(array $rows): array
    {
        $out = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $line = $this->line($row);
            if ($line !== null) {
                $out[] = $line;
            }
        }

        return $out;
    }
}
