<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Services\ProposalService;
use App\Domains\Agents\Support\ProposalAction;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AGENTS-APPROVAL-GATEWAY-001 — the approvals queue, over HTTP.
 *
 * Every route is project-scoped and answers to a project capability (the coverage test requires it);
 * the controller adds the tenant permission each decision needs. Deciding is `ProposalService`'s job —
 * this class never touches a provider and never sets a status itself.
 */
final class ActionProposalController extends Controller
{
    public function __construct(private readonly ProposalService $service) {}

    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        $status = $request->query('status');

        $rows = ActionProposal::query()
            ->where('project_id', $project)
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->latest()
            ->limit(200)
            ->get();

        return ApiResponse::success([
            'proposals' => $rows->map(fn (ActionProposal $p): array => $this->shape($p))->values()->all(),
            'limits' => app(ProposalRules::class)->limitsFor(Project::query()->findOrFail($project)),
        ], 'Proposals.');
    }

    /** A person may put a change forward too; it waits for somebody else to approve it. */
    public function store(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use'), 403);

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'external_account_id' => ['required', 'uuid'],
            'target_type' => ['required', Rule::in(['campaign', 'ad_set', 'ad'])],
            'target_external_id' => ['required', 'string', 'max:120'],
            'action' => ['required', Rule::in(ProposalAction::values())],
            'payload' => ['nullable', 'array'],
            'payload.daily_budget' => ['nullable', 'numeric'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $proposal = $this->service->propose(Project::query()->findOrFail($project), 'person', $request->user(), $data);

        return ApiResponse::success($this->shape($proposal), 'Proposal recorded.', status: 201);
    }

    public function approve(Request $request, string $project, string $proposal): JsonResponse
    {
        $row = $this->find($project, $proposal);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->shape($this->service->approve($row, $request->user(), $data['note'] ?? null)), 'Decision recorded.');
    }

    public function reject(Request $request, string $project, string $proposal): JsonResponse
    {
        $row = $this->find($project, $proposal);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->shape($this->service->reject($row, $request->user(), $data['note'] ?? null)), 'Decision recorded.');
    }

    private function find(string $project, string $proposal): ActionProposal
    {
        return ActionProposal::query()->where('project_id', $project)->findOrFail($proposal);
    }

    /** @return array<string, mixed> */
    private function shape(ActionProposal $p): array
    {
        return [
            'id' => (string) $p->getKey(),
            'agent' => $p->agent_key,
            'provider' => $p->provider,
            'external_account_id' => $p->external_account_id,
            'target_type' => $p->target_type,
            'target_external_id' => $p->target_external_id,
            'action' => $p->action,
            'payload' => $p->payload,
            'currency' => $p->currency,
            'reason' => $p->reason,
            'status' => $p->status,
            'violations' => $p->violations ?? [],
            'decided_at' => $p->decided_at?->toIso8601String(),
            'decision_note' => $p->decision_note,
            'expires_at' => $p->expires_at?->toIso8601String(),
            'executed_at' => $p->executed_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'log' => $p->relationLoaded('log') ? $p->log->map(fn ($l): array => ['phase' => $l->phase, 'note' => $l->note, 'at' => $l->created_at?->toIso8601String()])->all() : null,
        ];
    }
}
