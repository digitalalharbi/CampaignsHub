<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Models\AutomationRuleAction;
use App\Domains\Automation\Models\AutomationRuleRun;
use App\Domains\Automation\Support\RuleInput;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * AUTO-RULES-AGENTS-009 — what an agent may do with Automated Rules: read them, explain a run, and DRAFT one.
 *
 * Automation-first: the rules are the product, and the agents work on them rather than beside them. An
 * agent reads the rules a project has and why a run did what it did, from the same rows the dashboard
 * shows. It may write a rule only as a DRAFT:
 *
 *  - saved switched OFF, so it never runs until a person switches it on;
 *  - in Monitor Only, whatever the agent asked for, so even switched on it changes nothing — a person
 *    chooses Recommend or Auto-Execute in the builder, where the permission and trust-ladder checks
 *    for those modes apply to the person;
 *  - validated by `RuleInput`, the builder's own definition, so an agent cannot save a rule a person
 *    could not have;
 *  - marked `origin = agent` with the agent's key, for as long as it exists, and audited.
 *
 * The project is always the conversation's, passed in by the engine; a rule id from another project is
 * not found.
 */
final class AgentRuleTools
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function list(Project $project): array
    {
        $rules = AutomationRule::query()->where('project_id', $project->getKey())->orderBy('name')->limit(50)->get();

        return [
            'rules' => $rules->map(fn (AutomationRule $r): array => $this->shape($r))->all(),
            'note' => 'Rules are evaluated on their schedule by the platform. A rule that is off does not run.',
        ];
    }

    /** @return array<string, mixed> */
    public function explain(Project $project, string $ruleId): array
    {
        abort_unless(Str::isUuid($ruleId), 422, 'rule_id must be an id returned by list_automation_rules.');
        $rule = AutomationRule::query()->where('project_id', $project->getKey())->find($ruleId);
        abort_if($rule === null, 422, 'That rule is not in this project.');

        $runs = AutomationRuleRun::query()->where('rule_id', $rule->getKey())->latest('started_at')->limit(3)->get();
        $latest = $runs->first();
        $actions = $latest === null ? collect() : AutomationRuleAction::query()->where('run_id', $latest->getKey())->limit(50)->get();

        return [
            'rule' => $this->shape($rule),
            'runs' => $runs->map(static fn (AutomationRuleRun $r): array => [
                'trigger' => $r->trigger, 'dry_run' => (bool) $r->dry_run, 'status' => $r->status, 'evaluated' => $r->evaluated,
                'matched' => $r->matched, 'acted' => $r->acted, 'skipped' => $r->skipped, 'failed' => $r->failed,
                'error' => $r->error, 'started_at' => $r->started_at?->toIso8601String(),
            ])->all(),
            // Each target of the latest run: what was measured, what the rule did and why.
            'latest_run_targets' => $actions->map(static fn (AutomationRuleAction $a): array => [
                'target' => $a->target_name, 'provider' => $a->provider, 'status' => $a->status, 'action' => $a->action,
                'metrics' => $a->metrics, 'before' => $a->before, 'after' => $a->after, 'note' => $a->note,
            ])->all(),
            'note' => match (true) {
                $latest === null => 'This rule has not run yet.',
                (bool) $latest->dry_run => 'The latest run was a dry run, which keeps its totals only; per-target figures exist for real runs.',
                default => 'Figures are the ones the rule read at that run.',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function draft(Project $project, ?User $user, string $agentKey, array $input): array
    {
        $data = RuleInput::validate([
            ...$input,
            // A draft: off, and Monitor Only. Never what the model asked for here.
            'enabled' => false,
            'mode' => 'monitor',
            'cooldown_hours' => $input['cooldown_hours'] ?? 24,
            'logic' => $input['logic'] ?? 'all',
            'level' => $input['level'] ?? 'campaign',
            'schedule' => $input['schedule'] ?? ['frequency' => 'daily', 'at' => '09:00', 'timezone' => 'Asia/Riyadh'],
        ]);

        $rule = new AutomationRule;
        $rule->forceFill($data + [
            'project_id' => $project->getKey(),
            'origin' => 'agent',
            'drafted_by_agent' => $agentKey,
            'created_by' => $user?->getKey(),
            'updated_by' => $user?->getKey(),
            'next_run_at' => null,
        ]);
        $rule->save();

        $this->audit->log(
            action: 'automation.rule.drafted_by_agent',
            entityType: AutomationRule::class,
            entityId: (string) $rule->getKey(),
            after: ['agent' => $agentKey, 'name' => $rule->name, 'mode' => $rule->mode, 'enabled' => false, 'conditions' => $rule->conditions, 'action' => $rule->action],
        );

        return [
            'rule_id' => (string) $rule->getKey(),
            'enabled' => false,
            'mode' => 'monitor',
            'note' => 'Saved as a draft: switched off and in Monitor Only. A person reviews it in Automated Rules and decides whether to switch it on and which mode it runs in. Nothing has changed.',
        ];
    }

    /** @return array<string, mixed> */
    private function shape(AutomationRule $r): array
    {
        return [
            'id' => (string) $r->getKey(), 'name' => $r->name, 'enabled' => (bool) $r->enabled, 'mode' => $r->mode, 'level' => $r->level,
            'logic' => $r->logic, 'conditions' => $r->conditions, 'action' => $r->action, 'schedule' => $r->schedule,
            'cooldown_hours' => $r->cooldown_hours, 'min_spend' => $r->min_spend, 'origin' => $r->origin ?? 'person',
            'last_run_at' => $r->last_run_at?->toIso8601String(), 'last_result' => $r->last_result,
        ];
    }
}
