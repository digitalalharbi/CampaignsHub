<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROJECT-LIFECYCLE-001 — the implementation of {@see ProjectLifecycle}.
 *
 * {@see self::transition()} is the ONE writer of `projects.status` and {@see self::transitionClient()} the ONE writer
 * of `client_workspaces.client_status`. `Project::updating` throws when the status changes outside the first, and
 * `ProjectLifecycleSourceGuardTest` fails the build on any other writer in `app/`.
 */
final class ProjectStateMachine implements ProjectLifecycle
{
    private static int $writing = 0;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ArchiveEffects $effects,
        private readonly TrashRetention $retention,
    ) {}

    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    public function transition(Project $project, ProjectStatus $to, ?User $actor, ?string $reason = null, array $context = []): TransitionRecord
    {
        return DB::transaction(function () use ($project, $to, $actor, $reason, $context): TransitionRecord {
            /** @var Project $row */
            $row = Project::withoutGlobalScopes()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $from = ProjectStatus::normalise($row->status);

            if (! $from->canBecome($to)) {
                throw InvalidLifecycleTransition::between($from->value, $to->value);
            }

            $now = Carbon::now();
            $changes = ['status' => $to->value];

            if ($to === ProjectStatus::Archived) {
                // Back from the trash into «المؤرشفة»: the state before THAT archive is the one un-archiving returns to
                // (the delete recorded `previous_status = archived`, so it is read from the archive's own log row).
                $changes += ['archived_at' => $from === ProjectStatus::Deleted ? ($row->archived_at ?? $now) : $now, 'archived_by' => $from === ProjectStatus::Deleted ? ($row->archived_by ?? $actor?->getKey()) : $actor?->getKey(), 'previous_status' => $from === ProjectStatus::Deleted ? $this->stateBeforeLastArchive((string) $row->getKey()) : $from->value];
            } elseif ($to === ProjectStatus::Deleted) {
                $changes += [
                    'deleted_at' => $now,
                    'deleted_by' => $actor?->getKey(),
                    'purge_after' => $now->copy()->addDays($this->retention->daysFor((string) $row->tenant_id)),
                    'previous_status' => $from->value,
                ];
            } else {
                // Back to a working state: whatever stamped the archive/delete is cleared.
                $changes += ['archived_at' => null, 'archived_by' => null, 'deleted_at' => null, 'deleted_by' => null, 'purge_after' => null, 'previous_status' => null];
            }
            if ($from === ProjectStatus::Deleted && $to === ProjectStatus::Archived) {
                $changes += ['deleted_at' => null, 'deleted_by' => null, 'purge_after' => null];
            }

            self::$writing++;
            try {
                $row->forceFill($changes)->save();
            } finally {
                self::$writing--;
            }
            $project->setRawAttributes($row->getAttributes(), true);

            $client = DB::table('client_workspaces')->where('id', $row->client_workspace_id)->first(['name']);
            $id = (string) Str::uuid();
            DB::table('project_status_transitions')->insert([
                'id' => $id,
                'tenant_id' => $row->tenant_id,
                'project_id' => $row->getKey(),
                'client_workspace_id' => $row->client_workspace_id,
                'name_snapshot' => $row->name,
                'client_name_snapshot' => $client?->name,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->getKey(),
                'actor_name_snapshot' => $actor?->name,
                'reason' => $reason,
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => $now->format('Y-m-d H:i:s.uP'), // microseconds: two transitions in one second keep their order
            ]);

            $this->audit->log(
                action: 'project.lifecycle.'.$to->value,
                entityType: Project::class,
                entityId: (string) $row->getKey(),
                before: ['status' => $from->value, 'name' => $row->name],
                after: ['status' => $to->value, 'name' => $row->name, 'transition_id' => $id] + ($context === [] ? [] : ['context' => $context]),
                reason: $reason,
                userId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
                tenantId: (string) $row->tenant_id,
            );

            return new TransitionRecord($id, (string) $row->getKey(), $from->value, $to->value, $actor?->getKey() === null ? null : (int) $actor->getKey(), $now->toIso8601String());
        });
    }

    public function transitionClient(ClientWorkspace $client, ClientStatus $to, ?User $actor, ?string $reason = null, array $context = []): TransitionRecord
    {
        return DB::transaction(function () use ($client, $to, $actor, $reason, $context): TransitionRecord {
            /** @var ClientWorkspace $row */
            $row = ClientWorkspace::withoutGlobalScopes()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $from = ClientStatus::tryFrom((string) $row->client_status) ?? ClientStatus::Active;
            // Rows archived before the lifecycle columns: `archived_at` is the archive's truth.
            if ($row->archived_at !== null && $from !== ClientStatus::Deleted) {
                $from = ClientStatus::Archived;
            }

            if (! $from->canBecome($to)) {
                throw InvalidLifecycleTransition::between($from->value, $to->value);
            }

            $now = Carbon::now();
            $changes = ['client_status' => $to->value];
            if ($to === ClientStatus::Archived) {
                $changes += ['archived_at' => $now, 'archived_by' => $actor?->getKey(), 'previous_status' => $from === ClientStatus::Deleted ? $row->previous_status : $from->value];
            } elseif ($to === ClientStatus::Deleted) {
                $changes += ['deleted_at' => $now, 'deleted_by' => $actor?->getKey(), 'purge_after' => $now->copy()->addDays($this->retention->daysFor((string) $row->tenant_id)), 'previous_status' => $from->value];
            } else {
                $changes += ['archived_at' => null, 'archived_by' => null, 'deleted_at' => null, 'deleted_by' => null, 'purge_after' => null, 'previous_status' => null];
            }
            if ($from === ClientStatus::Deleted && $to === ClientStatus::Archived) {
                $changes += ['deleted_at' => null, 'deleted_by' => null, 'purge_after' => null];
            }

            $row->forceFill($changes)->save();
            $client->setRawAttributes($row->getAttributes(), true);

            $id = (string) Str::uuid();
            DB::table('client_status_transitions')->insert([
                'id' => $id,
                'tenant_id' => $row->tenant_id,
                'client_workspace_id' => $row->getKey(),
                'name_snapshot' => $row->name,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->getKey(),
                'actor_name_snapshot' => $actor?->name,
                'reason' => $reason,
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => $now->format('Y-m-d H:i:s.uP'), // microseconds: two transitions in one second keep their order
            ]);

            $this->audit->log(
                action: 'client.lifecycle.'.$to->value,
                entityType: 'client_workspace',
                entityId: (string) $row->getKey(),
                before: ['client_status' => $from->value, 'name' => $row->name],
                after: ['client_status' => $to->value, 'name' => $row->name, 'transition_id' => $id],
                reason: $reason,
                userId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
                tenantId: (string) $row->tenant_id,
            );

            return new TransitionRecord($id, (string) $row->getKey(), $from->value, $to->value, $actor?->getKey() === null ? null : (int) $actor->getKey(), $now->toIso8601String());
        });
    }

    public function archive(Project $project, ?User $actor, bool $unlinkAccounts = false, ?string $reason = null): array
    {
        return DB::transaction(function () use ($project, $actor, $unlinkAccounts, $reason): array {
            $shared = $this->effects->sharedAccounts($project);
            $record = $this->transition($project, ProjectStatus::Archived, $actor, $reason, ['unlink_accounts' => $unlinkAccounts]);
            $effects = $this->effects->apply($project, $actor, $unlinkAccounts, $record->id);

            return ['transition' => $record, 'effects' => $effects, 'shared_accounts' => $shared];
        });
    }

    public function unarchive(Project $project, ?User $actor, bool $confirmed, bool $relinkAccounts = true): array
    {
        if (! $confirmed) {
            throw new InvalidLifecycleTransition('Un-archiving needs explicit confirmation.');
        }

        return DB::transaction(function () use ($project, $actor, $relinkAccounts): array {
            $current = Project::withoutGlobalScopes()->withTrashed()->whereKey($project->getKey())->firstOrFail();
            if (ProjectStatus::normalise($current->status) !== ProjectStatus::Archived) {
                throw InvalidLifecycleTransition::between((string) $current->status, 'unarchive');
            }
            $back = ProjectStatus::tryFrom((string) $current->previous_status);
            if ($back === null || (! $back->isLive() && $back !== ProjectStatus::Draft)) {
                $back = ProjectStatus::Active;
            }

            $record = $this->transition($project, $back, $actor, 'unarchive', $relinkAccounts ? [] : ['relink_accounts' => false]);
            $result = $this->effects->restore($project, $actor, null, $relinkAccounts);

            return ['transition' => $record, 'restored' => $result['restored'], 'skipped' => $result['skipped']];
        });
    }

    public function softDelete(Project $project, ?User $actor, ?string $reason = null, array $context = []): array
    {
        return DB::transaction(function () use ($project, $actor, $reason, $context): array {
            $shared = $this->effects->sharedAccounts($project);
            $record = $this->transition($project, ProjectStatus::Deleted, $actor, $reason ?? 'delete', $context + ['unlink_accounts' => true]);
            $effects = $this->effects->apply($project, $actor, true, $record->id, false, true);

            return ['transition' => $record, 'effects' => $effects, 'shared_accounts' => $shared];
        });
    }

    public function restoreFromTrash(Project $project, ?User $actor, bool $confirmed, bool $relinkAccounts = true): array
    {
        if (! $confirmed) {
            throw new InvalidLifecycleTransition('Restoring from the trash needs explicit confirmation.');
        }

        return DB::transaction(function () use ($project, $actor, $relinkAccounts): array {
            $current = Project::withoutGlobalScopes()->whereKey($project->getKey())->firstOrFail();
            if (ProjectStatus::normalise($current->status) !== ProjectStatus::Deleted || $current->getAttribute('purged_at') !== null) {
                throw InvalidLifecycleTransition::between((string) $current->status, 'restore_from_trash');
            }
            $back = ProjectStatus::tryFrom((string) $current->previous_status);
            if ($back === null || $back === ProjectStatus::Deleted) {
                $back = ProjectStatus::Active;
            }
            $deleteTransition = $this->lastTransitionInto((string) $current->getKey(), ProjectStatus::Deleted);

            $record = $this->transition($project, $back, $actor, 'restore_from_trash', $relinkAccounts ? [] : ['relink_accounts' => false]);
            $result = $this->effects->restore($project, $actor, $deleteTransition, $relinkAccounts);

            return ['transition' => $record, 'restored' => $result['restored'], 'skipped' => $result['skipped']];
        });
    }

    /** The id of the newest transition of this project INTO `$status`, or null. */
    public function lastTransitionInto(string $projectId, ProjectStatus $status): ?string
    {
        $id = DB::table('project_status_transitions')->where('project_id', $projectId)->where('to_status', $status->value)
            ->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** The state the project was in before its most recent archive (falls back to active). */
    private function stateBeforeLastArchive(string $projectId): string
    {
        $from = DB::table('project_status_transitions')->where('project_id', $projectId)->where('to_status', ProjectStatus::Archived->value)
            ->where('from_status', '!=', ProjectStatus::Deleted->value)
            ->orderByDesc('created_at')->orderByDesc('id')->value('from_status');
        $state = ProjectStatus::tryFrom((string) $from);

        return $state !== null && ($state->isLive() || $state === ProjectStatus::Draft) ? $state->value : ProjectStatus::Active->value;
    }

    public function archiveImpact(Project $project): array
    {
        return $this->effects->impact($project);
    }

    public function history(Project $project): array
    {
        return DB::table('project_status_transitions')
            ->where('project_id', $project->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'from_status', 'to_status', 'actor_id', 'actor_name_snapshot', 'name_snapshot', 'reason', 'context', 'created_at'])
            ->map(static fn (object $r): array => [
                'id' => (string) $r->id,
                'from' => (string) $r->from_status,
                'to' => (string) $r->to_status,
                'actor_id' => $r->actor_id === null ? null : (int) $r->actor_id,
                'actor_name' => $r->actor_name_snapshot,
                'name_snapshot' => $r->name_snapshot,
                'reason' => $r->reason,
                'context' => $r->context === null ? null : json_decode((string) $r->context, true),
                'at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
            ])->all();
    }
}
