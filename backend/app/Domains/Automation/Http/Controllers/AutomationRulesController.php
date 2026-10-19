<?php

declare(strict_types=1);

namespace App\Domains\Automation\Http\Controllers;

use App\Domains\Agents\Services\TrustLadder;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Models\AutomationRuleAction;
use App\Domains\Automation\Models\AutomationRuleRun;
use App\Domains\Automation\Services\RuleEvaluator;
use App\Domains\Automation\Support\RuleCatalog;
use App\Domains\Automation\Support\RuleInput;
use App\Domains\Automation\Support\RuleSchedule;
use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * AUTO-RULES-001 — the Automated Rules operating surface for one project.
 *
 * Reading needs campaigns.view. Writing a rule needs the project's campaign-manage capability (route) and,
 * for a rule that may change a platform, the permission that change needs (campaigns.pause or
 * campaigns.budget.change); an Auto-Execute rule also needs ai.manage. Every create, edit, switch and
 * delete is in the change log.
 */
final class AutomationRulesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function catalog(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $row = Project::query()->findOrFail($project);
        $ladder = app(TrustLadder::class);

        $support = [];
        foreach (AdPlatforms::ORDER as $provider) {
            foreach (['pause_resume', 'budget_change'] as $cap) {
                $entry = collect(WriteCapabilityRegistry::entries())->first(fn (array $e): bool => $e['provider'] === $provider && $e['capability'] === $cap);
                $support[$provider][$cap] = $entry['status'] ?? WriteCapabilityRegistry::NOT_IMPLEMENTED;
            }
            $support[$provider]['execution_enabled'] = in_array($provider, (array) config('agents.execute_providers', []), true);
        }

        return ApiResponse::success(RuleCatalog::forBuilder() + [
            'providers' => $support,
            'autonomy' => ['pause' => $ladder->activeGrant($row, 'pause') !== null, 'budget_decrease' => $ladder->activeGrant($row, 'budget_decrease') !== null],
        ], 'Catalog.');
    }

    /**
     * What a rule's scope can name: the project's actively bound ad accounts and their platform campaigns
     * and ad groups — the same ids RuleTargets reads, so a scope can only name the project's own objects.
     */
    public function targets(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        // Advertising accounts only: a store, an analytics property or a pixel bound to the project is not
        // something a rule can pause or budget.
        $accounts = ExternalAccount::query()->whereIn('id', BoundAccountVisibility::activeAccountIds($project) ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('account_type', 'ad_account')->get(['id', 'provider', 'name', 'external_id']);
        $accountIds = $accounts->map(fn ($a) => (string) $a->id)->all();
        $campaigns = ExternalCampaign::query()->where('project_id', $project)->whereIn('external_account_id', $accountIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->orderBy('name')->limit(1000)->get(['id', 'provider', 'name', 'status', 'external_account_id', 'daily_budget', 'currency']);
        $sets = ExternalAdSet::query()->where('project_id', $project)->whereIn('external_campaign_id', $campaigns->modelKeys() ?: ['00000000-0000-0000-0000-000000000000'])
            ->orderBy('name')->limit(2000)->get(['id', 'name', 'status', 'external_campaign_id']);

        return ApiResponse::success([
            'accounts' => $accounts->map(fn ($a): array => ['id' => (string) $a->id, 'provider' => AdPlatforms::canonical((string) $a->provider), 'name' => $a->name ?? $a->external_id])->values()->all(),
            'campaigns' => $campaigns->map(fn ($c): array => ['id' => (string) $c->id, 'provider' => AdPlatforms::canonical((string) $c->provider), 'name' => $c->name, 'status' => $c->status, 'account_id' => (string) $c->external_account_id, 'daily_budget' => $c->daily_budget !== null ? (float) $c->daily_budget : null, 'currency' => $c->currency])->values()->all(),
            'ad_sets' => $sets->map(fn ($s): array => ['id' => (string) $s->id, 'name' => $s->name, 'status' => $s->status, 'campaign_id' => (string) $s->external_campaign_id])->values()->all(),
        ], 'Targets.');
    }

    /**
     * Which platform figures each of the project's ad platforms actually reported lately — so the builder
     * can say, before a rule is saved, that a platform in scope never reports what a condition needs.
     *
     * Per platform: `has_data` (anything reported in the window at all — a platform just connected has
     * nothing yet, which is «not known», not «not supported») and `reported` (the base figure keys it sent).
     * Only the project's actively bound ad accounts count, through the same visibility bound every metric
     * read uses.
     */
    public function metricSupport(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $days = max(7, min((int) $request->integer('days', 30), 90));
        $accounts = ExternalAccount::query()->whereIn('id', BoundAccountVisibility::activeAccountIds($project) ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('account_type', 'ad_account')->get(['id', 'provider']);

        $q = DailyMetric::withoutGlobalScopes()->where('project_id', $project)
            ->whereIn('external_account_id', $accounts->modelKeys() ?: ['00000000-0000-0000-0000-000000000000'])
            ->whereIn('metric_key', ['spend', 'impressions', 'clicks', 'conversions', 'revenue'])
            ->whereNotNull('value')
            ->whereBetween('metric_date', [Carbon::today()->subDays($days - 1)->toDateString(), Carbon::today()->toDateString()]);
        BoundAccountVisibility::apply($q, 'daily_metrics');

        $reported = [];
        foreach ($q->select('provider', 'metric_key')->distinct()->get() as $row) {
            $reported[AdPlatforms::canonical((string) $row->provider)][(string) $row->metric_key] = true;
        }

        $out = [];
        foreach ($accounts->pluck('provider')->map(fn ($p) => AdPlatforms::canonical((string) $p))->unique()->sort()->values() as $provider) {
            $keys = array_keys($reported[$provider] ?? []);
            sort($keys);
            $out[$provider] = ['has_data' => $keys !== [], 'reported' => $keys];
        }

        return ApiResponse::success(['days' => $days, 'providers' => (object) $out], 'Metric support.');
    }

    /** The cards at the top: what the rules did over the last N days, like the platform's own summary. */
    public function summary(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $days = max(1, min((int) $request->integer('days', 7), 90));
        $since = Carbon::now()->subDays($days);
        $acted = AutomationRuleAction::query()->where('project_id', $project)->where('created_at', '>=', $since)->whereIn('status', AutomationRuleAction::ACTED);
        $count = fn (array $actions): int => (clone $acted)->whereIn('action', $actions)->count();
        $rules = AutomationRule::query()->where('project_id', $project);
        $activeRecently = AutomationRuleAction::query()->where('project_id', $project)->where('created_at', '>=', Carbon::now()->subDays(30))->distinct()->pluck('rule_id')->map(fn ($v) => (string) $v)->all();

        return ApiResponse::success([
            'days' => $days,
            'all' => (clone $acted)->count(),
            'turn_on' => $count(['resume']),
            'turn_off' => $count(['pause']),
            'budget' => $count(['budget_increase', 'budget_decrease']),
            'bid' => $count(['bid_adjust']),
            'notified' => $count(['notify']),
            'failed' => (clone $acted)->where('status', 'failed')->count(),
            'awaiting_approval' => (clone $acted)->where('status', 'proposed')->count(),
            'rules_enabled' => (clone $rules)->where('enabled', true)->count(),
            'rules_disabled' => (clone $rules)->where('enabled', false)->count(),
            'rules_idle_30d' => (clone $rules)->where('enabled', true)->get(['id'])->filter(fn ($r) => ! in_array((string) $r->id, $activeRecently, true))->count(),
        ], 'Summary.');
    }

    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        return ApiResponse::success(['rules' => AutomationRule::query()->where('project_id', $project)->latest('updated_at')->get()->map(fn (AutomationRule $r): array => $this->shape($r))->all()], 'Rules.');
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorise($request, $data);
        $rule = new AutomationRule;
        $rule->forceFill($data + ['project_id' => $project, 'created_by' => $request->user()->getKey(), 'updated_by' => $request->user()->getKey()]);
        $rule->next_run_at = $rule->enabled ? RuleSchedule::next($rule) : null;
        $rule->save();
        $this->audit->log(action: 'automation.rule.created', entityType: AutomationRule::class, entityId: (string) $rule->getKey(), after: $this->auditShape($rule));

        return ApiResponse::success($this->shape($rule), 'Rule created.', status: 201);
    }

    public function update(Request $request, string $project, string $rule): JsonResponse
    {
        $row = AutomationRule::query()->where('project_id', $project)->findOrFail($rule);
        $data = $this->validated($request);
        $this->authorise($request, $data);
        $before = $this->auditShape($row);
        $row->forceFill($data + ['updated_by' => $request->user()->getKey()]);
        $row->next_run_at = $row->enabled ? RuleSchedule::next($row) : null;
        $row->save();
        $this->audit->log(action: 'automation.rule.updated', entityType: AutomationRule::class, entityId: (string) $row->getKey(), before: $before, after: $this->auditShape($row));

        return ApiResponse::success($this->shape($row), 'Rule updated.');
    }

    public function toggle(Request $request, string $project, string $rule): JsonResponse
    {
        $row = AutomationRule::query()->where('project_id', $project)->findOrFail($rule);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $this->authorise($request, $this->auditShape($row));
        $row->forceFill(['enabled' => $enabled, 'next_run_at' => $enabled ? RuleSchedule::next($row) : null, 'updated_by' => $request->user()->getKey()])->save();
        $this->audit->log(action: $enabled ? 'automation.rule.enabled' : 'automation.rule.disabled', entityType: AutomationRule::class, entityId: (string) $row->getKey());

        return ApiResponse::success($this->shape($row), $enabled ? 'Rule on.' : 'Rule off.');
    }

    public function destroy(Request $request, string $project, string $rule): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $row = AutomationRule::query()->where('project_id', $project)->findOrFail($rule);
        $this->audit->log(action: 'automation.rule.deleted', entityType: AutomationRule::class, entityId: (string) $row->getKey(), before: $this->auditShape($row));
        $row->delete();

        return ApiResponse::success(['deleted' => true], 'Rule deleted.');
    }

    /** Evaluate now WITHOUT acting: what would match, with its figures and the change it would ask for. */
    public function dryRun(Request $request, string $project, string $rule, RuleEvaluator $evaluator): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $row = AutomationRule::query()->where('project_id', $project)->findOrFail($rule);
        ['run' => $run, 'results' => $results] = $evaluator->evaluate($row, 'manual', dryRun: true);

        return ApiResponse::success(['run' => $this->runShape($run), 'results' => array_map(fn (array $r): array => [
            'provider' => $r['target']['provider'], 'name' => $r['target']['name'], 'external_id' => $r['target']['external_id'], 'level' => $r['target']['level'],
            'matched' => $r['matched'], 'status' => $r['status'], 'metrics' => $r['metrics'], 'before' => $r['before'] ?? null, 'after' => $r['after'] ?? null,
        ], $results)], 'Dry run.');
    }

    public function runNow(Request $request, string $project, string $rule, RuleEvaluator $evaluator): JsonResponse
    {
        $row = AutomationRule::query()->where('project_id', $project)->findOrFail($rule);
        $this->authorise($request, $this->auditShape($row));
        ['run' => $run] = $evaluator->evaluate($row, 'manual');

        return ApiResponse::success($this->runShape($run), 'Rule ran.');
    }

    public function runs(Request $request, string $project, string $rule): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $row = AutomationRule::query()->where('project_id', $project)->findOrFail($rule);

        return ApiResponse::success(['runs' => AutomationRuleRun::query()->where('rule_id', $row->getKey())->latest('started_at')->limit(50)->get()->map(fn ($r) => $this->runShape($r))->all()], 'Runs.');
    }

    /** Action history: every object a rule touched or declined to touch, with before and after values. */
    public function actions(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $q = AutomationRuleAction::query()->where('project_id', $project)->latest('created_at')
            ->when($request->filled('rule_id'), fn ($q) => $q->where('rule_id', (string) $request->string('rule_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->string('status')));
        $names = AutomationRule::withTrashed()->where('project_id', $project)->pluck('name', 'id');

        return ApiResponse::success(['actions' => $q->limit(200)->get()->map(fn (AutomationRuleAction $a): array => [
            'id' => (string) $a->getKey(), 'rule_id' => (string) $a->rule_id, 'rule' => $names[(string) $a->rule_id] ?? null,
            'provider' => $a->provider, 'level' => $a->level, 'target' => $a->target_name ?? $a->target_external_id, 'external_id' => $a->target_external_id,
            'action' => $a->action, 'status' => $a->status, 'before' => $a->before, 'after' => $a->after, 'metrics' => $a->metrics,
            'proposal_id' => $a->proposal_id, 'note' => $a->note, 'at' => $a->created_at?->toIso8601String(),
        ])->all()], 'Actions.');
    }

    public function changeLog(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $ids = AutomationRule::withTrashed()->where('project_id', $project)->pluck('name', 'id');

        return ApiResponse::success(['changes' => AuditLog::query()->where('entity_type', AutomationRule::class)
            ->whereIn('entity_id', $ids->keys()->map(fn ($k) => (string) $k)->all() ?: ['none'])->latest('created_at')->limit(200)->get()
            ->map(fn (AuditLog $l): array => ['action' => $l->action, 'rule' => $ids[$l->entity_id] ?? null, 'rule_id' => $l->entity_id, 'user_id' => $l->user_id, 'before' => $l->before, 'after' => $l->after, 'at' => $l->created_at?->toIso8601String()])
            ->all()], 'Change log.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return RuleInput::validate($request->all());
    }

    /** @param array<string, mixed> $data */
    private function authorise(Request $request, array $data): void
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('campaigns.view'), 403);
        $type = (string) ($data['action']['type'] ?? 'notify');
        if (($data['mode'] ?? 'monitor') !== 'monitor' && $type !== 'notify') {
            $needs = in_array($type, ['pause', 'resume'], true) ? 'campaigns.pause' : 'campaigns.budget.change';
            abort_unless($user->hasPermission($needs), 403, 'You may not create a rule that asks for this change.');
        }
        if (($data['mode'] ?? 'monitor') === 'auto') {
            abort_unless($user->hasPermission('ai.manage'), 403, 'Auto-execute rules are set by someone who manages automation.');
        }
    }

    /** @return array<string, mixed> */
    private function shape(AutomationRule $r): array
    {
        return [
            'id' => (string) $r->getKey(), 'name' => $r->name, 'enabled' => $r->enabled, 'mode' => $r->mode, 'level' => $r->level,
            'scope' => $r->scope ?? (object) [], 'logic' => $r->logic, 'conditions' => $r->conditions, 'action' => $r->action, 'schedule' => $r->schedule,
            'cooldown_hours' => $r->cooldown_hours, 'min_spend' => $r->min_spend, 'last_run_at' => $r->last_run_at?->toIso8601String(),
            'next_run_at' => $r->next_run_at?->toIso8601String(), 'last_result' => $r->last_result,
            // AUTO-RULES-AGENTS-009 — an agent's draft says so until a person deletes it.
            'origin' => $r->origin ?? 'person', 'drafted_by_agent' => $r->drafted_by_agent,
        ];
    }

    /** @return array<string, mixed> */
    private function auditShape(AutomationRule $r): array
    {
        return array_intersect_key($this->shape($r), array_flip(['name', 'enabled', 'mode', 'level', 'scope', 'logic', 'conditions', 'action', 'schedule', 'cooldown_hours', 'min_spend']));
    }

    /** @return array<string, mixed> */
    private function runShape(AutomationRuleRun $r): array
    {
        return [
            'id' => (string) $r->getKey(), 'trigger' => $r->trigger, 'dry_run' => $r->dry_run, 'status' => $r->status, 'evaluated' => $r->evaluated,
            'matched' => $r->matched, 'acted' => $r->acted, 'skipped' => $r->skipped, 'failed' => $r->failed, 'error' => $r->error,
            'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
