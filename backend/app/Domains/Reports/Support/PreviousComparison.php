<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * REPORT-FILTERS-FUNCTIONAL-001 — «compared with the previous period», switched off, leaves the document.
 *
 * The switch (`sections.previous_comparison`, off on every client link the builder makes since REPORT-CLIENT-NO-DELTAS-001)
 * emptied the live payload's `deltas` and nothing else. A stored copy served through a link kept `previous` (the totals of the
 * window before) and `delta` (each KPI's movement) in the page, the print document and the PDF; the live payload kept every
 * platform's own `movement`, which the page drew. A ratio beside the figure it is a ratio of returns the figure it was
 * compared with, so removing one and leaving the other removes nothing.
 *
 * One list of where the comparison travels, read by the live payload and the stored one. Each key keeps the empty value its own
 * shape takes (the pages read these keys), the figures of the period itself are never touched, and the stored copy is not written.
 */
final class PreviousComparison
{
    /** The keys that ARE the comparison, each with the empty value its own readers expect: a list stays a list, a block becomes null. */
    private const OWNED = [
        'delta' => [],
        'deltas' => [],
        'previous' => null,
        'objective_performance_previous' => null,
    ];

    /** The attention finding whose whole figure is this period's cost against the last period's rate. */
    public const COMPARISON_IMPACT = 'extra_cost_vs_previous_rate';

    /** Row lists whose rows carry their own movement against the window before. */
    private const ROWS_WITH_MOVEMENT = ['platforms', 'accounts'];

    /**
     * @param  array<string, mixed>  $payload  a live payload or a stored document
     * @return array<string, mixed>
     */
    public static function strip(array $payload): array
    {
        foreach (self::OWNED as $key => $empty) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $empty;
            }
        }

        foreach (self::ROWS_WITH_MOVEMENT as $list) {
            if (is_array($payload[$list] ?? null)) {
                $payload[$list] = array_map(static function ($row) {
                    if (is_array($row)) {
                        unset($row['movement']);
                    }

                    return $row;
                }, $payload[$list]);
            }
        }

        /*
         * Built from their own window read, so no key above reaches them. A finding that IS a sum against the last window's rate leaves
         * whole; every other finding keeps what this period measured and loses the figure it was compared with and the ratio that returns it.
         */
        if (is_array($payload['attention'] ?? null)) {
            $payload['attention'] = array_values(array_map(static function ($item) {
                if (is_array($item)) {
                    $item['kpis'] = array_map(static function ($kpi) {
                        if (is_array($kpi)) {
                            unset($kpi['before'], $kpi['change']);
                        }

                        return $kpi;
                    }, (array) ($item['kpis'] ?? []));
                }

                return $item;
            }, array_filter($payload['attention'], static fn ($item): bool => ! (is_array($item) && (($item['impact']['kind'] ?? null) === self::COMPARISON_IMPACT)))));
        }

        // A note that says «against the previous period» in words is the same comparison; the other notes do not read that window.
        if (is_array($payload['observations'] ?? null)) {
            $payload['observations'] = array_values(array_filter(
                $payload['observations'],
                static fn ($note): bool => ! (is_array($note) && ($note['kind'] ?? null) === 'period_comparison'),
            ));
        }

        // CONTENT-FORMAT-COMPARISON-001 — the comparison carries each format's figures of the window before, and names that window.
        if (is_array($payload['content_format_comparison'] ?? null)) {
            $block = $payload['content_format_comparison'];
            unset($block['previous_period']);
            $block['objectives'] = array_map(static function ($objective) {
                if (is_array($objective)) {
                    $objective['formats'] = array_map(static function ($format) {
                        if (is_array($format)) {
                            unset($format['previous']);
                        }

                        return $format;
                    }, (array) ($objective['formats'] ?? []));
                }

                return $objective;
            }, (array) ($block['objectives'] ?? []));
            $payload['content_format_comparison'] = $block;
        }

        // The deck's «this period against the last» slide has nothing left to draw.
        if (is_array($payload['slides'] ?? null)) {
            $payload['slides'] = array_values(array_filter(
                $payload['slides'],
                static fn ($slide): bool => ! (is_array($slide) && ($slide['type'] ?? null) === 'comparison'),
            ));
        }

        return $payload;
    }

    /**
     * A row of the content section with no window before it: no `previous`, and a fatigue signal keeps its direction and loses the figure it compared with.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function stripCreativeRow(array $row): array
    {
        unset($row['previous']);
        if (is_array($row['fatigue']['signals'] ?? null)) {
            $row['fatigue']['signals'] = array_map(static function ($signal) {
                if (is_array($signal)) {
                    unset($signal['previous'], $signal['change']);
                }

                return $signal;
            }, $row['fatigue']['signals']);
        }

        return $row;
    }
}
