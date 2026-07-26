<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Evaluates a project's active alert rules against the SAME metrics aggregation the reports use, and
 * raises a notification when a rule's condition holds. Enforces:
 *  - cooldown: no re-alert within the rule's window (dedup by rule + open notification)
 *  - quiet hours: suppresses raising during the configured window
 *  - project/tenant isolation: only the active project's metrics are read
 *  - demo safety: a demo rule only ever creates a demo notification
 *  - auto-resolve: an open alert whose condition has cleared is resolved
 */
final class AlertRuleEvaluator
{
    public function __construct(private readonly MetricsAggregator $agg) {}

    /**
     * Evaluate every active rule for a project. Returns the list of notifications raised.
     *
     * @return array{raised:int, resolved:int, skipped:int}
     */
    public function evaluateProject(string $tenantId, string $projectId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        app(TenantContext::class)->setTenantId($tenantId);
        app(ProjectContext::class)->setProjectId($projectId);

        $raised = 0;
        $resolved = 0;
        $skipped = 0;

        $rules = AlertRule::query()->where('project_id', $projectId)->where('is_active', true)->get();
        foreach ($rules as $rule) {
            $eval = $this->condition($rule, $now);
            $open = $this->openNotification($rule);

            if ($eval['triggered']) {
                if ($open !== null) {           // already alerting → dedup
                    $skipped++;

                    continue;
                }
                if ($this->inCooldown($rule, $now) || $this->inQuietHours($rule, $now)) {
                    $skipped++;

                    continue;
                }
                $this->raise($rule, $eval['message'], $eval['value'], $now);
                $raised++;
            } elseif ($open !== null) {          // condition cleared → auto-resolve
                $open->update(['status' => 'resolved', 'resolved_at' => $now]);
                $resolved++;
            }
        }

        app(ProjectContext::class)->forget();

        return ['raised' => $raised, 'resolved' => $resolved, 'skipped' => $skipped];
    }

    /**
     * @return array{triggered:bool, message:string, value:float}
     */
    private function condition(AlertRule $rule, Carbon $now): array
    {
        $to = $now->copy();
        $from = $to->copy()->subDays(max(1, $rule->evaluation_window) - 1);
        $totals = $this->agg->totals($from, $to);
        $t = (float) ($rule->threshold ?? 0);

        return match ($rule->rule_type) {
            'spend_no_conversions' => [
                'triggered' => (float) ($totals['spend'] ?? 0) > max(1, $t) && (float) ($totals['conversions'] ?? 0) < 1,
                'message' => 'إنفاق دون تحويلات خلال الفترة — يُنصح بمراجعة التتبع والاستهداف.',
                'value' => (float) ($totals['spend'] ?? 0),
            ],
            'no_spend' => [
                'triggered' => (float) ($totals['spend'] ?? 0) <= 0,
                'message' => 'لا يوجد إنفاق مسجّل خلال الفترة.',
                'value' => 0.0,
            ],
            'no_results' => [
                'triggered' => (float) ($totals['conversions'] ?? 0) <= 0 && (float) ($totals['spend'] ?? 0) > 0,
                'message' => 'إنفاق دون أي نتائج خلال الفترة.',
                'value' => (float) ($totals['conversions'] ?? 0),
            ],
            'cpa_increased' => [
                'triggered' => $totals['cpa'] !== null && (float) $totals['cpa'] > $t && $t > 0,
                'message' => sprintf('ارتفاع تكلفة النتيجة (CPA %s) فوق الحد %s.', number_format((float) ($totals['cpa'] ?? 0)), number_format($t)),
                'value' => (float) ($totals['cpa'] ?? 0),
            ],
            'roas_decreased' => [
                'triggered' => $totals['roas'] !== null && (float) $totals['roas'] < $t && $t > 0,
                'message' => sprintf('انخفاض العائد على الإنفاق (ROAS %s×) دون الحد %s×.', number_format((float) ($totals['roas'] ?? 0), 2), number_format($t, 2)),
                'value' => (float) ($totals['roas'] ?? 0),
            ],
            default => $this->genericMetric($rule, $totals, $t),
        };
    }

    /**
     * @param  array<string,float|null>  $totals
     * @return array{triggered:bool, message:string, value:float}
     */
    private function genericMetric(AlertRule $rule, array $totals, float $threshold): array
    {
        $value = (float) ($totals[$rule->metric] ?? 0);
        $triggered = match ($rule->operator) {
            'gt' => $value > $threshold,
            'gte' => $value >= $threshold,
            'lt' => $value < $threshold,
            'lte' => $value <= $threshold,
            'eq' => abs($value - $threshold) < 0.0001,
            default => false,
        };

        return ['triggered' => $triggered, 'message' => "تنبيه: {$rule->name}.", 'value' => $value];
    }

    private function openNotification(AlertRule $rule): ?AppNotification
    {
        return AppNotification::query()
            ->where('entity_type', 'alert_rule')->where('entity_id', $rule->id)
            ->whereIn('status', ['unread', 'read'])
            ->whereNull('resolved_at')
            ->first();
    }

    private function inCooldown(AlertRule $rule, Carbon $now): bool
    {
        return $rule->last_triggered_at !== null
            && $rule->last_triggered_at->copy()->addSeconds($rule->cooldown)->greaterThan($now);
    }

    private function inQuietHours(AlertRule $rule, Carbon $now): bool
    {
        $q = $rule->quiet_hours;
        if (! is_array($q) || ($q['enabled'] ?? false) !== true) {
            return false;
        }
        $start = (string) ($q['start'] ?? '22:00');
        $end = (string) ($q['end'] ?? '08:00');
        $cur = $now->format('H:i');

        // Window may wrap past midnight.
        return $start <= $end ? ($cur >= $start && $cur < $end) : ($cur >= $start || $cur < $end);
    }

    private function raise(AlertRule $rule, string $message, float $value, Carbon $now): void
    {
        AppNotification::create([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $rule->project_id,
            'type' => $rule->rule_type,
            'severity' => $rule->severity,
            'title' => $rule->name,
            'message' => $message,
            'source' => 'alert',
            'entity_type' => 'alert_rule',
            'entity_id' => $rule->id,
            'status' => 'unread',
            'is_demo' => (bool) $rule->is_demo, // a demo rule only ever raises a demo notification
        ]);

        $rule->forceFill(['last_triggered_at' => $now])->save();
    }
}
