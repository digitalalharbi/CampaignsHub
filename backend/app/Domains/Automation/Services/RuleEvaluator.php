<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Services\ProposalService;
use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Models\AutomationRuleAction;
use App\Domains\Automation\Models\AutomationRuleRun;
use App\Domains\Automation\Support\RuleCatalog;
use App\Domains\Automation\Support\RuleReadings;
use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use App\Domains\Funding\Support\FundingMoney;
use App\Domains\Metrics\Services\DisplayCurrencyAdapter;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * AUTO-RULES-001 — one evaluation of one rule.
 *
 * Reads the rule's objects and their figures for each condition's window (the rule's own timezone), joins
 * the conditions with AND or OR, and for each match takes the rule's action IN ITS MODE:
 *
 *  - monitor: records the match and notifies the rule's author; nothing is asked of a platform;
 *  - recommend: files a proposal in the approval gateway that a person must decide — even where the
 *    trust ladder would let an agent's proposal run on its own;
 *  - auto: files the proposal and lets the gateway carry it out ONLY where the Owner granted that action
 *    class on the trust ladder and the project's record still qualifies; anything else waits for a person.
 *
 * Safeguards: an object acted on by this rule inside its cooldown is skipped; an object with an open
 * proposal for a different action, or an opposite budget move by any rule inside the cooldown, is
 * skipped as a conflict; a failed write is not retried until the cooldown passes; a run acts on at most
 * MAX_ACTIONS objects. A dry run evaluates and reports and changes nothing.
 */
final class RuleEvaluator
{
    public const MAX_ACTIONS = 50;

    public function __construct(
        private readonly RuleTargets $targets,
        private readonly RuleMetrics $metrics,
        private readonly ProposalService $proposals,
        private readonly NotificationDispatcher $notifications,
        private readonly DisplayCurrencyAdapter $display,
    ) {}

    /** @return array{run: AutomationRuleRun, results: list<array<string, mixed>>} */
    public function evaluate(AutomationRule $rule, string $trigger = 'schedule', bool $dryRun = false): array
    {
        $run = AutomationRuleRun::withoutGlobalScopes()->create([
            'tenant_id' => $rule->tenant_id, 'rule_id' => $rule->getKey(), 'dry_run' => $dryRun, 'trigger' => $dryRun ? 'dry_run' : $trigger,
            'status' => 'running', 'started_at' => Carbon::now(),
        ]);
        $results = [];

        try {
            $tz = (string) (($rule->schedule['timezone'] ?? null) ?: 'Asia/Riyadh');
            $today = Carbon::now($tz)->startOfDay();
            $targets = $this->targets->for($rule);
            $figures = $this->figures($rule, $targets, $today);
            $acted = 0;

            foreach ($targets as $t) {
                [$matched, $read, $why, $readings] = $this->judge($rule, $t, $figures, $today);
                // CONS-I-DRYRUN — what a person reads: the indicator's Arabic name, the value in display currency, the exact reason.
                $row = ['target' => $t, 'matched' => $matched, 'metrics' => $read, 'note' => $why, 'readings' => $readings, 'reason' => $this->reason($rule, $why, $readings, $figures, $t)];

                if (! $matched) {
                    $results[] = $row + ['status' => match ($why) {
                        null => 'not_matched',
                        'no_figure' => 'skipped_no_figure',
                        default => 'skipped_insufficient_data',
                    }];

                    continue;
                }
                if ($dryRun) {
                    $results[] = $row + ['status' => 'would_'.$rule->action['type']] + $this->plan($rule, $t);

                    continue;
                }
                if ($acted >= self::MAX_ACTIONS) {
                    $results[] = $row + $this->record($rule, $run, $t, $read, 'skipped_run_cap', 'This run reached its limit of '.self::MAX_ACTIONS.' actions.');

                    continue;
                }

                $outcome = $this->act($rule, $run, $t, $read);
                if (in_array($outcome['status'], AutomationRuleAction::ACTED, true)) {
                    $acted++;
                }
                $results[] = $row + $outcome;
            }

            $count = static fn (callable $f): int => count(array_filter($results, $f));
            $run->forceFill([
                'evaluated' => count($targets),
                'matched' => $count(static fn ($r) => $r['matched']),
                'acted' => $count(static fn ($r) => in_array($r['status'], AutomationRuleAction::ACTED, true)),
                'skipped' => $count(static fn ($r) => str_starts_with((string) $r['status'], 'skipped') || str_starts_with((string) $r['status'], 'unsupported') || $r['status'] === 'awaiting_credentials'),
                'failed' => $count(static fn ($r) => $r['status'] === 'failed'),
                'status' => 'done', 'finished_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $run->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => Carbon::now()])->save();
        }

        if (! $dryRun) {
            $rule->forceFill([
                'last_run_at' => Carbon::now(),
                'last_result' => $run->status === 'failed' ? 'failed' : ($run->matched === 0 ? 'no_change_required' : ($run->acted > 0 ? 'acted' : 'matched_not_acted')),
            ])->save();
        }

        return ['run' => $run->fresh() ?? $run, 'results' => $results];
    }

    /**
     * Figures per window used by the conditions, plus the previous window when a trend is asked, plus today.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return array<string, array<string, array<string, float|null>>> key => id => figures
     */
    private function figures(AutomationRule $rule, array $targets, Carbon $today): array
    {
        $ids = array_column($targets, 'id');
        $windows = [1];
        $trend = false;
        foreach ((array) $rule->conditions as $c) {
            $windows[] = (int) ($c['window_days'] ?? 7);
            $trend = $trend || str_ends_with((string) ($c['metric'] ?? ''), '_change_pct');
        }
        $out = [];
        foreach (array_unique($windows) as $w) {
            $from = $today->copy()->subDays($w - 1);
            $out["cur:{$w}"] = $this->metrics->window((string) $rule->project_id, (string) $rule->level, $ids, $from, $today);
            if ($trend) {
                $out["prev:{$w}"] = $this->metrics->window((string) $rule->project_id, (string) $rule->level, $ids, $from->copy()->subDays($w), $from->copy()->subDay());
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array{0: bool, 1: array<string, mixed>, 2: ?string, 3: list<array<string, mixed>>} matched, figures read, why not judged, readings
     */
    private function judge(AutomationRule $rule, array $t, array $figures, Carbon $today): array
    {
        $read = [];
        $readings = [];
        $results = [];
        $maxWindow = 1;

        foreach ((array) $rule->conditions as $c) {
            $metric = (string) $c['metric'];
            $w = (int) ($c['window_days'] ?? 7);
            $maxWindow = max($maxWindow, $w);
            $value = $this->value($rule, $metric, $w, $t, $figures, $today);
            $read["{$metric}@{$w}d"] = $value;
            $readings[] = $this->reading($rule, $metric, $w, $value, $t, $figures);
            $results[] = $value === null ? null : $this->compare($value, (string) $c['operator'], $c['value']);
        }

        // Data sufficiency: below the rule's minimum spend nothing is judged — too little to act on.
        $spend = $figures["cur:{$maxWindow}"][$t['id']]['spend'] ?? null;
        if ($rule->min_spend !== null && ($spend === null || $spend < (float) $rule->min_spend)) {
            return [false, $read, 'below_min_spend', $readings];
        }

        $matched = $rule->logic === 'any'
            ? in_array(true, $results, true)
            : $results !== [] && ! in_array(false, $results, true) && ! in_array(null, $results, true);

        /*
         * A figure nobody measured is not a miss. When no condition was judged false and at least one had
         * no figure — nothing was reported for this object in the window (missing, delayed or inaccessible
         * data, never a claim that the platform cannot supply the metric) — the object was not judged, and the history says so instead of «not matched».
         */
        if (! $matched && in_array(null, $results, true) && ! in_array(false, $results, true)) {
            return [false, $read, 'no_figure', $readings];
        }

        return [$matched, $read, null, $readings];
    }

    /** @param array<string, mixed> $t */
    private function value(AutomationRule $rule, string $metric, int $w, array $t, array $figures, Carbon $today): float|string|null
    {
        $cur = $figures["cur:{$w}"][$t['id']] ?? [];

        return match ($metric) {
            'delivery_status' => strtolower((string) ($t['status'] ?? '')) ?: null,
            'age_days' => $t['started_at'] !== null ? (float) Carbon::parse($t['started_at'])->startOfDay()->diffInDays($today->copy()->startOfDay()) : null,
            'budget_utilization' => $this->budgetUtilization($rule, $t, $figures),
            'spend_change_pct', 'cpa_change_pct', 'roas_change_pct' => $this->change(substr($metric, 0, -11), $cur, $figures["prev:{$w}"][$t['id']] ?? []),
            default => $cur[$metric] ?? null,
        };
    }

    /**
     * CONS-I-BUDGET — today's spend over the object's daily budget, both in the DISPLAY currency.
     *
     * Spend is the converted column (the reporting currency); the budget is in the account's own currency, so it is
     * converted through the one adapter before the division — a SAR budget against a USD spend is the 3.75 trap.
     * No budget from the platform, no spend today, or no rate: null, and {@see unavailable()} says which.
     *
     * @param  array<string, mixed>  $t
     */
    private function budgetUtilization(AutomationRule $rule, array $t, array $figures): ?float
    {
        $budget = (float) ($t['daily_budget'] ?? 0);
        $spend = $figures['cur:1'][$t['id']]['spend'] ?? null;
        if ($budget <= 0 || $spend === null) {
            return null;
        }
        $converted = $this->display->toDisplay((string) $rule->tenant_id, (string) $rule->project_id, $budget, $t['currency'] ?? null);

        return $converted['amount'] !== null && $converted['amount'] > 0 ? $spend / $converted['amount'] * 100 : null;
    }

    /**
     * Why a metric has no value for this object — one code per cause, so the history can say it in words.
     *
     * @param  array<string, mixed>  $t
     */
    private function unavailable(AutomationRule $rule, string $metric, array $t, array $figures, int $w): string
    {
        return match ($metric) {
            'age_days' => 'no_creation_date',
            'delivery_status' => 'no_status',
            'budget_utilization' => match (true) {
                (float) ($t['daily_budget'] ?? 0) <= 0 => 'no_daily_budget',
                ($figures['cur:1'][$t['id']]['spend'] ?? null) === null => 'no_spend_today',
                default => 'no_rate',
            },
            'spend_change_pct', 'cpa_change_pct', 'roas_change_pct' => 'no_comparison',
            default => 'not_reported',
        };
    }

    /**
     * One reading, as a person meets it: the Arabic name of the indicator and the window, the value (money already in the
     * display currency), and — when there is none — the exact reason in words instead of «cpa@7d».
     *
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function reading(AutomationRule $rule, string $metric, int $w, float|string|null $value, array $t, array $figures): array
    {
        $def = RuleCatalog::METRICS[$metric] ?? [$metric, $metric, 'count'];
        $kind = $def[2];
        $code = $value === null ? $this->unavailable($rule, $metric, $t, $figures, $w) : null;
        $currency = $kind === 'money' ? $this->display->displayCurrency((string) $rule->project_id) : null;

        return [
            'metric' => $metric, 'window_days' => $w,
            'name_ar' => $def[0].($this->windowless($metric) ? '' : ' (آخر '.RuleReadings::days($w).')'),
            'name_en' => $def[1].($this->windowless($metric) ? '' : " (last {$w} d)"),
            'kind' => $kind, 'value' => is_float($value) ? round($value, 2) : $value, 'currency' => $currency,
            'text_ar' => RuleReadings::valueText($value, $kind, $currency, $code),
            'reason_code' => $code, 'reason_ar' => $code === null ? null : RuleReadings::reasonAr($code, $this->display->displayCurrency((string) $rule->project_id)),
        ];
    }

    private function windowless(string $metric): bool
    {
        return in_array($metric, ['age_days', 'delivery_status', 'budget_utilization'], true);
    }

    /**
     * The one sentence for an object nobody judged: «بيانات غير كافية» and WHY — age, status, spend or missing data.
     *
     * @param  list<array<string, mixed>>  $readings
     * @return array{code: string, ar: string}|null
     */
    private function reason(AutomationRule $rule, ?string $why, array $readings, array $figures, array $t): ?array
    {
        if ($why === 'below_min_spend') {
            $w = 1;
            foreach ((array) $rule->conditions as $c) {
                $w = max($w, (int) ($c['window_days'] ?? 7));
            }
            $spend = $figures["cur:{$w}"][$t['id']]['spend'] ?? null;
            $cur = $this->display->displayCurrency((string) $rule->project_id);
            $has = $spend === null ? 'ما فيه إنفاق مسجّل' : 'الإنفاق '.FundingMoney::withMark(number_format((float) $spend, 2), $cur);

            return ['code' => 'below_min_spend', 'ar' => 'بيانات غير كافية: '.$has.' والحد الأدنى '.FundingMoney::withMark(number_format((float) $rule->min_spend, 2), $cur)];
        }
        if ($why === 'no_figure') {
            foreach ($readings as $r) {
                if ($r['reason_code'] !== null) {
                    return ['code' => (string) $r['reason_code'], 'ar' => 'بيانات غير كافية: '.$r['name_ar'].' — '.$r['reason_ar']];
                }
            }
        }

        return null;
    }

    private function change(string $base, array $cur, array $prev): ?float
    {
        $a = $cur[$base] ?? null;
        $b = $prev[$base] ?? null;

        return $a !== null && $b !== null && $b != 0.0 ? ($a - $b) / $b * 100 : null;
    }

    private function compare(float|string $value, string $op, mixed $target): bool
    {
        if (is_string($value)) {
            return $op === 'eq' && $value === strtolower((string) $target);
        }
        $t = (float) $target;

        return match ($op) {
            'gt' => $value > $t, 'gte' => $value >= $t, 'lt' => $value < $t, 'lte' => $value <= $t,
            'eq' => abs($value - $t) < 1e-9, default => false,
        };
    }

    /**
     * What the action would change: the gateway action and, for a budget, the before and after values.
     *
     * @param  array<string, mixed>  $t
     * @return array{gateway: ?string, before: ?array<string, mixed>, after: ?array<string, mixed>}
     */
    private function plan(AutomationRule $rule, array $t): array
    {
        $type = (string) $rule->action['type'];
        $pct = (float) ($rule->action['percent'] ?? 0);

        return match ($type) {
            'pause' => ['gateway' => 'pause', 'before' => ['status' => $t['status']], 'after' => ['status' => 'paused']],
            'resume' => ['gateway' => 'resume', 'before' => ['status' => $t['status']], 'after' => ['status' => 'active']],
            'budget_increase', 'budget_decrease' => [
                'gateway' => 'set_daily_budget',
                'before' => ['daily_budget' => $t['daily_budget'], 'currency' => $t['currency']],
                'after' => $t['daily_budget'] === null || ($t['budget_source'] ?? 'self') !== 'self' ? null : ['daily_budget' => round($t['daily_budget'] * (1 + ($type === 'budget_increase' ? $pct : -$pct) / 100), 2), 'currency' => $t['currency']],
            ],
            default => ['gateway' => null, 'before' => null, 'after' => null],
        };
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function act(AutomationRule $rule, AutomationRuleRun $run, array $t, array $read): array
    {
        $type = (string) $rule->action['type'];
        $plan = $this->plan($rule, $t);
        $since = Carbon::now()->subHours(max(0, (int) $rule->cooldown_hours));

        $recent = AutomationRuleAction::withoutGlobalScopes()->where('rule_id', $rule->getKey())
            ->where('target_external_id', $t['external_id'])->whereIn('status', AutomationRuleAction::ACTED)->where('created_at', '>=', $since)->exists();
        if ($recent) {
            return $this->record($rule, $run, $t, $read, 'skipped_cooldown', "Acted on inside the last {$rule->cooldown_hours} hours.", $plan);
        }

        if ($type === 'notify' || $rule->mode === 'monitor') {
            $this->notify($rule, $t, $read, $type);

            return $this->record($rule, $run, $t, $read, 'notified', $rule->mode === 'monitor' && $type !== 'notify' ? 'Monitor only: the change was not asked for.' : null, $plan);
        }

        if ($plan['gateway'] === null) {
            return $this->record($rule, $run, $t, $read, 'unsupported_action', 'No platform change of this kind can be asked for through the approval gateway yet.', $plan);
        }
        if ($t['level'] === 'ad') {
            return $this->record($rule, $run, $t, $read, 'unsupported_level', 'Changes are carried out on campaigns and ad groups; an ad can be watched and notified on.', $plan);
        }
        $capability = $plan['gateway'] === 'set_daily_budget' ? 'budget_change' : 'pause_resume';
        $status = collect(WriteCapabilityRegistry::entries())->first(fn (array $e): bool => $e['provider'] === $t['provider'] && $e['capability'] === $capability)['status'] ?? WriteCapabilityRegistry::NOT_IMPLEMENTED;
        if ($status === WriteCapabilityRegistry::AWAITING_CREDENTIALS) {
            return $this->record($rule, $run, $t, $read, 'awaiting_credentials', 'The platform credentials needed for this change are not configured.', $plan);
        }
        if (! in_array($status, [WriteCapabilityRegistry::IMPLEMENTED_NOT_VERIFIED, WriteCapabilityRegistry::VERIFIED], true)) {
            return $this->record($rule, $run, $t, $read, 'unsupported_for_provider', 'This platform does not allow this change through its API here.', $plan);
        }
        if ($plan['gateway'] === 'set_daily_budget' && $plan['after'] === null) {
            return $this->record($rule, $run, $t, $read, 'skipped_no_budget', 'This object has no daily budget at this level.', $plan);
        }
        if ($this->conflicts($rule, $t, $type, $since)) {
            return $this->record($rule, $run, $t, $read, 'skipped_conflict', 'Another rule or an open request moves this object the other way.', $plan);
        }

        $project = Project::withoutGlobalScopes()->findOrFail($rule->project_id);
        $proposal = $this->proposals->propose($project, 'rules', null, [
            'provider' => $t['provider'], 'external_account_id' => (string) $t['external_account_id'],
            'target_type' => $t['level'], 'target_external_id' => $t['external_id'], 'action' => $plan['gateway'],
            'payload' => $plan['gateway'] === 'set_daily_budget' ? ['daily_budget' => $plan['after']['daily_budget']] : [],
            'reason' => mb_substr("Rule «{$rule->name}»: ".$this->describe($read), 0, 1000),
            'evidence' => ['rule_id' => (string) $rule->getKey(), 'run_id' => (string) $run->getKey(), 'figures' => $read],
            'allow_auto' => $rule->mode === 'auto',
        ]);

        $state = match ($proposal->status) {
            ActionProposal::EXECUTED => 'executed',
            ActionProposal::FAILED => 'failed',
            ActionProposal::BLOCKED => 'blocked',
            default => 'proposed',
        };
        $note = match ($state) {
            'proposed' => $rule->mode === 'auto' ? 'Waiting for approval: the Owner has not granted this action class on the trust ladder, or the record no longer qualifies.' : 'Waiting for approval.',
            'blocked' => 'Stopped by the gateway: '.implode(', ', (array) ($proposal->violations ?? [])),
            default => null,
        };

        return $this->record($rule, $run, $t, $read, $state, $note, $plan, (string) $proposal->getKey());
    }

    private function conflicts(AutomationRule $rule, array $t, string $type, Carbon $since): bool
    {
        $opposite = ['budget_increase' => 'budget_decrease', 'budget_decrease' => 'budget_increase', 'pause' => 'resume', 'resume' => 'pause'][$type] ?? null;
        if ($opposite !== null && AutomationRuleAction::withoutGlobalScopes()->where('project_id', $rule->project_id)
            ->where('target_external_id', $t['external_id'])->where('action', $opposite)->whereIn('status', ['proposed', 'executed'])
            ->where('created_at', '>=', $since)->exists()) {
            return true;
        }

        $gateway = ['pause' => 'pause', 'resume' => 'resume', 'budget_increase' => 'set_daily_budget', 'budget_decrease' => 'set_daily_budget'][$type] ?? null;

        return ActionProposal::withoutGlobalScopes()->where('project_id', $rule->project_id)->where('target_external_id', $t['external_id'])
            ->whereIn('status', ActionProposal::OPEN)->where('action', '!=', $gateway)->exists();
    }

    private function notify(AutomationRule $rule, array $t, array $read, string $type): void
    {
        if ($rule->created_by === null) {
            return;
        }
        $this->notifications->dispatch([
            'tenant_id' => (string) $rule->tenant_id, 'user_id' => $rule->created_by, 'project_id' => (string) $rule->project_id,
            'type' => 'automation_rule', 'severity' => 'warning',
            'title' => "Rule «{$rule->name}» matched: ".($t['name'] ?? $t['external_id']),
            'message' => $this->describe($read).($type !== 'notify' ? ' — action: '.$type.' (monitor only).' : ''),
            // NOTIFICATION-CHANNELS-001 — the email is in the reader's language; the figures are the same.
            'title_ar' => "طابقت القاعدة «{$rule->name}»: ".($t['name'] ?? $t['external_id']),
            'message_ar' => $this->describe($read).($type !== 'notify' ? ' — الإجراء: '.$type.' (مراقبة فقط).' : ''),
            'source' => 'automation', 'entity_type' => 'automation_rule', 'entity_id' => (string) $rule->getKey(),
            'action_url' => '/automation', 'dedup_extra' => $t['external_id'],
        ]);
    }

    /** @param array<string, mixed> $read */
    private function describe(array $read): string
    {
        $parts = [];
        foreach ($read as $k => $v) {
            $parts[] = $k.' = '.(is_float($v) ? round($v, 2) : ($v ?? 'not reported'));
        }

        return implode('; ', $parts);
    }

    /** @return array<string, mixed> */
    private function record(AutomationRule $rule, AutomationRuleRun $run, array $t, array $read, string $status, ?string $note, ?array $plan = null, ?string $proposalId = null): array
    {
        $plan ??= $this->plan($rule, $t);
        AutomationRuleAction::withoutGlobalScopes()->create([
            'tenant_id' => $rule->tenant_id, 'rule_id' => $rule->getKey(), 'run_id' => $run->getKey(), 'project_id' => $rule->project_id,
            'level' => $t['level'], 'provider' => $t['provider'], 'external_account_id' => $t['external_account_id'],
            'target_external_id' => $t['external_id'], 'target_name' => $t['name'], 'action' => (string) $rule->action['type'],
            'status' => $status, 'metrics' => $read, 'before' => $plan['before'], 'after' => $plan['after'], 'proposal_id' => $proposalId, 'note' => $note,
        ]);

        return ['status' => $status, 'note' => $note, 'before' => $plan['before'], 'after' => $plan['after'], 'proposal_id' => $proposalId];
    }
}
