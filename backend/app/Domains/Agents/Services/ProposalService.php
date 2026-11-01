<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Agents\Contracts\ProposalExecutor;
use App\Domains\Agents\Models\ActionLog;
use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Support\ProposalAction;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * AGENTS-APPROVAL-GATEWAY-001 — the only door to an advertising change.
 *
 * An agent (or a person) PROPOSES. A person with the right permission APPROVES or REJECTS. Only an
 * approved proposal reaches an executor, and only after every rule has been re-read against the world as
 * it is now. No code path in this product writes to an ad platform from anywhere else, and none is added
 * by a model's output: the vocabulary is closed (`ProposalAction`), the arguments are validated here, and
 * what a model «decides» outside them becomes a stored, blocked proposal.
 */
final class ProposalService
{
    public function __construct(
        private readonly ProposalRules $rules,
        private readonly AuditLogger $audit,
        private readonly ProjectAbilities $abilities,
    ) {}

    /**
     * Record a request. A duplicate of an open one is that same request, returned as it is; a request
     * that breaks a rule is stored `blocked` with its reasons so the attempt is visible, never queued.
     *
     * @param  array<string, mixed>  $input  provider, external_account_id, target_type, target_external_id, action, payload, reason, evidence
     */
    public function propose(Project $project, string $agentKey, ?User $by, array $input): ActionProposal
    {
        $payload = $this->cleanPayload((array) ($input['payload'] ?? []));
        $key = $this->idempotencyKey($project, $input, $payload);

        $existing = ActionProposal::withoutGlobalScopes()
            ->where('tenant_id', $project->tenant_id)
            ->where('idempotency_key', $key)
            ->whereIn('status', ActionProposal::OPEN)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $proposal = new ActionProposal;
        $proposal->forceFill([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->getKey(),
            'client_workspace_id' => $project->client_workspace_id,
            'agent_key' => $agentKey,
            'provider' => (string) ($input['provider'] ?? ''),
            'external_account_id' => (string) ($input['external_account_id'] ?? ''),
            'target_type' => (string) ($input['target_type'] ?? ''),
            'target_external_id' => (string) ($input['target_external_id'] ?? ''),
            'action' => (string) ($input['action'] ?? ''),
            'payload' => $payload === [] ? null : $payload,
            'currency' => $this->currencyOf($project, (string) ($input['external_account_id'] ?? '')),
            'reason' => (string) ($input['reason'] ?? ''),
            'evidence' => isset($input['evidence']) && is_array($input['evidence']) ? $input['evidence'] : null,
            'status' => ActionProposal::PENDING,
            'idempotency_key' => $key,
            'requested_by' => $by?->getKey(),
            'expires_at' => Carbon::now()->addHours((int) config('agents.proposal_ttl_hours', 72)),
        ])->save();

        $violations = $this->rules->violations($proposal);

        if ($violations !== []) {
            $proposal->forceFill(['status' => ActionProposal::BLOCKED, 'violations' => $violations])->save();
            $this->record($proposal, 'blocked', $by, note: implode(', ', $violations));
        } else {
            $this->record($proposal, 'proposed', $by, note: $proposal->reason);
            if ($proposal->action === 'set_daily_budget') {
                // What the budget was when asked, kept so the ladder can tell a decrease from a raise later.
                $proposal->forceFill(['evidence' => [...(array) $proposal->evidence, 'current_daily_budget' => $this->rules->currentDailyBudget($proposal)]])->save();
            }
        }

        $this->audit->log(
            action: 'agents.proposal.'.($violations === [] ? 'created' : 'blocked'),
            entityType: ActionProposal::class,
            entityId: (string) $proposal->getKey(),
            after: ['agent' => $agentKey, 'action' => $proposal->action, 'status' => $proposal->status],
        );

        // AUTO-RULES-001 — a rule in «recommend» mode asks for a person whatever the ladder allows.
        if ($proposal->status === ActionProposal::PENDING && $by === null && ($input['allow_auto'] ?? true) === true) {
            return $this->runIfGranted($proposal);
        }

        return $proposal;
    }

    /**
     * AGENTS-AUTONOMY-001 — an AGENT's proposal in a class an owner granted, on a project whose record is
     * still eligible, is approved in that owner's name with a note saying so; everything else waits. The
     * owner must still hold the permissions an approval needs at that moment.
     */
    private function runIfGranted(ActionProposal $proposal): ActionProposal
    {
        $ladder = app(TrustLadder::class);
        $class = $ladder->classOf($proposal);
        $project = Project::withoutGlobalScopes()->find($proposal->project_id);
        if ($class === null || $project === null) {
            return $proposal;
        }

        $grant = $ladder->activeGrant($project, $class);
        $owner = $grant !== null ? User::query()->find($grant->granted_by) : null;
        if ($grant === null || $owner === null || ! $ladder->measure($project, $class)['eligible']) {
            return $proposal;
        }

        try {
            return $this->approve($proposal, $owner, 'auto: trust ladder');
        } catch (HttpException) {
            // The owner no longer holds the permission, or the proposal moved on: it waits for a person.
            return $proposal->fresh() ?? $proposal;
        }
    }

    /**
     * Approve and carry out. The returned proposal says what happened: `executed`, `failed`, or `blocked`
     * (a rule no longer holds, or no executor is enabled for this provider).
     */
    public function approve(ActionProposal $proposal, User $by, ?string $note = null): ActionProposal
    {
        $this->authorise($proposal, $by);

        // Expiry is recorded OUTSIDE the transaction below: an exception inside it would roll the record back.
        $this->expireIfStale($proposal, $by);

        return DB::transaction(function () use ($proposal, $by, $note): ActionProposal {
            // Locked, so two approvers cannot both carry the same proposal out.
            $row = ActionProposal::withoutGlobalScopes()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if ($row->status !== ActionProposal::PENDING) {
                throw new HttpException(409, 'This proposal is no longer waiting for a decision.');
            }

            // The decision is recorded before anything is attempted, so it survives whatever follows.
            $row->forceFill(['status' => ActionProposal::APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => Carbon::now(), 'decision_note' => $note])->save();
            $this->record($row, 'approved', $by, note: $note);

            // The world may have moved since the proposal was written.
            $violations = $this->rules->violations($row);
            if ($violations !== []) {
                return $this->block($row, $by, $violations);
            }

            $executor = $this->executorFor($row);
            if ($executor === null) {
                return $this->block($row, $by, ['execution_not_enabled_for_provider']);
            }

            $row->forceFill(['status' => ActionProposal::EXECUTING])->save();
            $this->record($row, 'executing', $by);

            try {
                $result = $executor->execute($row);
            } catch (Throwable $e) {
                $row->forceFill(['status' => ActionProposal::FAILED])->save();
                $this->record($row, 'failed', $by, response: ['error' => mb_substr($e->getMessage(), 0, 500)]);

                return $row;
            }

            $row->forceFill(['status' => ActionProposal::EXECUTED, 'executed_at' => Carbon::now()])->save();
            $this->record($row, 'executed', $by, $result['request'] ?? null, $result['response'] ?? null);
            $this->audit->log(action: 'agents.proposal.executed', entityType: ActionProposal::class, entityId: (string) $row->getKey(), after: ['action' => $row->action]);

            return $row;
        });
    }

    private function expireIfStale(ActionProposal $proposal, User $by): void
    {
        $fresh = ActionProposal::withoutGlobalScopes()->find($proposal->getKey());

        if ($fresh !== null && $fresh->status === ActionProposal::PENDING && $fresh->expires_at !== null && $fresh->expires_at->isPast()) {
            $fresh->forceFill(['status' => ActionProposal::EXPIRED])->save();
            $this->record($fresh, 'expired', $by);

            throw new HttpException(409, 'This proposal expired before it was approved.');
        }
    }

    public function reject(ActionProposal $proposal, User $by, ?string $note = null): ActionProposal
    {
        $this->authorise($proposal, $by, forReject: true);

        if ($proposal->status !== ActionProposal::PENDING) {
            throw new HttpException(409, 'This proposal is no longer waiting for a decision.');
        }

        $proposal->forceFill(['status' => ActionProposal::REJECTED, 'decided_by' => $by->getKey(), 'decided_at' => Carbon::now(), 'decision_note' => $note])->save();
        $this->record($proposal, 'rejected', $by, note: $note);
        $this->audit->log(action: 'agents.proposal.rejected', entityType: ActionProposal::class, entityId: (string) $proposal->getKey());

        return $proposal;
    }

    /**
     * Who may decide. Two things must both be true — the person can manage this project's campaigns, and
     * holds the tenant permission for THIS kind of change — and nobody approves their own request.
     */
    private function authorise(ActionProposal $proposal, User $by, bool $forReject = false): void
    {
        if (! $this->abilities->allows($by, (string) $proposal->project_id, ProjectCapability::CAMPAIGNS_MANAGE)) {
            throw new HttpException(403, 'You cannot decide proposals for this project.');
        }

        if (! $forReject) {
            $action = ProposalAction::tryFrom($proposal->action);

            if ($action === null || ! $by->hasPermission($action->permission())) {
                throw new HttpException(403, 'You do not hold the permission this change needs.');
            }

            // Raising spend is also a budget decision.
            if ($action === ProposalAction::SetDailyBudget
                && ! $this->abilities->allows($by, (string) $proposal->project_id, ProjectCapability::BUDGET_MANAGE)) {
                throw new HttpException(403, 'You cannot change this project’s budget.');
            }

            if ($proposal->requested_by !== null && (int) $proposal->requested_by === (int) $by->getKey()) {
                throw new HttpException(403, 'A change must be approved by someone other than the person who asked for it.');
            }
        }
    }

    /** @param  list<string>  $violations */
    private function block(ActionProposal $row, User $by, array $violations): ActionProposal
    {
        $row->forceFill(['status' => ActionProposal::BLOCKED, 'violations' => $violations])->save();
        $this->record($row, 'blocked', $by, note: implode(', ', $violations));

        return $row;
    }

    private function executorFor(ActionProposal $proposal): ?ProposalExecutor
    {
        foreach ((array) config('agents.executors', []) as $class) {
            $executor = app($class);

            if ($executor instanceof ProposalExecutor && $executor->supports($proposal)) {
                return $executor;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    private function record(ActionProposal $p, string $phase, ?User $by, ?array $request = null, ?array $response = null, ?string $note = null): void
    {
        ActionLog::withoutGlobalScopes()->create([
            'tenant_id' => $p->tenant_id, 'proposal_id' => $p->getKey(), 'phase' => $phase,
            'actor_id' => $by?->getKey(), 'api_request' => $request, 'api_response' => $response, 'note' => $note,
        ]);
    }

    /**
     * Keep only scalar, expected keys: a model's free-form extras never reach a rule or an executor.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cleanPayload(array $payload): array
    {
        $out = [];
        if (isset($payload['daily_budget']) && is_numeric($payload['daily_budget'])) {
            $out['daily_budget'] = round((float) $payload['daily_budget'], 4);
        }

        return $out;
    }

    /**
     * One request, one key: the same change to the same object by the same amount while it is still
     * open is the same proposal, so a routine that fires twice asks once.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $payload
     */
    private function idempotencyKey(Project $project, array $input, array $payload): string
    {
        ksort($payload);

        return hash('sha256', implode('|', [
            $project->tenant_id, $project->getKey(), $input['provider'] ?? '', $input['external_account_id'] ?? '',
            $input['target_type'] ?? '', $input['target_external_id'] ?? '', $input['action'] ?? '', json_encode($payload),
        ]));
    }

    /**
     * The currency of an account — only one this project actually holds (same tenant, actively bound).
     *
     * Read by id alone this answered for ANY account in the database and the proposal returned it, so a caller who knew a foreign
     * account's id learned that it exists and what it is denominated in. The rules block such a proposal afterwards; the read must not
     * have happened first.
     */
    private function currencyOf(Project $project, string $accountId): ?string
    {
        if ($accountId === '' || ! Str::isUuid($accountId)) {
            return null;
        }

        $currency = DB::table('external_accounts')
            ->where('id', $accountId)
            ->where('tenant_id', $project->tenant_id)
            ->whereExists(fn ($b) => $b->selectRaw('1')->from('project_integration_bindings as pib')
                ->whereColumn('pib.external_account_id', 'external_accounts.id')
                ->where('pib.project_id', $project->getKey())
                ->where('pib.is_active', true))
            ->value('currency');

        return is_string($currency) && $currency !== '' ? $currency : null;
    }
}
