<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Agents\Engine\AgentToolbox;
use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Models\ClientAgentRule;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Usage\UsageLedger;
use App\Models\User;
use RuntimeException;

/**
 * AGENTS-ENGINE-001 — one turn of a conversation: the model reads, calls tools, and answers.
 *
 * The loop is ours, so every tool call passes through `AgentToolbox` with the thread's project; a model
 * cannot reach a tool its role does not list, and cannot leave the project. The loop stops after a fixed
 * number of tool rounds and says so rather than running on. A refusal from the model is stored and shown
 * as what it is.
 */
final class AgentConversation
{
    public function __construct(
        private readonly ModelClientFactory $models,
        private readonly AgentToolbox $toolbox,
        private readonly UsageLedger $usage,
    ) {}

    /** Run the reply to the latest user message in the thread. Returns the final assistant text. */
    public function reply(AgentThread $thread, ?User $user, int $depth = 0): string
    {
        $project = Project::withoutGlobalScopes()->findOrFail($thread->project_id);
        $role = AgentRoles::get($thread->agent_key);

        if ($role === null || ! $role['enabled']) {
            return $this->say($thread, 'This agent is not available yet: '.($role['why_disabled'] ?? 'unknown_agent').'.', 'system');
        }

        ['client' => $client, 'model' => $model, 'token_limit' => $limit, 'credential_id' => $credentialId] = $this->models->for($project, $thread->agent_key);
        $system = AgentRoles::systemPrompt($thread->agent_key, (string) $project->name, $this->rulesSummary($project));
        $tools = $this->toolbox->specs($role['tools']);

        $rounds = (int) config('agents.max_tool_rounds', 8);
        for ($i = 0; $i <= $rounds; $i++) {
            // The workspace's own monthly ceiling on its key, checked before EVERY call, not once per turn.
            // Measured on THIS key, from the usage ledger — another project's key never spends this one's allowance.
            if ($limit !== null && $this->usage->usedThisMonth((string) $thread->tenant_id, 'ai_tokens_own_key', $credentialId) >= $limit) {
                throw new RuntimeException('ai_token_limit_reached');
            }
            $turn = $client->respond($model, $system, $this->history($thread), $tools);

            $message = AgentMessage::withoutGlobalScopes()->create([
                'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->getKey(), 'role' => 'assistant',
                'content' => $turn->blocks, 'text' => $turn->text === '' ? null : $turn->text,
                'tool_calls' => $turn->toolCalls === [] ? null : array_map(static fn (array $c): array => ['name' => $c['name'], 'input' => $c['input']], $turn->toolCalls),
                'usage' => $turn->usage, 'model' => $turn->model,
            ]);
            // PRICING-USAGE-CREDITS-001 — the customer's own key: recorded so they see it and their cap alerts at 75/90/100%,
            // never charged against credits.
            $this->usage->record(
                (string) $thread->tenant_id, 'ai_tokens_own_key',
                (int) ($turn->usage['input_tokens'] ?? 0) + (int) ($turn->usage['output_tokens'] ?? 0),
                'agent_message:'.$message->getKey(), scope: $credentialId, cap: $limit,
                referenceType: 'agent_message', referenceId: (string) $message->getKey(),
            );

            if ($turn->stop === 'refusal') {
                return $turn->text !== '' ? $turn->text : 'The model declined to answer this request.';
            }

            if ($turn->stop !== 'tool_use' || $turn->toolCalls === []) {
                return $turn->text;
            }

            if ($i === $rounds) {
                break;
            }

            // Every call of this turn answered in ONE tool message, in order.
            $results = [];
            $activity = [];
            foreach ($turn->toolCalls as $call) {
                $out = $this->toolbox->call(
                    $project, $user, $thread->agent_key, $role['tools'], $call['name'], $call['input'],
                    fn (string $agent, string $question): string => $depth >= 1 ? 'Nested delegation is not allowed.' : $this->delegate($thread, $user, $agent, $question),
                );
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $out['content'], 'is_error' => ! $out['ok']];
                $activity[] = ['name' => $call['name'], 'ok' => $out['ok']];
            }

            AgentMessage::withoutGlobalScopes()->create([
                'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->getKey(), 'role' => 'tool',
                'content' => $results, 'tool_calls' => $activity,
            ]);
        }

        return $this->say($thread, 'I stopped after the maximum number of steps without a final answer. Ask again more narrowly.', 'system');
    }

    /** The orchestrator's `ask_agent`: a fresh, one-question thread with the specialist, inside the same project. */
    private function delegate(AgentThread $parent, ?User $user, string $agent, string $question): string
    {
        $role = AgentRoles::get($agent);
        if ($role === null || ! $role['enabled'] || $agent === 'orchestrator') {
            return 'That agent is not available.';
        }

        $child = AgentThread::withoutGlobalScopes()->create([
            'tenant_id' => $parent->tenant_id, 'project_id' => $parent->project_id, 'agent_key' => $agent,
            'title' => mb_substr($question, 0, 160), 'created_by' => $user?->getKey(),
        ]);
        AgentMessage::withoutGlobalScopes()->create([
            'tenant_id' => $parent->tenant_id, 'thread_id' => $child->getKey(), 'role' => 'user',
            'content' => [['type' => 'text', 'text' => $question]], 'text' => $question,
        ]);

        return $this->reply($child, $user, depth: 1);
    }

    /** @return list<array{role: string, blocks: list<array<string, mixed>>}> */
    private function history(AgentThread $thread): array
    {
        $out = [];
        foreach ($thread->messages()->withoutGlobalScopes()->get() as $m) {
            if ($m->role === 'system') {
                continue;
            }
            $role = $m->role === 'assistant' ? 'assistant' : 'user';
            $blocks = (array) $m->content;

            // Consecutive user-side turns (a person's words after tool results) are one user turn.
            if ($out !== [] && $out[count($out) - 1]['role'] === $role && $role === 'user') {
                $out[count($out) - 1]['blocks'] = [...$out[count($out) - 1]['blocks'], ...$blocks];

                continue;
            }
            $out[] = ['role' => $role, 'blocks' => $blocks];
        }

        return $out;
    }

    private function rulesSummary(Project $project): string
    {
        return ClientAgentRule::withoutGlobalScopes()->where('project_id', $project->getKey())->get()
            ->map(fn (ClientAgentRule $r): string => sprintf(
                '- %s %s %s %s%s%s',
                $r->platform ?? 'all platforms', $r->kpi, $r->direction === 'max' ? 'at most' : 'at least', $r->target_value,
                $r->min_spend_to_judge === null ? '' : ", judge only after spend {$r->min_spend_to_judge}",
                $r->forbidden_words ? ', forbidden words: '.implode(', ', (array) $r->forbidden_words) : '',
            ))->implode("\n");
    }

    private function say(AgentThread $thread, string $text, string $role): string
    {
        AgentMessage::withoutGlobalScopes()->create([
            'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->getKey(), 'role' => $role,
            'content' => [['type' => 'text', 'text' => $text]], 'text' => $text,
        ]);

        return $text;
    }
}
