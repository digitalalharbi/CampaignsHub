<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Models\ContentApproval;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\ClientWorkspaces\Services\ContentApprovals;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CLIENT-CONTENT-APPROVAL-001 — the agency side of «بانتظار اعتمادك».
 *
 * `content.view` to read, `content.create` to ask, `content.update` to withdraw — AND reach to THIS client AND,
 * for a project-confined operator, to the project. Nothing here widens who sees a client; it uses the same
 * gates the rest of the client page uses.
 */
final class ContentApprovalsController
{
    public function __construct(private readonly ClientAccess $access, private readonly ContentApprovals $approvals) {}

    /** GET /app/clients/{client}/content-approvals */
    public function index(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        /** @var User $user */
        $user = $request->user();
        $this->access->assert($user, 'content.view', $workspace);
        $projects = $this->access->reachableProjectIds($user, $workspace);

        $rows = ContentApproval::query()->with('creative')
            ->where('client_workspace_id', $workspace->getKey())
            ->when($projects !== null, fn ($q) => $q->whereIn('project_id', $projects))
            ->latest('created_at')->limit(200)->get();

        return response()->json(['data' => ['approvals' => $rows->map(fn (ContentApproval $a) => $this->approvals->agencyShape($a))->values()]]);
    }

    /** POST /app/clients/{client}/content-approvals */
    public function store(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        /** @var User $user */
        $user = $request->user();
        $this->access->assert($user, 'content.create', $workspace);

        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'caption' => ['nullable', 'string', 'max:4000'],
            'creative_id' => ['nullable', 'uuid'],
        ]);

        $this->assertProject($user, $workspace, $data['project_id']);

        try {
            $approval = $this->approvals->create($workspace, $data, $user, $request->ip(), $request->userAgent());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => 'The project or content does not belong to this client.', 'errors' => [$e->getMessage() => ['invalid']]], 422);
        }

        return response()->json(['data' => ['approval' => $this->approvals->agencyShape($approval->load('creative'))]], 201);
    }

    /** POST /app/clients/{client}/content-approvals/{approval}/withdraw */
    public function withdraw(Request $request, string $client, string $approval): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        /** @var User $user */
        $user = $request->user();
        $this->access->assert($user, 'content.update', $workspace);

        /** @var ContentApproval|null $model */
        $model = Str::isUuid($approval)
            ? ContentApproval::query()->where('client_workspace_id', $workspace->getKey())->whereKey($approval)->first()
            : null;
        abort_if($model === null, 404);
        $this->assertProject($user, $workspace, (string) $model->project_id, 404);

        try {
            $this->approvals->withdraw($model, $user, $request->ip(), $request->userAgent());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['approval' => $this->approvals->agencyShape($model->refresh()->load('creative'))]]);
    }

    private function assertProject(User $user, ClientWorkspace $workspace, string $projectId, int $status = 403): void
    {
        $projects = $this->access->reachableProjectIds($user, $workspace);
        abort_if($projects !== null && ! in_array($projectId, $projects, true), $status);
    }
}
