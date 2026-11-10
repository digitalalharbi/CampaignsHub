<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * DIGESTS-VISUAL-V2-001 — the ONE rule that says whether a platform of a project is fine.
 *
 * The Owner runs up to thirty projects with five to seven platforms each. He cannot read thirty
 * paragraphs, so every (project × platform) CELL and every project gets exactly one of four states, by
 * a rule that is deterministic, written down here, and tested (`DigestStatusTest`).
 *
 * ## The four states
 *
 * | state   | chip | meaning                                         |
 * |---------|------|-------------------------------------------------|
 * | `ok`    | ✓    | figures arrived and nothing below fired          |
 * | `watch` | !    | something is drifting; look today                |
 * | `bad`   | ✕    | money or data is being lost; decide now          |
 * | `none`  | –    | no figures (a missing figure is NEVER read as 0) |
 *
 * ## The rule per cell — first match wins, in this order
 *
 * 1. the source's last sync FAILED ............................ bad   (`sync_failed`)
 * 2. spend > 0 and the objective's result count is 0 (reported)  bad   (`no_results`)
 * 3. budget pace above {@see PACE_HOT}× .......................... bad   (`pace_hot`)
 * 4. the objective's headline figure worsened ≥ {@see WORSE_BAD} .... bad   (`worse`)
 * 5. the source is STALE (no fresh figures) ................... watch (`stale`)
 * 6. budget pace below {@see PACE_SLOW}× ........................ watch (`pace_slow`)
 * 7. the headline figure worsened ≥ {@see WORSE_WATCH} ............ watch (`worse`)
 * 8. the results fell ≥ {@see FALL_WATCH} against the previous window . watch (`results_down`)
 * 0.  an OPEN P1/P2 alert on the platform → bad (`alert_bad`), tested first; an open P3 or snoozed one → watch (`alert_watch`) only when
 *      the cell has figures — the in-app board's own rule ({@see alertState}), so mail and screen agree
 * 9. no spend figure and no failure ............................ none  (`no_data`)
 * 10. otherwise ................................................ ok
 *
 * «Worsened» is judged on the objective's own headline figure, in its own direction: a cost (CPA, CPL,
 * CPC, CPM, CPI) worsens when it RISES; a return or a rate (ROAS, engagement, completion) worsens when it FALLS.
 *
 * ## The rule per project
 *
 * `bad` when any cell is bad or a project-level critical finding exists; else `watch` when any cell is
 * watch or a project-level warning exists; else `none` when no cell has figures at all; else `ok`.
 *
 * ## Global priority — what needs the Owner NOW, across every project
 *
 * Issues sort as the in-app board sorts: priority P1 → P3 → severity (critical first) → newest, then (reason weight, {@see WEIGHT}) → (money at stake, descending)
 * → project name → canonical platform order. One list for the whole portfolio, never a list per project.
 */
final class DigestStatus
{
    public const PACE_HOT = 1.4;

    public const PACE_SLOW = 0.6;

    public const WORSE_BAD = 0.40;

    public const WORSE_WATCH = 0.20;

    public const FALL_WATCH = 0.30;

    /** Lower is more urgent. Inside one state, a stopped sync outranks a slow budget. */
    public const WEIGHT = [
        'sync_failed' => 0, 'no_results' => 1, 'pace_hot' => 2, 'worse' => 3,
        'alert_bad' => 3.5, 'project_critical' => 4,
        'stale' => 5, 'pace_slow' => 6, 'results_down' => 7, 'alert_watch' => 6.5, 'project_warning' => 8,
        'no_data' => 9,
    ];

    /** The chip: glyph, ink and a strong background (heatmap cells) and a soft one (cards). Explicit colours, so a dark-mode client cannot invert them into black-on-black. */
    public const GLYPH = ['ok' => '✓', 'watch' => '!', 'bad' => '✕', 'none' => '–'];

    public const INK = ['ok' => '#0a6b57', 'watch' => '#8a4a07', 'bad' => '#a31616', 'none' => '#5b6b68'];

    public const CELL = ['ok' => '#cfeee3', 'watch' => '#fbe2ae', 'bad' => '#f5bcbc', 'none' => '#e6e2d6'];

    public const SOFT = ['ok' => '#e8f5f1', 'watch' => '#fffbeb', 'bad' => '#fef2f2', 'none' => '#faf8f3'];

    /** @var array<string,int> */
    public const STATE_RANK = ['bad' => 0, 'watch' => 1, 'ok' => 2, 'none' => 3];

    /** P1 before P2 before P3; an event with no priority is read as P3 (thin evidence), as the board reads it. */
    public const PRIORITY_RANK = ['P1' => 0, 'P2' => 1, 'P3' => 2];

    /**
     * The board's cell rule over the open alert events that touch a cell: an open (not snoozed) P1/P2 → `bad`; any other
     * open or snoozed event (P3, or snoozed) → `watch`; none → null.
     *
     * @param  list<array{priority?: ?string, snoozed?: bool}>  $alerts
     */
    public static function alertState(array $alerts): ?string
    {
        $out = null;
        foreach ($alerts as $a) {
            if (! ($a['snoozed'] ?? false) && in_array($a['priority'] ?? 'P3', ['P1', 'P2'], true)) {
                return 'bad';
            }
            $out = 'watch';
        }

        return $out;
    }

    public static function label(string $state, bool $ar): string
    {
        return match ($state) {
            'ok' => $ar ? 'سليم' : 'Healthy',
            'watch' => $ar ? 'مراقبة' : 'Watch',
            'bad' => $ar ? 'مشكلة' : 'Problem',
            default => $ar ? 'لا بيانات' : 'No data',
        };
    }

    /**
     * @param  array{alert?: ?string, spend?: mixed, spend_withheld?: bool, results?: mixed, results_reported?: bool, results_key?: string, results_prev?: mixed,
     *     headline_now?: mixed, headline_prev?: mixed, headline_lower_is_better?: bool, pace?: mixed, source?: ?string}  $in
     * @return array{state: string, reason: ?string, severity: float}
     */
    public static function cell(array $in): array
    {
        $source = $in['source'] ?? null;
        $spend = is_numeric($in['spend'] ?? null) && ! ($in['spend_withheld'] ?? false) ? (float) $in['spend'] : null;
        $pace = is_numeric($in['pace'] ?? null) ? (float) $in['pace'] : null;
        $worse = self::worsening($in);
        $results = is_numeric($in['results'] ?? null) ? (float) $in['results'] : null;
        $resultsPrev = is_numeric($in['results_prev'] ?? null) ? (float) $in['results_prev'] : null;

        if ($source === 'failed') {
            return ['state' => 'bad', 'reason' => 'sync_failed', 'severity' => 1.0];
        }
        if ($spend !== null && $spend > 0 && ($in['results_reported'] ?? false) === true && $results !== null && $results == 0.0 && isset($in['results_key'])) {
            return ['state' => 'bad', 'reason' => 'no_results', 'severity' => 1.0];
        }
        if ($pace !== null && $pace > self::PACE_HOT) {
            return ['state' => 'bad', 'reason' => 'pace_hot', 'severity' => $pace];
        }
        if ($worse !== null && $worse >= self::WORSE_BAD) {
            return ['state' => 'bad', 'reason' => 'worse', 'severity' => $worse];
        }
        if ($source === 'stale') {
            return ['state' => 'watch', 'reason' => 'stale', 'severity' => 1.0];
        }
        if ($pace !== null && $pace < self::PACE_SLOW) {
            return ['state' => 'watch', 'reason' => 'pace_slow', 'severity' => 1 - $pace];
        }
        if ($worse !== null && $worse >= self::WORSE_WATCH) {
            return ['state' => 'watch', 'reason' => 'worse', 'severity' => $worse];
        }
        if ($results !== null && $resultsPrev !== null && $resultsPrev > 0 && ($results - $resultsPrev) / $resultsPrev <= -self::FALL_WATCH) {
            return ['state' => 'watch', 'reason' => 'results_down', 'severity' => ($resultsPrev - $results) / $resultsPrev];
        }
        // The in-app board's rule (AttentionBoard): an open P1/P2 alert on the cell → problem — before anything else the metrics say.
        if (($in['alert'] ?? null) === 'bad') {
            return ['state' => 'bad', 'reason' => 'alert_bad', 'severity' => 1.0];
        }
        if ($spend === null) {
            return ['state' => 'none', 'reason' => 'no_data', 'severity' => 0.0];
        }
        // …and, as on the board, «no data» outranks an open P3 or snoozed alert (watch), which outranks healthy.
        if (($in['alert'] ?? null) === 'watch') {
            return ['state' => 'watch', 'reason' => 'alert_watch', 'severity' => 0.5];
        }

        return ['state' => 'ok', 'reason' => null, 'severity' => 0.0];
    }

    /**
     * @param  list<string>  $cellStates
     * @param  list<string>  $projectFindings  `critical` / `warning` of the project-level findings
     */
    public static function project(array $cellStates, array $projectFindings = []): string
    {
        if (in_array('bad', $cellStates, true) || in_array('critical', $projectFindings, true)) {
            return 'bad';
        }
        if (in_array('watch', $cellStates, true) || in_array('warning', $projectFindings, true)) {
            return 'watch';
        }

        return array_filter($cellStates, static fn (string $s): bool => $s !== 'none') === [] ? 'none' : 'ok';
    }

    /**
     * The global order of issues. `$a`/`$b` carry state, reason, at_stake, project, order.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public static function compare(array $a, array $b): int
    {
        // The board's order: priority P1 → P3, then severity (critical first), then newest — then ours (reason, money, name).
        return (self::STATE_RANK[$a['state']] <=> self::STATE_RANK[$b['state']])
            ?: ((self::PRIORITY_RANK[$a['priority'] ?? 'P3'] ?? 2) <=> (self::PRIORITY_RANK[$b['priority'] ?? 'P3'] ?? 2))
            ?: ((int) ($a['sev'] ?? 1) <=> (int) ($b['sev'] ?? 1))
            ?: ((int) ($b['fired'] ?? 0) <=> (int) ($a['fired'] ?? 0))
            ?: ((self::WEIGHT[$a['reason']] ?? 99) <=> (self::WEIGHT[$b['reason']] ?? 99))
            ?: ((float) $b['at_stake'] <=> (float) $a['at_stake'])
            ?: strcmp((string) $a['project'], (string) $b['project'])
            ?: ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0));
    }

    /** How much worse the headline figure is than the previous window, as a positive ratio; null when it cannot be judged. */
    private static function worsening(array $in): ?float
    {
        $now = $in['headline_now'] ?? null;
        $was = $in['headline_prev'] ?? null;
        if (! is_numeric($now) || ! is_numeric($was) || (float) $was == 0.0) {
            return null;
        }
        $d = ((float) $now - (float) $was) / abs((float) $was);

        return ($in['headline_lower_is_better'] ?? false) ? $d : -$d;
    }
}
