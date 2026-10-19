<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use App\Domains\Agents\Models\ClientAgentRule;
use App\Domains\Agents\Services\AgentReadTools;
use App\Domains\Agents\Services\ProposalService;
use App\Domains\Agents\Support\ProposalAction;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * AGENTS-ENGINE-001 — the tools an agent may call, and the ONLY code that runs when it calls one.
 *
 * The project is never taken from the model: it is the thread's, passed in by the engine, so a tool call
 * cannot reach another project however its arguments are written. Arguments are validated here; a call
 * that fails validation returns an error result the model can read, and runs nothing.
 */
final class AgentToolbox
{
    public function __construct(
        private readonly AgentReadTools $read,
        private readonly ProposalService $proposals,
    ) {}

    /**
     * @param  list<string>  $names
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function specs(array $names): array
    {
        $all = [
            'list_campaigns' => ['Campaigns of this project over the last N days, with spend, results, cost per result, ROAS and the previous period.', ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90]], []],
            'get_history' => ['Daily figures of one campaign over the last N days.', ['campaign_id' => ['type' => 'string'], 'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90]], ['campaign_id']],
            'compare_targets' => ['Every campaign judged against the client\'s own targets (on_target, off_target, not_judged, no_figure).', ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90]], []],
            'list_creatives' => ['The project\'s ads as the content library shows them, most-spent first, with each ad\'s copy, figures and fatigue verdict (fatigued, watch, stable, insufficient_data).', ['days' => ['type' => 'integer', 'minimum' => 7, 'maximum' => 90]], []],
            'get_rules' => ['The client\'s goals, decision rules, special days, forbidden words and the approval limits.', [], []],
            'propose_change' => ['Put forward ONE change for a person to approve: pause, resume or set_daily_budget on a campaign or ad set. It does not change anything by itself.', [
                'provider' => ['type' => 'string'], 'external_account_id' => ['type' => 'string', 'description' => 'Needed only when the project has more than one account on that platform (see get_rules).'], 'target_type' => ['type' => 'string', 'enum' => ['campaign', 'ad_set']],
                'target_external_id' => ['type' => 'string'], 'action' => ['type' => 'string', 'enum' => ProposalAction::values()],
                'daily_budget' => ['type' => 'number'], 'reason' => ['type' => 'string'],
            ], ['provider', 'target_external_id', 'action', 'reason']],
            'ask_agent' => ['Ask a specialist agent (analyst, campaign, creative, reports) a question and get its answer.', [
                'agent' => ['type' => 'string', 'enum' => ['analyst', 'campaign', 'creative', 'reports']], 'question' => ['type' => 'string'],
            ], ['agent', 'question']],
        ];

        $out = [];
        foreach ($names as $name) {
            if (! isset($all[$name])) {
                continue;
            }
            [$description, $properties, $required] = $all[$name];
            $out[] = ['name' => $name, 'description' => $description, 'input_schema' => [
                'type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false,
            ]];
        }

        return $out;
    }

    /**
     * Run one call. Returns the JSON text handed back to the model and whether it succeeded.
     *
     * @param  list<string>  $allowed  the role's tools — anything else is refused, whatever the model asked
     * @param  array<string, mixed>  $input
     * @param  (callable(string, string): string)|null  $askAgent
     * @return array{content: string, ok: bool}
     */
    public function call(Project $project, ?User $user, string $agentKey, array $allowed, string $name, array $input, ?callable $askAgent = null): array
    {
        if (! in_array($name, $allowed, true)) {
            return $this->error("The tool {$name} is not available to this agent.");
        }
        if (isset($input['_invalid_arguments'])) {
            // A provider returned arguments that were not valid JSON; nothing is run on a guess.
            return $this->error('The arguments of this call were not valid JSON. Call the tool again with valid arguments.');
        }

        try {
            $result = match ($name) {
                'list_campaigns' => $this->read->listCampaigns($project, $this->days($input)),
                'get_history' => $this->history($project, $input),
                'compare_targets' => $this->read->compareTargets($project, $this->days($input)),
                'get_rules' => $this->rules($project),
                'list_creatives' => $this->read->listCreatives($project, max(7, $this->days($input + ['days' => 14]))),
                'propose_change' => $this->propose($project, $user, $agentKey, $input),
                'ask_agent' => ['answer' => $askAgent === null ? 'Delegation is not available here.' : $askAgent((string) ($input['agent'] ?? ''), (string) ($input['question'] ?? ''))],
                default => throw new InvalidArgumentException('Unknown tool.'),
            };
        } catch (HttpExceptionInterface|InvalidArgumentException $e) {
            // Our own refusals are worded for the model; anything else is a fault and stays in the logs.
            return $this->error(mb_substr($e->getMessage(), 0, 300));
        } catch (Throwable $e) {
            report($e);

            return $this->error('The tool failed. Do not retry the same call.');
        }

        return ['content' => (string) json_encode($result, JSON_UNESCAPED_UNICODE), 'ok' => true];
    }

    /** @param array<string, mixed> $input */
    private function days(array $input): int
    {
        return isset($input['days']) && is_numeric($input['days']) ? max(1, min((int) $input['days'], 90)) : 7;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function history(Project $project, array $input): array
    {
        $id = (string) ($input['campaign_id'] ?? '');
        // The campaign must be this project's — checked here, not trusted.
        abort_unless(Str::isUuid($id), 422, 'campaign_id must be an id returned by list_campaigns.');
        abort_unless(UnifiedCampaign::query()->where('project_id', $project->getKey())->whereKey($id)->exists(), 422, 'That campaign is not in this project.');

        return $this->read->getHistory($project, $id, $this->days($input));
    }

    /** @return array<string, mixed> */
    private function rules(Project $project): array
    {
        return [
            'rules' => ClientAgentRule::query()->where('project_id', $project->getKey())->get(['platform', 'kpi', 'direction', 'target_value', 'min_spend_to_judge', 'special_days', 'forbidden_words', 'notes'])->toArray(),
            'limits' => app(ProposalRules::class)->limitsFor($project),
            'accounts' => ExternalAccount::query()
                ->whereIn('id', ProjectIntegrationBinding::query()->where('project_id', $project->getKey())->where('is_active', true)->select('external_account_id'))
                ->get(['id', 'provider', 'name', 'currency'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function propose(Project $project, ?User $user, string $agentKey, array $input): array
    {
        // The AGENT is the requester (no person is), so the person who was talking to it may approve it;
        // who asked the agent is kept as evidence. The self-approval rule is about a person approving their
        // own hand-written request.
        $provider = (string) ($input['provider'] ?? '');
        $account = (string) ($input['external_account_id'] ?? '');
        if ($account === '') {
            // A project with ONE account on that platform needs no id from the model; more than one must be named.
            $bound = ProjectIntegrationBinding::query()->where('project_id', $project->getKey())->where('provider', $provider)
                ->where('is_active', true)->pluck('external_account_id');
            $account = $bound->count() === 1 ? (string) $bound->first() : '';
        }

        $proposal = $this->proposals->propose($project, $agentKey, null, [
            'provider' => $provider,
            'external_account_id' => $account,
            'target_type' => (string) ($input['target_type'] ?? 'campaign'),
            'target_external_id' => (string) ($input['target_external_id'] ?? ''),
            'action' => (string) ($input['action'] ?? ''),
            'payload' => isset($input['daily_budget']) ? ['daily_budget' => $input['daily_budget']] : [],
            'reason' => mb_substr((string) ($input['reason'] ?? ''), 0, 1000),
            'evidence' => ['conversation_user_id' => $user?->getKey()],
        ]);

        return [
            'proposal_id' => (string) $proposal->getKey(),
            'status' => $proposal->status,
            'violations' => $proposal->violations ?? [],
            'note' => $proposal->status === 'pending' ? 'Waiting for a person to approve. Nothing has changed yet.' : 'Stopped by a rule. Nothing has changed.',
        ];
    }

    /** @return array{content: string, ok: bool} */
    private function error(string $message): array
    {
        return ['content' => (string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE), 'ok' => false];
    }
}
