<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationAudience;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Arranging who is told — MAIL-010.
 *
 * ## Two refusals, and they are different refusals
 *
 * **The actor cannot reach the project.** A manager arranging notifications for a client they have no
 * access to would be using this screen to learn that the client exists — a scoped operator must not
 * be able to enumerate the workspace through a settings form. Refused as 403.
 *
 * **The recipient cannot reach the project.** Adding them here would be a request the product will
 * never honour, so it is refused as 422 with the reason stated. This one is a courtesy rather than a
 * control: `NotificationAudience` drops them at send time regardless. Refusing at the moment somebody
 * makes the mistake is worth doing anyway, because the alternative is a manager arranging something,
 * watching nothing happen, and concluding the feature is broken.
 *
 * Neither refusal is the security boundary on its own. The boundary is that a row in this table
 * cannot grant anything, and it is enforced where the mail is sent.
 *
 * ## `settings.manage`, and why `index` needs it too
 *
 * The listing names colleagues and the projects they are attached to. That is org structure, and a
 * read-only version of it is still a disclosure — so the whole controller sits behind the same
 * permission rather than only its writes.
 */
final class NotificationRecipientController extends Controller
{
    /**
     * The categories a manager may arrange somebody into.
     *
     * From `MessageCatalogue` since MAIL-011 rather than a literal — one vocabulary, or the two
     * drift. It gained `content` and folded `sync` and `token` into `integrations`; rows written
     * under the older names keep working, because `NotificationAudience` translates on read rather
     * than a migration rewriting people's stored arrangements.
     */
    private const CATEGORIES = MessageCatalogue::ARRANGEABLE;

    public function __construct(
        private readonly NotificationAudience $audience,
        private readonly DigestScope $scope,
        private readonly TenantContext $tenants,
    ) {}

    /** GET — every arrangement in this workspace, each with whether it is actually doing anything. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = (string) $this->tenants->tenantId();

        $rows = DB::table('notification_recipients')
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique()->all())->get()->keyBy('id');
        // Qualified by client, for the same reason `assignable` qualifies them — see there.
        $projects = Project::query()->where('tenant_id', $tenantId)->with('clientWorkspace:id,name')
            ->get(['id', 'name', 'client_workspace_id'])
            ->mapWithKeys(static fn (Project $p): array => [
                (string) $p->id => $p->clientWorkspace?->name !== null
                    ? $p->clientWorkspace->name.' · '.$p->name
                    : (string) $p->name,
            ]);

        return ApiResponse::success([
            'recipients' => $rows->map(function ($row) use ($users, $projects, $tenantId): array {
                $user = $users->get($row->user_id);

                return [
                    'id' => $row->id,
                    'user_id' => $row->user_id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    'project_id' => $row->project_id,
                    'project_name' => $row->project_id === null ? null : ($projects[$row->project_id] ?? null),
                    'category' => $row->category,
                    /*
                     * Live, per row. A manager who arranged this in March and had the member moved
                     * off the client in April needs to see that the row is inert — the alternative
                     * is a list that looks correct and mails nobody.
                     */
                    'status' => $this->statusOf($user, $tenantId, $row),
                ];
            })->all(),
            'available_categories' => self::CATEGORIES,
        ], 'Notification recipients retrieved.');
    }

    /**
     * GET assignable — who this actor may add, and for which projects.
     *
     * Computed from ceilings rather than from the users table, so the form cannot offer a choice the
     * store endpoint will refuse. Everyone listed here holds an active membership AND reaches at
     * least one project the actor also reaches.
     */
    public function assignable(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = (string) $this->tenants->tenantId();
        $mine = $this->scope->projectIdsFor($request->user(), $tenantId);

        $candidates = User::query()
            ->whereIn('id', DB::table('memberships')
                ->where('tenant_id', $tenantId)->where('status', 'active')->pluck('user_id'))
            ->orderBy('name')
            ->get();

        $people = [];
        foreach ($candidates as $user) {
            $theirs = array_values(array_intersect($mine, $this->scope->projectIdsFor($user, $tenantId)));
            if ($theirs === []) {
                continue;
            }
            $people[] = [
                'user_id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                // Only the overlap. Naming a project the actor cannot reach would leak it.
                'project_ids' => $theirs,
            ];
        }

        return ApiResponse::success([
            'people' => $people,
            /*
             * The client's name travels with the project's.
             *
             * Found by opening the screen: three different clients each have a project called «Q3
             * Launch — Demo», so the picker offered the same words three times and a manager had no
             * way to tell which client they were arranging. A project name is only unique inside its
             * client, and this is a workspace-wide list.
             */
            'projects' => Project::query()->where('tenant_id', $tenantId)->whereIn('id', $mine)
                ->with('clientWorkspace:id,name')->orderBy('name')->get(['id', 'name', 'client_workspace_id'])
                ->map(static fn (Project $p): array => [
                    'id' => (string) $p->id,
                    'name' => (string) $p->name,
                    'client_name' => $p->clientWorkspace?->name,
                ])->all(),
            'available_categories' => self::CATEGORIES,
        ], 'Assignable recipients retrieved.');
    }

    /** POST — arrange for somebody to be told. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = (string) $this->tenants->tenantId();

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'project_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
        ]);

        $projectId = $data['project_id'] ?? null;
        $mine = $this->scope->projectIdsFor($request->user(), $tenantId);

        // The actor's own reach. A scoped operator must not learn a project exists by being told they
        // cannot use it — but 403 here is about the project they NAMED, which they already knew.
        abort_if($projectId !== null && ! in_array($projectId, $mine, true), 403, 'That project is outside your access.');

        $target = User::query()->find($data['user_id']);
        abort_if($target === null, 422, 'Unknown member.');

        $theirs = $this->scope->projectIdsFor($target, $tenantId);
        abort_if($theirs === [], 422, 'That member has no active access in this workspace.');

        if ($projectId !== null && ! in_array($projectId, $theirs, true)) {
            abort(422, 'That member cannot see this project, so they would never receive these messages. Grant them access first.');
        }

        DB::table('notification_recipients')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'user_id' => $target->getKey(),
            'category' => $data['category'] ?? null,
            'created_by' => $request->user()->getKey(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $this->index($request);
    }

    /** DELETE — stop telling them. Removing an arrangement takes nothing away from the person. */
    public function destroy(Request $request, string $recipient): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = (string) $this->tenants->tenantId();

        // Bounded by tenant in the WHERE, not by a lookup and a check — an id from another workspace
        // finds nothing rather than being found and then refused.
        DB::table('notification_recipients')
            ->where('tenant_id', $tenantId)->where('id', $recipient)->delete();

        return $this->index($request);
    }

    /**
     * Whether a row is doing anything today, in words the screen can print.
     *
     * A blanket row (no project) is reported as active whenever the person reaches anything at all —
     * there is no single project to test it against, and reporting it inert because one project is
     * out of reach would be wrong in the other direction.
     */
    private function statusOf(?User $user, string $tenantId, object $row): array
    {
        if ($user === null) {
            return ['eligible' => false, 'reason' => 'no_such_user'];
        }

        if ($row->project_id === null) {
            return $this->scope->projectIdsFor($user, $tenantId) === []
                ? ['eligible' => false, 'reason' => 'outside_their_access']
                : ['eligible' => true, 'reason' => null];
        }

        return $this->audience->explain(
            $user, $tenantId, (string) $row->project_id,
            (string) ($row->category ?? 'reports'),
        );
    }
}
