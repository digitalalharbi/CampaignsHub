<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Lifecycle\LifecycleNotices;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectDeletionImpact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PROJECT-DELETE-001 §8 §9 — deleting a project without reaching past it.
 *
 * ## The whole risk is over-reach
 *
 * A project sits in the middle of a chain whose upper rungs are SHARED: one Snapchat authorisation
 * feeds several projects, and one organisation's accounts are split between clients. So the danger
 * in this action is never «did it delete enough» — it is «what else did it touch». Three things are
 * therefore explicitly NOT done here, and each of them is a thing a naive cascade would do:
 *
 *  - the provider connection is not revoked. It is not this project's to revoke; another client is
 *    advertising through it right now.
 *  - the external account is not deleted, nor its discovery. It belongs to the tenant's inventory
 *    and to the advertising platform, and nothing in CampaignsHub owns it.
 *  - a neighbouring project's bindings, reports, links and schedules are not read, let alone
 *    written. Every statement below is filtered by THIS project.
 *
 * ## What it does do, and why these three first
 *
 * The project row is soft-deleted, which is what removes it from every surface at once: the model's
 * `SoftDeletes` scope stacks under the tenant scope, so no list, no lookup and no project-scoped
 * route can reach it from the next request onwards. History is kept rather than destroyed — a client
 * that leaves is still a year of spend the agency is required to be able to account for.
 *
 * But soft-deleting the row does NOT close the paths that live outside the interface, and those are
 * the ones that make a deletion untrue:
 *
 *  - a client-facing LIVE link answers from a token, not from a session, and would keep serving.
 *  - a schedule fires from the queue and would email the client next Sunday, out of anybody's sight.
 *  - a binding is what makes an account's data arrive; left active, the deleted project keeps being
 *    synced into.
 *  - a published lead form answers from a slug and keeps creating leads, and a live conversion destination keeps
 *    reporting them (FORMS-PDPL-001).
 *
 * All of these are closed in the SAME transaction as the delete, because a deletion that half-happened
 * is worse than one that failed: the interface would say the project is gone while the link still
 * opens.
 *
 * ## Why the bindings are deactivated and not destroyed
 *
 * `is_active = false` is the product's existing vocabulary for «this account no longer feeds this
 * project», and it is what every sync path already reads. Destroying the row instead would lose the
 * fact that the account WAS bound, which is exactly the fact an audit of last quarter's spend needs.
 *
 * ## PROJECT-LIFECYCLE-001 §4 (builder N2) — the soft delete is ONE lifecycle call
 *
 * Every step above is now an archive effect recorded by {@see ProjectLifecycle::softDelete()} (bindings unlinked, links
 * revoked, schedules/rules/routines stopped, tasks and alerts closed, forms unpublished, destinations off), so restoring
 * from «المحذوفات» reverses exactly this delete. Permanent deletion is `ProjectPurger` (after the retention, or by hand
 * with `projects.purge`).
 *
 * The client (§4): `project_only` (default) never touches the client. `project_and_client` is accepted ONLY when this is
 * the client's last project that is not already deleted AND the client's name is typed — enforced here, not in the UI;
 * the client is then soft-deleted through `transitionClient()` (restorable; never purged by the automatic job). The
 * hidden default client of a workspace without a client concept is never deleted.
 */
final class DeleteProject
{
    public const PROJECT_ONLY = 'project_only';

    public const PROJECT_AND_CLIENT = 'project_and_client';

    public function __construct(
        private readonly ProjectDeletionImpact $impact,
        private readonly AuditLogger $audit,
        private readonly ProjectLifecycle $lifecycle,
        private readonly LifecycleNotices $notices,
    ) {}

    /**
     * @return array<string,mixed> the impact, as measured immediately before the deletion, plus what the delete did
     *
     * @throws ValidationException
     */
    public function handle(Project $project, ?int $actorId, string $clientAction = self::PROJECT_ONLY, ?string $confirmClientName = null): array
    {
        $projectId = (string) $project->getKey();
        $actor = $actorId === null ? null : User::find($actorId);

        // Measured BEFORE, so the audit record says what was actually there rather than what is left.
        $impact = $this->impact->for($project);
        $client = $this->clientToDelete($project, $clientAction, $confirmClientName);

        $result = DB::transaction(function () use ($project, $actor, $client, $clientAction): array {
            $result = $this->lifecycle->softDelete($project, $actor, 'delete', ['client_action' => $clientAction]);
            if ($client !== null) {
                $this->lifecycle->transitionClient($client, ClientStatus::Deleted, $actor, 'deleted_with_last_project', ['project_id' => (string) $project->getKey()]);
            }

            return $result;
        });

        /*
         * The audit keeps the ACTION and its shape, never a copy of the client's figures.
         *
         * «Preserve required security evidence» and «do not keep an unauthorised historical copy»
         * are the same instruction read from two sides: who deleted what, when, and how much of it
         * there was — and nothing anybody could reconstruct a client's performance from.
         */
        $this->audit->log(
            action: 'project.deleted',
            entityType: Project::class,
            entityId: $projectId,
            before: [
                'name' => $impact['project']['name'],
                'client' => $impact['project']['client'],
                'status' => $impact['project']['status'],
                'counts' => $impact['counts'],
            ],
            after: ['client_action' => $clientAction, 'client_deleted' => $client !== null, 'purge_after' => optional($project->purge_after)->toIso8601String()],
            userId: $actorId,
        );

        $this->notices->deleted($project, $actor);

        return $impact + [
            'client_deleted' => $client !== null,
            'purge_after' => optional($project->purge_after)->toIso8601String(),
            'lifecycle' => [
                'transition' => $result['transition']->toArray(),
                'effects' => $result['effects'],
                'shared_accounts' => $result['shared_accounts'],
            ],
        ];
    }

    /**
     * The other projects of this project's client that are not already in the trash. While there is one, only the
     * project can be deleted — the client and its other projects are untouched (§4, server-enforced).
     */
    public static function otherProjectsOfClient(Project $project): int
    {
        return Project::withoutGlobalScopes()
            ->where('tenant_id', $project->tenant_id)
            ->where('client_workspace_id', $project->client_workspace_id)
            ->where('id', '!=', $project->getKey())
            ->whereNull('deleted_at')
            ->count();
    }

    /** @throws ValidationException */
    private function clientToDelete(Project $project, string $clientAction, ?string $confirmClientName): ?ClientWorkspace
    {
        if ($clientAction === self::PROJECT_ONLY) {
            return null;
        }
        if ($clientAction !== self::PROJECT_AND_CLIENT) {
            throw ValidationException::withMessages(['client_action' => [__('Choose «project only» or «project and client».')]]);
        }
        $client = ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $project->tenant_id)->whereKey($project->client_workspace_id)->first();
        if ($client === null || (bool) $client->is_canonical) {
            throw ValidationException::withMessages(['client_action' => [__('This workspace’s default client cannot be deleted.')]]);
        }
        if (self::otherProjectsOfClient($project) > 0) {
            throw ValidationException::withMessages(['client_action' => [__('The client has other projects; only the project can be deleted.')]]);
        }
        if (trim((string) $confirmClientName) !== trim((string) $client->name)) {
            throw ValidationException::withMessages(['confirm_client_name' => [__('Type the client name exactly to delete the client too.')]]);
        }

        return $client;
    }
}
