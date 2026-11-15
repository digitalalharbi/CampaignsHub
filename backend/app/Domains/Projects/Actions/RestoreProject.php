<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Lifecycle\ArchiveEffects;
use App\Domains\Projects\Lifecycle\InvalidLifecycleTransition;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectStateMachine;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Lifecycle\TransitionRecord;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIFECYCLE-001 §5 (builder N2) — «استعادة» from «المؤرشفة» or «المحذوفات», back to the previous state.
 *
 * - archived → un-archive (every recorded archive effect reversed);
 * - deleted → the state it had when it was deleted (archived, draft, active or paused), reversing ONLY the delete's
 *   effects; when the client was deleted together with it («المشروع والعميل»), the client comes back too.
 *
 * Unlinked accounts are re-linked only when the person confirmed it (`$relinkAccounts`) AND the account is still in the
 * inventory; a shared account gets back only THIS project's link (the effect rows are this project's binding rows).
 */
final class RestoreProject
{
    public function __construct(
        private readonly ProjectLifecycle $lifecycle,
        private readonly ArchiveEffects $effects,
    ) {}

    /**
     * @return array{transition: TransitionRecord, restored: array<string,int>, skipped: array<string,int>, client_restored: bool}
     *
     * @throws InvalidLifecycleTransition
     */
    public function handle(Project $project, ?User $actor, bool $relinkAccounts = true): array
    {
        $state = ProjectStatus::normalise($project->status);
        if ($state === ProjectStatus::Archived) {
            return $this->lifecycle->unarchive($project, $actor, true, $relinkAccounts) + ['client_restored' => false];
        }
        if ($state !== ProjectStatus::Deleted) {
            throw InvalidLifecycleTransition::between($state->value, 'restore');
        }

        return DB::transaction(function () use ($project, $actor, $relinkAccounts): array {
            $client = $this->clientDeletedWithIt($project);
            $clientRestored = false;
            if ($client !== null) {
                $back = ClientStatus::tryFrom((string) $client->previous_status);
                if ($back === null || in_array($back, [ClientStatus::Deleted, ClientStatus::Archived], true)) {
                    $back = ClientStatus::Active;
                }
                $this->lifecycle->transitionClient($client, $back, $actor, 'restored_with_project', ['project_id' => (string) $project->getKey()]);
                $clientRestored = true;
            }

            return $this->lifecycle->restoreFromTrash($project, $actor, true, $relinkAccounts) + ['client_restored' => $clientRestored];
        });
    }

    /**
     * Read-only: what «استعادة» would do.
     *
     * @return array<string,mixed>
     */
    public function impact(Project $project): array
    {
        $state = ProjectStatus::normalise($project->status);
        $deleteTransition = null;
        if ($state === ProjectStatus::Deleted) {
            $deleteTransition = app(ProjectStateMachine::class)->lastTransitionInto((string) $project->getKey(), ProjectStatus::Deleted);
            $target = ProjectStatus::tryFrom((string) $project->previous_status) ?? ProjectStatus::Active;
        } else {
            $target = ProjectStatus::tryFrom((string) $project->previous_status) ?? ProjectStatus::Active;
            if ($target === ProjectStatus::Archived || $target === ProjectStatus::Deleted) {
                $target = ProjectStatus::Active;
            }
        }
        $client = $state === ProjectStatus::Deleted ? $this->clientDeletedWithIt($project) : null;

        return [
            'project' => ['id' => (string) $project->getKey(), 'name' => (string) $project->name, 'status' => $state->value],
            'restores_to' => $target->value,
            'relink_accounts' => $this->effects->relinkCandidates($project, $deleteTransition),
            'client_restored' => $client !== null,
            'client' => $client === null ? null : ['id' => (string) $client->getKey(), 'name' => (string) $client->name],
            'purge_after' => optional($project->purge_after)->toIso8601String(),
        ];
    }

    /** The client, when it was soft-deleted together with THIS project («المشروع والعميل»). */
    private function clientDeletedWithIt(Project $project): ?ClientWorkspace
    {
        $client = ClientWorkspace::withoutGlobalScopes()->withTrashed()
            ->where('tenant_id', $project->tenant_id)->whereKey($project->client_workspace_id)->first();
        if ($client === null || ($client->client_status !== ClientStatus::Deleted->value && $client->deleted_at === null)) {
            return null;
        }
        $last = DB::table('client_status_transitions')->where('client_workspace_id', $client->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->first(['to_status', 'reason', 'context']);
        $context = $last?->context === null ? [] : (array) json_decode((string) $last->context, true);

        return $last !== null && $last->to_status === ClientStatus::Deleted->value && $last->reason === 'deleted_with_last_project'
            && ($context['project_id'] ?? null) === (string) $project->getKey()
            ? $client : null;
    }
}
