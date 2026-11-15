<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Services\ProposalService;
use App\Domains\Agents\Support\ProposalAction;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Metrics\Services\DisplayCurrencyAdapter;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AGENTS-APPROVAL-GATEWAY-001 — the approvals queue, over HTTP.
 *
 * Every route is project-scoped and answers to a project capability (the coverage test requires it);
 * the controller adds the tenant permission each decision needs. Deciding is `ProposalService`'s job —
 * this class never touches a provider and never sets a status itself.
 */
final class ActionProposalController extends Controller
{
    public function __construct(private readonly ProposalService $service, private readonly DisplayCurrencyAdapter $display) {}

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
        abort_unless($request->user() !== null, 403);

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
        // Asking for a change needs the AI permission (a request from the Agent Center) or the permission the
        // change itself needs (BUDGET-LIMITS-ACTIONS-001: a pause asked from a spend limit). Asking still
        // changes nothing; approving needs another person.
        $user = $request->user();
        abort_unless($user->hasPermission('ai.use') || $user->hasPermission(ProposalAction::from($data['action'])->permission()), 403);

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

    /**
     * POST agent-proposals/bulk — AGENTS-ASSISTANT-001: decide up to 20 at once. Each id is looked up IN THIS PROJECT and decided one
     * by one through the same gateway (rules re-read, permissions checked, executor only after an approval); one refusal never stops
     * the others and every outcome is returned per item. The screen shows each item's exact change before this is called.
     */
    public function bulk(Request $request, string $project): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'ids' => ['required', 'array', 'min:1', 'max:20'],
            'ids.*' => ['uuid', 'distinct'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $rows = ActionProposal::query()->where('project_id', $project)->whereIn('id', $data['ids'])->get()->keyBy(fn (ActionProposal $p): string => (string) $p->getKey());
        $results = [];
        foreach ($data['ids'] as $id) {
            $row = $rows->get($id);
            if ($row === null) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => 'not_found'];

                continue;
            }
            try {
                $decided = $data['decision'] === 'approve'
                    ? $this->service->approve($row, $request->user(), $data['note'] ?? null)
                    : $this->service->reject($row, $request->user(), $data['note'] ?? null);
                $results[] = ['id' => $id, 'ok' => true, 'status' => $decided->status];
            } catch (HttpExceptionInterface $e) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getStatusCode() === 403 ? 'forbidden' : 'not_pending'];
            }
        }

        return ApiResponse::success(['results' => $results], 'Decisions recorded.');
    }

    /** PATCH agent-proposals/{proposal} — edit the amount of a pending daily-budget proposal; it still waits for an approval. */
    public function amend(Request $request, string $project, string $proposal): JsonResponse
    {
        $row = $this->find($project, $proposal);
        $data = $request->validate(['daily_budget' => ['required', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->shape($this->service->amend($row, $request->user(), (float) $data['daily_budget'], $data['note'] ?? null)), 'Proposal edited.');
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
            // The budget when it was asked, for the «before → after» preview; nothing else of the evidence leaves the server.
            'current_daily_budget' => is_numeric($p->evidence['current_daily_budget'] ?? null) ? (float) $p->evidence['current_daily_budget'] : null,
            'currency' => $p->currency,
            // CONS-I-APPROVAL — the same budget figures in the currency the reader reads (the platform's own stay above, untouched).
            'display' => $this->displayBudget($p),
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

    /**
     * A daily-budget request «36 €» is a platform figure; the reader sees it converted, with the original kept beside it.
     * A request with no rate (or no known currency) carries `daily_budget: null` and a reason — never a number in the wrong unit.
     *
     * @return array<string, mixed>|null
     */
    private function displayBudget(ActionProposal $p): ?array
    {
        if ($p->action !== 'set_daily_budget') {
            return null;
        }
        $convert = fn (mixed $v): ?array => is_numeric($v)
            ? $this->display->toDisplay((string) $p->tenant_id, (string) $p->project_id, (float) $v, $p->currency)
            : null;
        $after = $convert($p->payload['daily_budget'] ?? null);
        $before = $convert($p->evidence['current_daily_budget'] ?? null);
        $any = $after ?? $before;

        return [
            'currency' => $any['currency'] ?? $this->display->displayCurrency((string) $p->project_id),
            'daily_budget' => $after['amount'] ?? null,
            'current_daily_budget' => $before['amount'] ?? null,
            'converted' => ($any['converted'] ?? false) === true,
            'source_currency' => $any['source_currency'] ?? null,
            'reason' => $any['reason'] ?? null,
            // FX-APPLY-001 — the FxConversion of each figure (rendered only by formatConversion; withheld → «—» + rates link).
            'conversion' => $after['conversion'] ?? null,
            'current_conversion' => $before['conversion'] ?? null,
            'stale' => ($any['stale'] ?? false) === true,
        ];
    }
}
