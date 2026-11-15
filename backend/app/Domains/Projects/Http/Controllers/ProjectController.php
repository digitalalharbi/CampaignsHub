<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\CanonicalWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Projects\Access\ProjectReach;
use App\Domains\Projects\Actions\DeleteProject;
use App\Domains\Projects\Actions\RestoreProject;
use App\Domains\Projects\Lifecycle\InvalidLifecycleTransition;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectMover;
use App\Domains\Projects\Lifecycle\ProjectPurger;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Resources\ProjectResource;
use App\Domains\Projects\Services\ProjectDeletionImpact;
use App\Domains\Projects\Services\ProjectListSummary;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\UserInCurrentTenant;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectController extends Controller
{
    /**
     * What a create may start in, and what PATCH `status` may ask for (legacy values are read as their canonical state).
     * `archived`/`deleted` are reached only through their own endpoints (archive effects, confirmation).
     */
    private const STATUSES = ['draft', 'active', 'paused', 'onboarding', 'completed'];

    public function index(Request $request, ProjectListSummary $summary): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('projects.view'), 403);

        /*
         * PROJECT-LIFECYCLE-001 §4 (N2) — «المحذوفات»: `status=deleted` (alias `trash`) lists the soft-deleted projects not
         * yet permanently deleted, newest deletion first. Every other list keeps excluding them (SoftDeletes scope).
         */
        $trash = in_array($request->string('status')->toString(), ['deleted', 'trash'], true);
        $query = $trash
            ? Project::onlyTrashed()->inLifecycle(ProjectStatus::Deleted)->whereNull('purged_at')->orderByDesc('deleted_at')
            : Project::query()->latest();

        if (($reachable = $this->reachable($user)) !== null) {
            $query->whereIn('id', $reachable);
        }
        if ($workspace = $request->string('client_workspace_id')->toString()) {
            $query->where('client_workspace_id', $workspace);
        }
        /*
         * PROJECT-LIFECYCLE-001 — the management list: draft + active + paused by default (LiveProjects::LISTED);
         * `include_archived` adds the archived ones («المؤرشفة»); `status=archived` lists only those. Deleted projects
         * are soft-deleted and never listed here (the trash is its own list).
         */
        if ($trash) {
            // already constrained above
        } elseif ($status = $request->string('status')->toString()) {
            $query->inLifecycle(ProjectStatus::normalise($status));
        } elseif ($request->boolean('include_archived')) {
            $query->inLifecycle(ProjectStatus::Draft, ProjectStatus::Active, ProjectStatus::Paused, ProjectStatus::Archived);
        } else {
            $query->listed();
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $projects = $query->with(['clientWorkspace' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()->select(['id', 'name'])])->get();

        /*
         * PROJECT-LIST-SURFACE-001 §10 — the facts that make the list worth opening.
         *
         * Attached after the page is fetched, in ONE pass over the whole page rather than four
         * queries per card: an agency's list is every client they run, and the page that suffers
         * from an N+1 is the first one anybody sees.
         */
        $summaries = $summary->for($projects);
        $projects->each(function (Project $project) use ($summaries): void {
            $project->setAttribute('list_summary', $summaries[(string) $project->getKey()] ?? null);
        });

        return ApiResponse::success(ProjectResource::collection($projects), 'Projects retrieved.');
    }

    public function show(Request $request, string $project): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('projects.view'), 403);
        $model = $this->find($project);

        /*
         * TEAM-PROJECT-RBAC-001 — the same narrowing the LISTING applies, on the route that names one.
         *
         * `index` has filtered to the reader's own projects since it was written, and this asked only
         * for the tenant permission and then a tenant-scoped `find()`. So a client viewer confined to
         * one project could read the neighbouring client's record by putting its id in the URL — its
         * name, its budget window, its status, the workspace it belongs to.
         *
         * That the list hides what this route served is the tell, and the id is not a secret: it is
         * in the address of every project they legitimately open.
         */
        $this->authorizeReach($user, $model);

        /*
         * The status and setup a reader sees are DERIVED from what the project has (`ProjectLifecycle`), and
         * the listing has done so since it was written. This route returned the value stored at creation, so a
         * project read «نشط» on its card and «مسودة» on its own page. The same facts, for the one project;
         * they are not added to the answer — `summary` stays the listing's.
         */
        $model->setAttribute('lifecycle_facts', app(ProjectListSummary::class)->for(new EloquentCollection([$model]))[(string) $model->getKey()] ?? null);

        return ApiResponse::success(new ProjectResource($model), 'Project retrieved.');
    }

    /**
     * PROJECT-CREATE-WORKSPACE-001 — `client_workspace_id` is required of an AGENCY, not of everyone.
     *
     * It used to be required of everyone, which made project creation impossible for an advertiser:
     * a `client_workspaces` row is an agency's client, and somebody running their own campaigns has
     * none. That is what the connection wizard was working around with `workspaces.data?.[0]?.id` —
     * an expression that returns nothing for an advertiser and the WRONG client for an agency, and
     * whose failure reached production as «حدث خطأ غير متوقع.»
     *
     * So the field is optional in the payload and resolved in the domain: an advertiser's canonical
     * container, or — when the answer is genuinely the customer's to give — a validation error
     * naming the field, which the interface can turn into a question instead of a dead end.
     */
    public function store(Request $request, AuditLogger $audit, CanonicalWorkspace $containers): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.create'), 403);

        $validated = $request->validate([
            'client_workspace_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_workspaces', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'name' => ['required', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'account_manager_id' => ['nullable', 'integer', new UserInCurrentTenant],
        ]);

        $workspaceId = $validated['client_workspace_id'] ?? null;

        /*
         * An agency member files a project only under a client they reach (ADR 0002). The permission alone let a
         * manager confined to one client create work inside a sibling's workspace, and so in its portal.
         * Answered like an unknown id, so the existence of a client outside the ceiling is not confirmed.
         */
        if ($workspaceId !== null && app(MembershipContext::class)->isPortal(Portal::Agency)
            && ! app(ClientAccess::class)->canAccessClient($request->user(), ClientWorkspace::query()->findOrFail($workspaceId))) {
            throw ValidationException::withMessages(['client_workspace_id' => [__('validation.exists', ['attribute' => 'client workspace id'])]]);
        }

        if ($workspaceId === null) {
            $tenant = Tenant::withoutGlobalScopes()->findOrFail(app(TenantContext::class)->tenantId());
            $canonical = $containers->ensureFor($tenant);

            // An agency is asked, and told what to answer. Never guessed at — filing a project under
            // whichever client sorted first is how one client's work reaches another client's portal.
            if ($canonical === null) {
                throw ValidationException::withMessages([
                    'client_workspace_id' => [__('validation.client_workspace_required')],
                ]);
            }

            $workspaceId = (string) $canonical->getKey();
        }

        $project = Project::create([
            'client_workspace_id' => $workspaceId,
            'name' => $validated['name'],
            'account_manager_id' => $validated['account_manager_id'] ?? null,
            'status' => ProjectStatus::normalise($validated['status'] ?? 'draft')->value,
            'setup_completion' => 0,
        ]);

        $audit->log(action: 'project.created', entityType: Project::class, entityId: (string) $project->id, after: ['name' => $project->name]);

        return ApiResponse::success(new ProjectResource($project), 'Project created.', status: 201);
    }

    public function update(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.update'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'setup_completion' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'account_manager_id' => ['nullable', 'integer', new UserInCurrentTenant],
        ]);

        $before = $model->only(['name', 'status', 'setup_completion']);

        // PROJECT-LIFECYCLE-001 — a status change is a transition (allowed table, actor, log), never a column write.
        if (array_key_exists('status', $validated)) {
            $to = ProjectStatus::normalise((string) $validated['status']);
            if ($to !== $model->lifecycleStatus()) {
                $this->guarded(fn () => $this->lifecycle()->transition($model, $to, $request->user(), 'update'));
            }
            unset($validated['status']);
        }
        $model->update($validated);
        $audit->log(action: 'project.updated', entityType: Project::class, entityId: (string) $model->id, before: $before, after: $model->only(['name', 'status', 'setup_completion']));

        return ApiResponse::success(new ProjectResource($model), 'Project updated.');
    }

    /** Duplicate a project's scaffold (name + workspace + manager); bindings are NOT copied. */
    public function clone(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.create'), 403);
        $source = $this->find($project);
        $this->authorizeReach($request->user(), $source);

        $copy = Project::create([
            'client_workspace_id' => $source->client_workspace_id,
            'name' => $source->name.' (Copy)',
            'account_manager_id' => $source->account_manager_id,
            'status' => 'draft',
            'setup_completion' => 0,
            'meta' => $source->meta,
        ]);

        $audit->log(action: 'project.cloned', entityType: Project::class, entityId: (string) $copy->id, after: ['source' => $source->id]);

        return ApiResponse::success(new ProjectResource($copy), 'Project cloned.', status: 201);
    }

    /**
     * POST projects/{project}/archive — PROJECT-LIFECYCLE-001 §3. `projects.archive` (workspace owner by default).
     * Body: `{unlink_accounts?: bool, reason?: string}`. Applies the archive effects and answers with what was
     * stopped and which accounts are shared with another project (only THIS project's link stopped).
     */
    public function archive(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.archive'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);
        $data = $request->validate(['unlink_accounts' => ['sometimes', 'boolean'], 'reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        $result = $this->guarded(fn () => $this->lifecycle()->archive($model, $request->user(), (bool) ($data['unlink_accounts'] ?? false), $data['reason'] ?? null));

        return ApiResponse::success(
            (new ProjectResource($model->refresh()))->resolve($request) + ['lifecycle' => [
                'transition' => $result['transition']->toArray(),
                'effects' => $result['effects'],
                'shared_accounts' => $result['shared_accounts'],
            ]],
            'Project archived.',
        );
    }

    /**
     * POST projects/{project}/restore — un-archive, after confirmation (`{confirm: true}`). `projects.restore`.
     * Returns the project to the state it had before the archive and reverses every recorded archive effect.
     */
    public function restore(Request $request, string $project, RestoreProject $restorer): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.restore'), 403);
        $model = $this->findWithTrashed($project);
        $this->authorizeReach($request->user(), $model);
        $data = $request->validate(['confirm' => ['required', 'accepted'], 'relink_accounts' => ['sometimes', 'boolean']]);

        // §5 — from «المؤرشفة» or «المحذوفات»; unlinked accounts re-linked when confirmed (default) and still available.
        $result = $this->guarded(fn () => $restorer->handle($model, $request->user(), (bool) ($data['relink_accounts'] ?? true)));

        return ApiResponse::success(
            (new ProjectResource($model->refresh()))->resolve($request) + ['lifecycle' => [
                'transition' => $result['transition']->toArray(),
                'restored' => $result['restored'],
                'skipped' => $result['skipped'],
                'client_restored' => $result['client_restored'] ?? false,
            ]],
            'Project restored.',
        );
    }

    public function pause(Request $request, string $project): JsonResponse
    {
        return $this->transitionTo($request, $project, ProjectStatus::Paused, 'pause');
    }

    public function resume(Request $request, string $project): JsonResponse
    {
        return $this->transitionTo($request, $project, ProjectStatus::Active, 'resume');
    }

    /** GET projects/{project}/archive-impact — what archiving would stop + the shared-account warning. Read-only. */
    public function archiveImpact(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.archive'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);

        return ApiResponse::success($this->lifecycle()->archiveImpact($model), 'Archive impact.');
    }

    /** GET projects/{project}/lifecycle — the state, its stamps and the transition log (name snapshots). */
    public function lifecycleHistory(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.view'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);

        return ApiResponse::success([
            'status' => $model->lifecycleStatus()->value,
            'archived_at' => optional($model->archived_at)->toIso8601String(),
            'previous_status' => $model->previous_status,
            'purge_after' => optional($model->purge_after)->toIso8601String(),
            'transitions' => $this->lifecycle()->history($model),
        ], 'Project lifecycle.');
    }

    /**
     * GET projects/{project}/deletion-impact — what «حذف المشروع» will reach, before it reaches it.
     *
     * PROJECT-DELETE-001 §7. A read, and the only reason the dialog can say anything more useful
     * than «Are you sure?». It is served from the same measurement the deletion itself records, so
     * the numbers a person agreed to are the numbers the audit trail keeps.
     */
    public function deletionImpact(Request $request, string $project, ProjectDeletionImpact $impact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.delete'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);

        return ApiResponse::success($impact->for($model), 'Deletion impact.');
    }

    /**
     * DELETE projects/{project} — the destructive action, separate from «أرشفة».
     *
     * PROJECT-DELETE-001. Three guards before anything is written, and each one is a different way
     * this has gone wrong in products that shipped it carelessly:
     *
     *  - `projects.delete`, not `projects.update`. The catalogue already separated renaming a client
     *    from destroying one; nothing read the second permission until now.
     *  - reach. A member confined to one client may hold the permission for THEIR project and must
     *    not be able to spend it on the neighbouring one with a UUID in hand.
     *  - the typed name, checked HERE. A confirmation the browser enforces is a confirmation an API
     *    call skips, and this is the request that cannot be taken back.
     */
    public function destroy(Request $request, string $project, DeleteProject $delete): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.delete'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);

        $request->validate([
            'confirm_name' => ['required', 'string'],
            // §4 — when the project is the client's only one: «المشروع فقط» (default) or «المشروع والعميل».
            'client_action' => ['sometimes', 'string', Rule::in([DeleteProject::PROJECT_ONLY, DeleteProject::PROJECT_AND_CLIENT])],
            'confirm_client_name' => ['sometimes', 'nullable', 'string'],
        ]);

        /*
         * Compared after trimming and only after trimming.
         *
         * Not case-folded and not normalised further: the field exists to prove the person read the
         * name of the thing they are destroying, and «close enough» is the property it must not
         * have. Trailing whitespace from a copy-paste is not a failure of attention.
         */
        if (trim((string) $request->input('confirm_name')) !== trim((string) $model->name)) {
            throw ValidationException::withMessages([
                'confirm_name' => [__('Type the project name exactly to confirm deletion.')],
            ]);
        }

        $impact = $delete->handle(
            $model,
            $request->user()?->id,
            (string) $request->input('client_action', DeleteProject::PROJECT_ONLY),
            $request->input('confirm_client_name'),
        );

        return ApiResponse::success($impact, 'Project deleted.');
    }

    /**
     * GET projects/{project}/restore-impact — what restoring would bring back (§5): the state it returns to, the accounts
     * a restore would re-link and whether each is still available, and whether the client comes back with it. Read-only.
     */
    public function restoreImpact(Request $request, string $project, RestoreProject $restorer): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.restore'), 403);
        $model = $this->findWithTrashed($project);
        $this->authorizeReach($request->user(), $model);

        return ApiResponse::success($restorer->impact($model), 'Restore impact.');
    }

    /**
     * POST projects/{project}/purge — «حذف نهائي» from «المحذوفات». `projects.purge` + the typed project name. Refused
     * unless the project is in the trash. Keeps audit + notice logs (name snapshot); never calls an ad platform.
     */
    public function purge(Request $request, string $project, ProjectPurger $purger): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.purge'), 403);
        $model = $this->findWithTrashed($project);
        $this->authorizeReach($request->user(), $model);
        $request->validate(['confirm_name' => ['required', 'string']]);
        if (trim((string) $request->input('confirm_name')) !== trim((string) $model->name)) {
            throw ValidationException::withMessages(['confirm_name' => [__('Type the project name exactly to confirm deletion.')]]);
        }

        $counts = $this->guarded(fn () => $purger->purge($model, $request->user(), false));

        return ApiResponse::success(['id' => (string) $model->getKey(), 'name' => (string) $model->name, 'purged' => true, 'counts' => array_filter($counts), 'ad_platforms_untouched' => true], 'Project permanently deleted.');
    }

    /** GET projects/{project}/move-impact?client_workspace_id= — what «نقل لعميل آخر» re-points. `projects.move`. Read-only. */
    public function moveImpact(Request $request, string $project, ProjectMover $mover): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.move'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);
        $target = $request->string('client_workspace_id')->toString();

        return ApiResponse::success($mover->impact($model, $target === '' ? null : $target), 'Move impact.');
    }

    /**
     * POST projects/{project}/move — «نقل المشروع لعميل آخر» (§5). `projects.move` (workspace owner by default) + the typed
     * project name. Inside the SAME workspace only: a client of another workspace is refused server-side (422).
     */
    public function move(Request $request, string $project, ProjectMover $mover): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.move'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);
        $data = $request->validate(['client_workspace_id' => ['required', 'uuid'], 'confirm_name' => ['required', 'string']]);
        if (trim((string) $data['confirm_name']) !== trim((string) $model->name)) {
            throw ValidationException::withMessages(['confirm_name' => [__('Type the project name exactly to confirm the move.')]]);
        }
        if (($why = $mover->refusal($model, (string) $data['client_workspace_id'])) !== null) {
            throw ValidationException::withMessages(['client_workspace_id' => [match ($why) {
                'same_client' => __('The project already belongs to this client.'),
                'target_not_active' => __('The target client is archived or deleted.'),
                'project_deleted' => __('A deleted project cannot be moved.'),
                default => __('The client is not in this workspace.'),
            }]]);
        }

        $counts = $this->guarded(fn () => $mover->move($model, (string) $data['client_workspace_id'], $request->user()));

        return ApiResponse::success((new ProjectResource($model->refresh()))->resolve($request) + ['moved' => array_filter($counts)], 'Project moved.');
    }

    /** Pause / resume — `projects.update`, through the one transition function (which logs and audits). */
    private function transitionTo(Request $request, string $project, ProjectStatus $to, string $reason): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.update'), 403);
        $model = $this->find($project);
        $this->authorizeReach($request->user(), $model);
        $this->guarded(fn () => $this->lifecycle()->transition($model, $to, $request->user(), $reason));

        return ApiResponse::success(new ProjectResource($model->refresh()), 'Project status updated.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function guarded(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidLifecycleTransition $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }
    }

    private function lifecycle(): ProjectLifecycle
    {
        return app(ProjectLifecycle::class);
    }

    /**
     * TEAM-PROJECT-RBAC-001 — may this reader reach THIS project at all.
     *
     * ## Why it is a method and not two lines in `show`
     *
     * It WAS two lines in `show`, and that is exactly how the writes went without it. `show` learned
     * that a member confined to one client could read the neighbouring client's record by putting its
     * id in the URL, and the narrowing was added where the lesson was learned. `update`, `clone` and
     * the four state transitions kept checking only the TENANT permission and then looking the
     * project up with a tenant-scoped `find()` — which finds every project in the agency by
     * definition.
     *
     * So the same member could pause, archive, restore, rename or copy another client's project. One
     * rung more serious than the read it was fixed for: a neighbour's project name is a disclosure,
     * and pausing their campaigns stops their advertising.
     *
     * ## Why the tenant permission is not the scope
     *
     * `projects.update` answers «may this person run clients at all», which is the sentence
     * `ProjectRouteCapabilityCoverageTest` uses to justify exempting these routes from a project
     * capability. That sentence is true and it is not a scope: an account manager running one client
     * holds it, and nothing about holding it says which client. `projects.view.all` is the permission
     * that means «every project in this agency», and {@see self::reachable()} already reads it — so
     * an agency-wide reader is not narrowed and nobody who could act before loses anything they were
     * entitled to.
     *
     * 403 and not 404: «ask for access» is the true answer, and telling a colleague the project does
     * not exist sends them looking for a bug instead.
     */
    private function authorizeReach(User $user, Project $model): void
    {
        $reachable = $this->reachable($user);

        abort_unless(
            $reachable === null || in_array((string) $model->id, $reachable, true),
            403,
            'You do not have access to this project.',
        );
    }

    /**
     * The projects this reader may reach, or NULL for «every project in the tenant».
     *
     * Agency-wide viewers hold `projects.view.all` and are not narrowed — that permission is what
     * lets an operator open any client in their own workspace. Everybody else reaches the projects
     * they are an ACTIVE member of, which is the same rule `ResolveProject` applies before the
     * project-context routes and the same one the listing has always applied.
     *
     * Null rather than an all-ids list deliberately: an agency with three hundred projects should
     * not pay for a query that answers «no narrowing», and a caller reading `null` cannot mistake it
     * for «reaches nothing», which an empty array would say.
     *
     * @return list<string>|null
     */
    private function reachable(User $user): ?array
    {
        return app(ProjectReach::class)->idsFor($user);
    }

    /** Tenant-scoped lookup that also finds a project in «المحذوفات» (never a purged tombstone). */
    private function findWithTrashed(string $id): Project
    {
        $model = Project::withTrashed()->whereNull('purged_at')->find($id);
        abort_if($model === null, 404, 'Project not found.');

        return $model;
    }

    /** Tenant-scoped lookup (global TenantScope makes cross-tenant fail-closed → 404). */
    private function find(string $id): Project
    {
        $model = Project::find($id);
        abort_if($model === null, 404, 'Project not found.');

        return $model;
    }
}
