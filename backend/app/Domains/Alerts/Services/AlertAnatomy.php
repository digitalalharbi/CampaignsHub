<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Notifications\Support\AlertEventText;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — the anatomy of an alert: problem, evidence, impact, action, priority.
 *
 * ## The evidence rule
 *
 * Every alert carries an `evidence` payload — metric, window, before, now, threshold, completeness — and a
 * `sufficient` verdict. An alert whose evidence is thin is NOT dressed up: it gets no recommended action,
 * the lowest priority (P3), and is marked «بيانات غير كافية». Nothing here recomputes a figure; every number
 * is one the rule wrote into the event's context, and a number that is absent stays absent (null, never 0).
 *
 * ## The priority rule (deterministic; same inputs, same answer)
 *
 *  - evidence not sufficient           → P3
 *  - severity `critical`               → P1
 *  - deviation ≥ 2× the rule threshold → P1   (a 25% rule that fired at 50% is not routine)
 *  - severity `warning`                → P2
 *  - otherwise (`info`)                → P3
 */
final class AlertAnatomy
{
    /** Types whose evidence is a comparison of one metric across two windows. */
    private const COMPARED = ['cpa_increase' => 'cpa', 'cpl_increase' => 'cpl', 'roas_drop' => 'roas'];

    private const CONNECTION = ['sync_failure', 'sync_outage', 'measurement_outage', 'token_expiry'];

    /**
     * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — linking and measurement health, never a performance decision: these go to the
     * «ملخص الربط» section of a digest, not to «يحتاج قرارك الآن».
     */
    public const LINK_HEALTH = [...self::CONNECTION, 'tracking_health', 'ga4_tracking_loss'];

    /**
     * @param  array<string,mixed>  $context
     * @return array{priority: string, evidence: array<string,mixed>, anatomy: array<string,mixed>}
     */
    public static function build(string $type, array $context, string $severity): array
    {
        $evidence = self::evidence($type, $context);
        $priority = self::priority($evidence, $severity);

        $say = ['ar' => AlertEventText::plain($type, $context, true), 'en' => AlertEventText::plain($type, $context, false)];
        $known = isset(self::COMPARED[$type]) || in_array($type, [...self::CONNECTION, 'no_results', 'metric_anomaly', 'budget_risk'], true);
        $sufficient = $evidence['sufficient'] === true && $known;

        $note = ['ar' => 'بيانات غير كافية — لا نوصي بإجراء قبل اكتمال الدليل.', 'en' => 'Insufficient data — no action is recommended until the evidence is complete.'];

        return [
            'priority' => $priority,
            'evidence' => $evidence,
            'anatomy' => [
                'problem' => ['ar' => $say['ar']['headline'], 'en' => $say['en']['headline']],
                'impact' => $sufficient ? ['ar' => $say['ar']['text'], 'en' => $say['en']['text']] : null,
                'numbers' => ['ar' => $say['ar']['numbers'], 'en' => $say['en']['numbers']],
                'action' => $sufficient ? ['ar' => $say['ar']['action'], 'en' => $say['en']['action']] : null,
                'insufficient_note' => $sufficient ? null : $note,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $c
     * @return array<string,mixed>
     */
    public static function evidence(string $type, array $c): array
    {
        $num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
        $e = ['metric' => null, 'window_days' => null, 'before' => null, 'now' => null, 'threshold' => null, 'threshold_unit' => null, 'completeness' => 'unverified', 'missing' => []];

        if (isset(self::COMPARED[$type])) {
            $key = self::COMPARED[$type];
            $e['metric'] = $key;
            $e['before'] = $num($c[$key.'_previous'] ?? null);
            $e['now'] = $num($c[$key.'_current'] ?? null);
            $e['window_days'] = isset($c['window_days']) ? (int) $c['window_days'] : null;
            $e['threshold'] = $num($c['threshold_pct'] ?? null);
            $e['threshold_unit'] = '%';
            // cost-per rules withdraw a verdict when either window is incomplete; ROAS has no such guard.
            $e['completeness'] = $key === 'roas' ? 'unverified' : 'complete';
        } elseif ($type === 'no_results') {
            // ALERT-OBJECTIVE-RESULT-001 — the evidence names the result it measured; `conversions` only for an older event.
            $e['metric'] = isset($c['result_key']) ? (string) $c['result_key'] : 'conversions';
            $e['now'] = $num($c['conversions'] ?? null);
            $e['window_days'] = isset($c['days']) ? (int) $c['days'] : null;
            $e['threshold'] = 0.0;
            $e['threshold_unit'] = 'results';
            $e['spend'] = $num($c['spend'] ?? null);
            $e['missing'] = $num($c['spend'] ?? null) === null ? ['spend'] : [];
        } elseif ($type === 'metric_anomaly') {
            $p = (array) (($c['points'] ?? [])[0] ?? []);
            $e['metric'] = isset($p['metric']) ? (string) $p['metric'] : null;
            $e['before'] = $num($p['baseline'] ?? null);
            $e['now'] = $num($p['value'] ?? null);
            $e['window_days'] = isset($c['window_days']) ? (int) $c['window_days'] : null;
            $e['threshold_unit'] = 'baseline';
        } elseif ($type === 'budget_risk') {
            $e['metric'] = 'budget_used';
            $e['now'] = $num($c['consumed'] ?? $c['spend'] ?? null);
            $e['before'] = null;
            $e['threshold'] = $num($c['threshold'] ?? null);
            $e['limit'] = $num($c['limit'] ?? $c['budget'] ?? null);
        } elseif (in_array($type, self::CONNECTION, true)) {
            $e['metric'] = 'connection';
            $e['now'] = null;
            $e['last_sync_at'] = $c['last_sync_at'] ?? null;
            $e['expires_at'] = $c['expires_at'] ?? null;
            $e['provider'] = $c['provider'] ?? null;
        }

        $e['sufficient'] = self::sufficient($type, $e, $c);
        if (! $e['sufficient'] && $e['missing'] === []) {
            $e['missing'] = self::missingOf($type, $e, $c);
        }

        return $e;
    }

    /**
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $c
     */
    private static function sufficient(string $type, array $e, array $c): bool
    {
        if ($e['completeness'] === 'partial') {
            return false;
        }

        return match (true) {
            isset(self::COMPARED[$type]) => $e['before'] !== null && $e['now'] !== null && $e['before'] > 0 && ($e['window_days'] ?? 0) > 0 && $e['threshold'] !== null,
            $type === 'no_results' => ($e['spend'] ?? 0) > 0 && ($e['window_days'] ?? 0) > 0 && $e['now'] !== null,
            $type === 'metric_anomaly' => $e['metric'] !== null && $e['before'] !== null && $e['now'] !== null,
            $type === 'budget_risk' => $e['now'] !== null && ($e['limit'] ?? null) !== null && $e['limit'] > 0,
            in_array($type, self::CONNECTION, true) => ($c['provider'] ?? '') !== '' || ($c['last_sync_at'] ?? null) !== null || ($c['expires_at'] ?? null) !== null || ($c['error'] ?? null) !== null,
            default => false,
        };
    }

    /**
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $c
     * @return list<string>
     */
    private static function missingOf(string $type, array $e, array $c): array
    {
        $missing = [];
        foreach (['before', 'now', 'window_days', 'threshold'] as $k) {
            if (isset(self::COMPARED[$type]) && $e[$k] === null) {
                $missing[] = $k;
            }
        }

        return $missing === [] ? ['evidence'] : $missing;
    }

    /** @param array<string,mixed> $evidence */
    public static function priority(array $evidence, string $severity): string
    {
        if ($evidence['sufficient'] !== true) {
            return 'P3';
        }
        if ($severity === 'critical') {
            return 'P1';
        }

        $before = $evidence['before'];
        $now = $evidence['now'];
        $threshold = $evidence['threshold'];
        if ($evidence['threshold_unit'] === '%' && $before !== null && $before > 0 && $now !== null && $threshold !== null && $threshold > 0
            && abs($now - $before) / $before * 100 >= 2 * $threshold) {
            return 'P1';
        }

        return $severity === 'warning' ? 'P2' : 'P3';
    }
}
