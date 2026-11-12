<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Services\MetricsAggregator;
use Illuminate\Support\Carbon;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — did the action work? Read-only, from the metrics the product already reports.
 *
 * When an alert is marked «done», `baseline()` freezes the alert's OWN metric over the N complete days before
 * that day. `measure()` later compares it with the N complete days after. Until those N days have completed
 * the answer is `insufficient_data` — never a partial figure, never a guess. A window with withheld money, an
 * incomplete provider read, or no value (a cost per result with no results) is `insufficient_data` too.
 * An alert whose metric is not a campaign figure (a token, a sync) is `not_measurable`, and says so.
 */
final class AlertImpact
{
    private const METRIC = ['cpa_increase' => 'cpa', 'cpl_increase' => 'cpl', 'roas_drop' => 'roas', 'no_results' => 'result'];

    /** Lower is better for these; everything else, higher is better. */
    private const LOWER_BETTER = ['cpa', 'cpl'];

    public function __construct(private readonly MetricsAggregator $metrics) {}

    /** @return array<string,mixed> */
    public function baseline(AlertEvent $event, ?string $tag, Carbon $now): array
    {
        $ctx = (array) $event->context;
        $metric = self::METRIC[$event->type] ?? ($event->type === 'metric_anomaly' ? ($ctx['points'][0]['metric'] ?? null) : null);
        $days = max(3, min(14, (int) ($ctx['window_days'] ?? $ctx['days'] ?? 7)));
        $base = ['action_tag' => $tag, 'recorded_at' => $now->toIso8601String(), 'done_on' => $now->toDateString(), 'window_days' => $days, 'metric' => $metric];

        $campaign = $event->entity_type === UnifiedCampaign::class && $event->entity_id !== null ? UnifiedCampaign::query()->find($event->entity_id) : null;
        if ($metric === null || $campaign === null) {
            return $base + ['measurable' => false, 'reason' => 'not_a_campaign_metric'];
        }

        $value = $this->figure((string) $campaign->id, $metric, $now->copy()->subDays($days)->startOfDay(), $now->copy()->subDay()->endOfDay());

        return $base + ['measurable' => true, 'campaign_id' => (string) $campaign->id, 'value_at_done' => $value['value'], 'before_complete' => $value['complete']];
    }

    /** @return array<string,mixed> */
    public function measure(AlertEvent $event, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $b = $event->impact_baseline;
        if ($event->decision_status !== 'done' || ! is_array($b)) {
            return ['state' => 'none'];
        }
        if (($b['measurable'] ?? false) !== true) {
            return ['state' => 'not_measurable', 'reason' => $b['reason'] ?? 'not_a_campaign_metric', 'action_tag' => $b['action_tag'] ?? null];
        }

        $days = (int) $b['window_days'];
        $doneOn = Carbon::parse((string) $b['done_on'])->startOfDay();
        $afterFrom = $doneOn->copy()->addDay();
        $afterTo = $doneOn->copy()->addDays($days)->endOfDay();
        $out = ['metric' => $b['metric'], 'window_days' => $days, 'action_tag' => $b['action_tag'] ?? null, 'before' => $b['value_at_done'] ?? null,
            'after_window' => [$afterFrom->toDateString(), $afterTo->toDateString()]];

        // The window is complete only once its last day is over.
        if ($now->lessThanOrEqualTo($afterTo)) {
            return $out + ['state' => 'insufficient_data', 'reason' => 'window_incomplete', 'days_remaining' => max(1, (int) ceil($now->diffInHours($afterTo, false) / 24))];
        }
        if (($b['value_at_done'] ?? null) === null || ($b['before_complete'] ?? false) !== true) {
            return $out + ['state' => 'insufficient_data', 'reason' => 'before_window_incomplete'];
        }

        $after = $this->figure((string) $b['campaign_id'], (string) $b['metric'], $afterFrom, $afterTo);
        if (! $after['complete'] || $after['value'] === null) {
            return $out + ['state' => 'insufficient_data', 'reason' => $after['complete'] ? 'no_value_in_window' : 'incomplete_data'];
        }

        $before = (float) $b['value_at_done'];
        $change = $before > 0 ? ($after['value'] - $before) / $before * 100 : null;
        $better = in_array((string) $b['metric'], self::LOWER_BETTER, true) ? $after['value'] < $before : $after['value'] > $before;
        $verdict = $change === null ? 'unclear' : (abs($change) < 2 ? 'flat' : ($better ? 'improved' : 'worsened'));

        return $out + ['state' => 'computed', 'after' => $after['value'], 'change_pct' => $change === null ? null : round($change, 1), 'verdict' => $verdict];
    }

    /** @return array{value: ?float, complete: bool} */
    private function figure(string $campaignId, string $metric, Carbon $from, Carbon $to): array
    {
        $m = $this->metrics->acrossProjects()->forCampaigns([$campaignId]);
        $complete = ($m->coverageOfWindow($from, $to)['state'] ?? 'complete') === 'complete';
        $row = $m->figuresByCampaign($from, $to)[$campaignId] ?? [];
        $v = $row[$metric] ?? null;
        if ((int) ($row['spend_withheld_rows'] ?? 0) > 0) {
            $complete = false;
        }

        return ['value' => is_numeric($v) ? (float) $v : null, 'complete' => $complete];
    }
}
