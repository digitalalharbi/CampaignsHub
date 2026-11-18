<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROJECT-LIFECYCLE-001 §3 — what archiving a project stops, recorded so that un-archiving restores it exactly.
 *
 * Every change is a row of `project_archive_effects` holding the target row's values BEFORE and AFTER. Restore writes
 * `before` back only where the row still holds `after` — a setting somebody changed since is left as they set it
 * (outcome `skipped_changed`). Nothing is ever deleted, and every statement is filtered by THIS project's id: a
 * binding of the same ad account to another project («مشترك») is a different row and is never touched.
 *
 * | kind | target | archive | un-archive |
 * |---|---|---|---|
 * | binding_sync_stopped | project_integration_bindings (active, syncing) | sync_enabled=false | back |
 * | binding_unlinked (option «فك ربط الحسابات») | project_integration_bindings (active) | is_active=false, sync_enabled=false | back |
 * | report_schedule_paused | report_schedules (active) | active=false, next_run_at=null | back (next_run_at as it was) |
 * | alert_rule_disabled | alert_rules (active) | active=false | back |
 * | automation_rule_disabled | automation_rules (enabled) | enabled=false | back |
 * | agent_routine_disabled | agent_routines (enabled) | enabled=false | back |
 * | task_closed | tasks (not completed/cancelled) | status=cancelled + reason | back to its status |
 * | alert_closed | alert_events (open/snoozed) | status=resolved + reason | kept closed (`kept_closed`) — a point-in-time signal; the evaluator re-raises it if it still holds |
 * | proposal_expired | action_proposals (pending) | status=expired + reason | kept expired (`kept_closed`) — a stale recommendation is not revived |
 *
 * Soft delete (N2, §4) applies the same table with `unlink` forced on, plus three kinds that only a delete needs —
 * the paths that answer from outside a session:
 *
 * | kind | target | delete | restore |
 * |---|---|---|---|
 * | share_revoked | report_shares of this project's reports (live) | revoked_at=now | revoked_at back to NULL |
 * | lead_form_unpublished | lead_forms (published) | status=archived | back to published |
 * | conversion_destination_off | conversion_destinations (not off) | status=off | back to its status |
 *
 * Restore can be limited to ONE transition's effects ({@see self::restore()} `$onlyTransitionId`): restoring a
 * project deleted while archived reverses the delete's effects only, and leaves the archive's in place.
 *
 * Notifications, emails, digests, recommendations and aggregation need no row change: they read {@see LiveProjects},
 * which an archived project is not in. Spend limits and budgets are configuration that is simply no longer read.
 */
final class ArchiveEffects
{
    private const CLOSED_TASK_STATUSES = ['completed', 'cancelled'];

    /** An account in one of these states is no longer available to re-link (§5). */
    private const UNAVAILABLE_ACCOUNT_STATUSES = ['removed', 'deleted', 'revoked', 'disconnected'];

    /**
     * @return array<string,int> kind => rows changed (or that WOULD change on a dry run)
     */
    public function apply(Project $project, ?User $actor, bool $unlinkAccounts = false, ?string $transitionId = null, bool $dryRun = false, bool $forDelete = false): array
    {
        $summary = [];
        foreach ($this->plan($project, $unlinkAccounts || $forDelete) + ($forDelete ? $this->deletePlan($project) : []) as $kind => $spec) {
            $rows = $this->targets($project, $spec)->get();
            $summary[$kind] = $rows->count();
            if ($dryRun) {
                continue;
            }
            foreach ($rows as $row) {
                $this->applyOne($project, $actor, $transitionId, $kind, $spec, $row);
            }
        }

        return $summary;
    }

    /**
     * @param  string|null  $onlyTransitionId  reverse only the effects recorded by this transition (restore from the trash
     *                                         of a project that was archived before it was deleted)
     * @param  bool  $relinkAccounts  false keeps unlinked accounts unlinked (the person declined the re-link in the restore
     *                                window; outcome `kept_unlinked`)
     * @return array{restored: array<string,int>, skipped: array<string,int>}
     */
    public function restore(Project $project, ?User $actor, ?string $onlyTransitionId = null, bool $relinkAccounts = true): array
    {
        $restored = [];
        $skipped = [];
        $effects = DB::table('project_archive_effects')
            ->where('project_id', $project->getKey())
            ->whereNull('restored_at')
            ->when($onlyTransitionId !== null, fn ($q) => $q->where('transition_id', $onlyTransitionId))
            ->orderByDesc('applied_at')->orderByDesc('id')
            ->get();

        foreach ($effects as $effect) {
            $kind = (string) $effect->kind;
            $before = (array) json_decode((string) $effect->before, true);
            $after = (array) json_decode((string) $effect->after, true);
            $outcome = 'restored';

            if (in_array($kind, ['alert_closed', 'proposal_expired'], true)) {
                $outcome = 'kept_closed';
            } elseif ($kind === 'binding_unlinked' && ! $relinkAccounts) {
                $outcome = 'kept_unlinked';
            } else {
                $current = DB::table((string) $effect->target_table)->where('id', $effect->target_id)->first();
                if ($current === null) {
                    $outcome = 'skipped_missing';
                } elseif (str_starts_with($kind, 'binding_') && ! $this->accountAvailable($current)) {
                    // §5 — re-linked only if the account is still available (not removed from the inventory).
                    $outcome = 'skipped_unavailable';
                } elseif (! $this->stillHolds($current, $after)) {
                    $outcome = 'skipped_changed';
                } else {
                    DB::table((string) $effect->target_table)->where('id', $effect->target_id)
                        ->update($this->restoreValues($kind, $current, $before));
                }
            }

            DB::table('project_archive_effects')->where('id', $effect->id)->update([
                'restored_at' => Carbon::now(), 'restored_by' => $actor?->getKey(), 'restore_outcome' => $outcome,
            ]);
            if ($outcome === 'restored') {
                $restored[$kind] = ($restored[$kind] ?? 0) + 1;
            } else {
                $skipped[$kind.':'.$outcome] = ($skipped[$kind.':'.$outcome] ?? 0) + 1;
            }
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * Read-only: what archiving would stop, and the shared-account warning data.
     *
     * @return array<string,mixed>
     */
    public function impact(Project $project): array
    {
        return [
            'project' => ['id' => (string) $project->getKey(), 'name' => (string) $project->name, 'status' => (string) $project->status],
            'stops' => $this->apply($project, null, false, null, true),
            'stops_if_unlinked' => $this->apply($project, null, true, null, true),
            'shared_accounts' => $this->sharedAccounts($project),
            'ad_platforms_untouched' => true,
        ];
    }

    /**
     * Read-only: what restoring would re-link (§5). Each account this project had unlinked by an archive/delete that a
     * restore would reverse, and whether it is still available. Shared accounts: only THIS project's link is restored.
     *
     * @return list<array{binding_id: string, external_account_id: string, provider: string, name: string|null, external_id: string|null, available: bool}>
     */
    public function relinkCandidates(Project $project, ?string $onlyTransitionId = null): array
    {
        $rows = DB::table('project_archive_effects as e')
            ->join('project_integration_bindings as b', DB::raw('b.id::text'), '=', 'e.target_id')
            ->leftJoin('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where('e.project_id', $project->getKey())
            ->where('e.kind', 'binding_unlinked')
            ->whereNull('e.restored_at')
            ->when($onlyTransitionId !== null, fn ($q) => $q->where('e.transition_id', $onlyTransitionId))
            ->orderBy('a.name')
            ->get(['b.id as binding_id', 'b.external_account_id', 'b.provider', 'a.name', 'a.external_id', 'a.status', 'a.id as account_id']);

        return $rows->map(fn (object $r): array => [
            'binding_id' => (string) $r->binding_id,
            'external_account_id' => (string) $r->external_account_id,
            'provider' => (string) $r->provider,
            'name' => $r->name,
            'external_id' => $r->external_id,
            'available' => $r->account_id !== null && ! in_array((string) $r->status, self::UNAVAILABLE_ACCOUNT_STATUSES, true),
        ])->values()->all();
    }

    /**
     * This project's active bindings whose ad account is ALSO bound (actively) to another project. Archiving stops only
     * this project's binding; the other project keeps its data.
     *
     * @return list<array{external_account_id: string, provider: string, name: string|null, external_id: string|null, other_projects: list<array{id: string, name: string, status: string}>}>
     */
    public function sharedAccounts(Project $project): array
    {
        $mine = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where('b.project_id', $project->getKey())
            ->where('b.is_active', true)
            ->get(['a.id', 'a.provider', 'a.name', 'a.external_id']);

        $out = [];
        foreach ($mine as $account) {
            $others = DB::table('project_integration_bindings as b')
                ->join('projects as p', 'p.id', '=', 'b.project_id')
                ->where('b.external_account_id', $account->id)
                ->where('b.project_id', '!=', $project->getKey())
                ->where('b.is_active', true)
                ->whereNull('p.deleted_at')
                ->orderBy('p.name')
                ->get(['p.id', 'p.name', 'p.status']);
            if ($others->isEmpty()) {
                continue;
            }
            $out[] = [
                'external_account_id' => (string) $account->id,
                'provider' => (string) $account->provider,
                'name' => $account->name,
                'external_id' => $account->external_id,
                'other_projects' => $others->map(static fn (object $p): array => ['id' => (string) $p->id, 'name' => (string) $p->name, 'status' => (string) $p->status])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{table: string, where: callable, set: callable}>
     */
    private function plan(Project $project, bool $unlinkAccounts): array
    {
        $reason = (string) config('lifecycle.archive_close_reason', 'المشروع مؤرشف');
        $reasonKey = (string) config('lifecycle.archive_close_reason_key', 'projects.lifecycle.closed_reason.archived');
        $now = Carbon::now();

        $bindings = $unlinkAccounts
            ? ['binding_unlinked' => [
                'table' => 'project_integration_bindings',
                'where' => static fn ($q) => $q->where('is_active', true),
                'set' => static fn (object $r): array => ['is_active' => false, 'sync_enabled' => false],
            ]]
            : ['binding_sync_stopped' => [
                'table' => 'project_integration_bindings',
                'where' => static fn ($q) => $q->where('is_active', true)->where('sync_enabled', true),
                'set' => static fn (object $r): array => ['sync_enabled' => false],
            ]];

        return $bindings + [
            'report_schedule_paused' => [
                'table' => 'report_schedules',
                'where' => static fn ($q) => $q->where('active', true),
                'set' => static fn (object $r): array => ['active' => false, 'next_run_at' => null],
            ],
            'alert_rule_disabled' => [
                'table' => 'alert_rules',
                'where' => static fn ($q) => $q->where('active', true),
                'set' => static fn (object $r): array => ['active' => false],
            ],
            'automation_rule_disabled' => [
                'table' => 'automation_rules',
                'where' => static fn ($q) => $q->where('enabled', true),
                'set' => static fn (object $r): array => ['enabled' => false],
            ],
            'agent_routine_disabled' => [
                'table' => 'agent_routines',
                'where' => static fn ($q) => $q->where('enabled', true),
                'set' => static fn (object $r): array => ['enabled' => false],
            ],
            'task_closed' => [
                'table' => 'tasks',
                'where' => static fn ($q) => $q->whereNotIn('status', self::CLOSED_TASK_STATUSES),
                'set' => static fn (object $r): array => [
                    'status' => 'cancelled',
                    'meta' => json_encode(array_replace((array) json_decode((string) ($r->meta ?? 'null'), true), ['closed_reason' => $reason, 'closed_reason_key' => $reasonKey, 'status_before_archive' => $r->status]), JSON_UNESCAPED_UNICODE),
                ],
            ],
            'alert_closed' => [
                'table' => 'alert_events',
                'where' => static fn ($q) => $q->whereIn('status', ['open', 'snoozed']),
                'set' => static fn (object $r): array => [
                    'status' => 'resolved',
                    'resolved_at' => $now,
                    'snoozed_until' => null,
                    'context' => json_encode(array_replace((array) json_decode((string) ($r->context ?? 'null'), true), ['closed_reason' => $reason, 'closed_reason_key' => $reasonKey]), JSON_UNESCAPED_UNICODE),
                ],
            ],
            'proposal_expired' => [
                'table' => 'action_proposals',
                'where' => static fn ($q) => $q->where('status', 'pending'),
                'set' => static fn (object $r): array => ['status' => 'expired', 'decision_note' => $reason],
            ],
        ];
    }

    /**
     * The kinds only a soft delete applies (§4): the paths that answer from outside a session.
     *
     * @return array<string, array{table: string, where: callable, set: callable, query?: callable}>
     */
    private function deletePlan(Project $project): array
    {
        $now = Carbon::now();

        return [
            'share_revoked' => [
                'table' => 'report_shares',
                // Shares hang off a report: this project's reports only (tenant + project), never by tenant alone.
                'query' => static fn (): Builder => DB::table('report_shares')->whereIn('report_id', DB::table('reports')
                    ->where('tenant_id', $project->tenant_id)->where('project_id', $project->getKey())->select('id')),
                'where' => static fn ($q) => $q->whereNull('revoked_at'),
                'set' => static fn (object $r): array => ['revoked_at' => $now],
            ],
            'lead_form_unpublished' => [
                'table' => 'lead_forms',
                'where' => static fn ($q) => $q->where('status', 'published'),
                'set' => static fn (object $r): array => ['status' => 'archived'],
            ],
            'conversion_destination_off' => [
                'table' => 'conversion_destinations',
                'where' => static fn ($q) => $q->where('status', '!=', 'off'),
                'set' => static fn (object $r): array => ['status' => 'off'],
            ],
        ];
    }

    /**
     * @param  array{table: string, where: callable, set: callable, query?: callable}  $spec
     */
    private function targets(Project $project, array $spec): Builder
    {
        // Id-scoped AND tenant-scoped: only this project's own rows, ever.
        $q = isset($spec['query'])
            ? ($spec['query'])()
            : DB::table($spec['table'])->where('project_id', $project->getKey())->where('tenant_id', $project->tenant_id);
        ($spec['where'])($q);

        return $q->orderBy('id');
    }

    /** The binding's ad account still exists in the inventory and is not removed (§5 «if still available»). */
    private function accountAvailable(object $binding): bool
    {
        if (! property_exists($binding, 'external_account_id') || $binding->external_account_id === null) {
            return true;
        }
        $account = DB::table('external_accounts')->where('id', $binding->external_account_id)->first(['status']);

        return $account !== null
            && ! in_array((string) ($account->status ?? ''), self::UNAVAILABLE_ACCOUNT_STATUSES, true);
    }

    /**
     * @param  array{table: string, where: callable, set: callable}  $spec
     */
    private function applyOne(Project $project, ?User $actor, ?string $transitionId, string $kind, array $spec, object $row): void
    {
        $set = ($spec['set'])($row);
        $before = [];
        foreach (array_keys($set) as $column) {
            $before[$column] = $row->{$column} ?? null;
        }
        $hasUpdatedAt = property_exists($row, 'updated_at');

        DB::table($spec['table'])->where('id', $row->id)->update($set + ($hasUpdatedAt ? ['updated_at' => Carbon::now()] : []));

        DB::table('project_archive_effects')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->getKey(),
            'transition_id' => $transitionId,
            'kind' => $kind,
            'target_table' => $spec['table'],
            'target_id' => (string) $row->id,
            'before' => json_encode($this->normalise($before), JSON_UNESCAPED_UNICODE),
            'after' => json_encode($this->normalise($set), JSON_UNESCAPED_UNICODE),
            'applied_by' => $actor?->getKey(),
            'applied_at' => Carbon::now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /** @param array<string,mixed> $after */
    private function stillHolds(object $current, array $after): bool
    {
        foreach ($after as $column => $value) {
            if (in_array($column, ['meta', 'context', 'next_run_at', 'resolved_at'], true)) {
                continue; // carriers of the change, not the switch itself
            }
            if ($column === 'revoked_at') {
                if (($current->revoked_at ?? null) === null) {
                    return false; // un-revoked since: somebody already decided
                }

                continue;
            }
            if ($this->scalar($current->{$column} ?? null) !== $this->scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $before
     * @return array<string,mixed>
     */
    private function restoreValues(string $kind, object $current, array $before): array
    {
        if ($kind === 'task_closed') {
            $meta = (array) json_decode((string) ($current->meta ?? 'null'), true);
            unset($meta['closed_reason'], $meta['closed_reason_key'], $meta['status_before_archive']);

            return ['status' => $before['status'], 'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)] + (property_exists($current, 'updated_at') ? ['updated_at' => Carbon::now()] : []);
        }

        return $before + (property_exists($current, 'updated_at') ? ['updated_at' => Carbon::now()] : []);
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function normalise(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? Carbon::instance($v)->toIso8601String() : $v, $values);
    }

    private function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return (string) $v;
    }
}
