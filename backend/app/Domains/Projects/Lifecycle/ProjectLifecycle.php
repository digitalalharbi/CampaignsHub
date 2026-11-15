<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Models\User;

/**
 * PROJECT-LIFECYCLE-001 — the contract every lifecycle feature builds on (docs/rules/project-lifecycle.md).
 *
 * Bound to {@see ProjectStateMachine}. Builders of delete/restore/move/UI call THIS, never write a status.
 */
interface ProjectLifecycle
{
    /**
     * The ONE function that changes `projects.status`. Checks the allowed-transitions table
     * ({@see ProjectStatus::allowedTargets()}), writes the state and its stamps (archived_at/by, deleted_at/by,
     * purge_after, previous_status) and logs actor + time + name snapshot in `project_status_transitions` and the
     * audit log, in one transaction.
     *
     * @param  array<string,mixed>  $context  stored on the log row (e.g. {"unlink_accounts": true})
     *
     * @throws InvalidLifecycleTransition
     */
    public function transition(Project $project, ProjectStatus $to, ?User $actor, ?string $reason = null, array $context = []): TransitionRecord;

    /**
     * The ONE function that changes `client_workspaces.client_status` (rule 4: never read by aggregation).
     *
     * @param  array<string,mixed>  $context
     *
     * @throws InvalidLifecycleTransition
     */
    public function transitionClient(ClientWorkspace $client, ClientStatus $to, ?User $actor, ?string $reason = null, array $context = []): TransitionRecord;

    /**
     * Archive (§3): transition to `archived` and apply the archive effects, recording each one so that
     * {@see self::unarchive()} restores it exactly.
     *
     * @return array{transition: TransitionRecord, effects: array<string,int>, shared_accounts: list<array<string,mixed>>}
     */
    public function archive(Project $project, ?User $actor, bool $unlinkAccounts = false, ?string $reason = null): array;

    /**
     * Un-archive: back to the state recorded before the archive, every recorded effect reversed. Requires the
     * caller's explicit confirmation.
     *
     * @return array{transition: TransitionRecord, restored: array<string,int>, skipped: array<string,int>}
     *
     * @throws InvalidLifecycleTransition
     */
    public function unarchive(Project $project, ?User $actor, bool $confirmed): array;

    /**
     * What archiving would stop, and which accounts are shared with another project (warning data). Read-only.
     *
     * @return array<string,mixed>
     */
    public function archiveImpact(Project $project): array;

    /**
     * @return list<array<string,mixed>> newest first
     */
    public function history(Project $project): array;
}
