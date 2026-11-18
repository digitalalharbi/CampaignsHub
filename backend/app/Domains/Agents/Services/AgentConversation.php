<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Agents\Engine\AgentToolbox;
use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\Agents\Engine\StreamingModelClient;
use App\Domains\Agents\Models\AgentMemory;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Models\ClientAgentRule;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Agents\Support\AssistantRouter;
use App\Domains\Agents\Support\DataMinimizer;
use App\Domains\Agents\Support\MemoryScrubber;
use App\Domains\Agents\Support\TurnDigest;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Subscriptions\Usage\UsageLedger;
use App\Domains\Subscriptions\Usage\UsageMeter;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use RuntimeException;

/**
 * AGENTS-ENGINE-001 / AGENTS-ASSISTANT-001 — one turn of a conversation: the model reads, calls tools, and answers.
 *
 * The loop is ours, so every tool call passes through `AgentToolbox` with the thread's project; a model cannot reach a tool its
 * role does not list, and cannot leave the project. In an «المساعد» conversation the role is chosen per message by
 * `AssistantRouter` from the person's own words. Limits that live here, in code, and hold whatever the model says:
 * - the person's daily token cap and the key's monthly ceiling, checked before EVERY model call;
 * - a fixed number of tool rounds per turn (then it stops and says so);
 * - the longest conversation that is replayed;
 * - Stop: checked between streamed events and before every call.
 * Tool results reach the model scrubbed and fenced as untrusted data (`DataMinimizer`); the cards and sources shown beside the
 * answer are built from the same results by code (`TurnDigest`). A refusal from the model is stored and shown as what it is.
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
        $assistant = $thread->agent_key === 'assistant';
        $key = $assistant ? AssistantRouter::route($this->lastUserText($thread), $this->previousSpecialist($thread)) : $thread->agent_key;
        $role = AgentRoles::get($key);
        $threadId = (string) $thread->getKey();
        $live = $depth === 0;

        if ($live) {
            AgentLiveState::put($threadId, ['phase' => 'routing', 'specialist' => $assistant ? $key : null, 'text' => '', 'tools' => []]);
        }

        if ($role === null || ! $role['enabled']) {
            return $this->say($thread, 'This agent is not available yet: '.($role['why_disabled'] ?? 'unknown_agent').'.', 'system', $assistant ? $key : null, ['code' => $role['why_disabled'] ?? 'unknown_agent']);
        }

        if ($assistant && $thread->messages()->withoutGlobalScopes()->count() > (int) config('agents.assistant.max_thread_messages', 80)) {
            throw new RuntimeException('thread_too_long');
        }

        $picked = $this->models->for($project, $key, $assistant ? AssistantSettings::model((string) $thread->tenant_id) : null);
        ['client' => $client, 'model' => $model, 'token_limit' => $limit, 'credential_id' => $credentialId] = $picked;
        // AI-PLATFORM-KEY-001 — on the platform key every reply counts once against this person's monthly allowance.
        $platform = ($picked['platform'] ?? false) === true;
        if ($platform && $live) {
            $this->guardPlatform($thread, $user);
            if ($user !== null) {
                $this->usage->record(
                    (string) $thread->tenant_id, 'ai_messages_platform', 1, 'agent_reply:'.$threadId.':'.$this->lastUserMessageId($thread),
                    scope: 'user:'.$user->getKey(), cap: $this->platformAllowance((string) $thread->tenant_id),
                    referenceType: 'agent_thread', referenceId: $threadId,
                );
            }
        }
        $stable = AgentRoles::stablePrompt($key);
        $context = AgentRoles::contextBlock((string) $project->name, $this->rulesSummary($project), $this->memory($thread, $user), $this->context($thread));
        $tools = $this->toolbox->specs($role['tools']);
        $digest = new TurnDigest;

        $rounds = $assistant ? (int) config('agents.assistant.max_tool_rounds', 6) : (int) config('agents.max_tool_rounds', 8);
        for ($i = 0; $i <= $rounds; $i++) {
            if ($live && AgentLiveState::stopRequested($threadId)) {
                return $this->say($thread, 'Stopped at your request.', 'system', $assistant ? $key : null, ['code' => 'stopped'] + $digest->meta());
            }
            $this->guardSpend($thread, $user, $limit, $credentialId);

            $turn = $live && $client instanceof StreamingModelClient
                ? $client->stream($model, $stable, $context, $this->history($thread), $tools, [
                    'effort' => $role['effort'],
                    'max_tokens' => (int) config('agents.assistant.max_output_tokens', 8000),
                    'timeout' => (float) config('agents.assistant.timeout_seconds', 120),
                ], static fn (string $delta) => AgentLiveState::appendText($threadId, $delta), static fn (): bool => AgentLiveState::stopRequested($threadId))
                : $client->respond($model, $stable."\n\n".$context, $this->history($thread), $tools);

            if ($turn->stop === 'stopped' && $turn->blocks === []) {
                return $this->say($thread, 'Stopped at your request.', 'system', $assistant ? $key : null, ['code' => 'stopped'] + $digest->meta());
            }

            $final = $turn->stop !== 'tool_use' || $turn->toolCalls === [];
            $message = AgentMessage::withoutGlobalScopes()->create([
                'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->getKey(), 'role' => 'assistant',
                'content' => $turn->blocks, 'text' => $turn->text === '' ? null : $turn->text,
                'tool_calls' => $turn->toolCalls === [] ? null : array_map(static fn (array $c): array => ['name' => $c['name'], 'input' => $c['input']], $turn->toolCalls),
                'usage' => $turn->usage, 'model' => $turn->model,
                'specialist' => $assistant ? $key : null,
                'meta' => $final ? $this->finalMeta($digest, $turn->stop) : null,
            ]);
            // PRICING-USAGE-CREDITS-001 — the customer's own key: recorded so they see it and their cap alerts at 75/90/100%,
            // never charged against credits.
            $this->usage->record(
                (string) $thread->tenant_id, $platform ? 'ai_tokens_platform' : 'ai_tokens_own_key',
                (int) ($turn->usage['input_tokens'] ?? 0) + (int) ($turn->usage['output_tokens'] ?? 0),
                'agent_message:'.$message->getKey(), scope: $credentialId, cap: $limit,
                referenceType: 'agent_message', referenceId: (string) $message->getKey(),
            );

            if ($turn->stop === 'refusal') {
                return $turn->text !== '' ? $turn->text : 'The model declined to answer this request.';
            }

            if ($final) {
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
                    $project, $user, $key, $role['tools'], $call['name'], $call['input'],
                    fn (string $agent, string $question): string => $depth >= 1 ? 'Nested delegation is not allowed.' : $this->delegate($thread, $user, $agent, $question),
                );
                $content = $out['content'];
                if ($out['ok']) {
                    $decoded = json_decode($out['content'], true);
                    $decoded = is_array($decoded) ? $decoded : ['value' => $out['content']];
                    $digest->add($call['name'], $call['input'], $decoded);
                    // AGENTS-INJECTION-001 — what the model reads is scrubbed and fenced as data, never as instructions.
                    $content = DataMinimizer::wrap($call['name'], $decoded);
                }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $content, 'is_error' => ! $out['ok']];
                $activity[] = ['name' => $call['name'], 'ok' => $out['ok']];
            }

            AgentMessage::withoutGlobalScopes()->create([
                'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->getKey(), 'role' => 'tool',
                'content' => $results, 'tool_calls' => $activity,
            ]);
            if ($live) {
                AgentLiveState::put($threadId, ['phase' => 'reading', 'text' => '', 'tools' => array_column($activity, 'name')]);
            }
        }

        return $this->say($thread, 'I stopped after the maximum number of steps without a final answer. Ask again more narrowly.', 'system', $assistant ? $key : null, ['code' => 'max_tool_rounds'] + $digest->meta());
    }

    /**
     * The person's daily cap (AGENTS-ASSISTANT-001) and the key's monthly ceiling, read before EVERY call, not once per turn.
     * Measured on THIS key and THIS person — another project's key or a colleague never spends this allowance.
     */
    /**
     * AI-PLATFORM-KEY-001 — this person's replies on the platform key this month, against the plan's per-person allowance.
     * Reached → `ai_monthly_limit_reached` (the screen offers to raise it). No allowance set for the plan = not capped.
     */
    private function guardPlatform(AgentThread $thread, ?User $user): void
    {
        // A scheduled run has no person to count against: on the platform key it is refused, so its cost is never unbounded.
        if ($user === null) {
            throw new RuntimeException('ai_routine_needs_own_key');
        }
        $cap = $this->platformAllowance((string) $thread->tenant_id);
        if ($cap !== null && $this->usage->usedThisMonth((string) $thread->tenant_id, 'ai_messages_platform', 'user:'.$user->getKey()) >= $cap) {
            throw new RuntimeException('ai_monthly_limit_reached');
        }
    }

    /** One person's monthly replies on the platform key for this workspace's plan; null when the plan states none. */
    private function platformAllowance(string $tenantId): ?int
    {
        $meter = UsageMeter::query()->where('code', 'ai_messages_platform')->where('is_active', true)->first();
        $tenant = Tenant::query()->find($tenantId);
        if ($meter === null || $tenant === null) {
            return null;
        }

        return $meter->includedFor(app(SubscriptionService::class)->currentPlan($tenant)?->code);
    }

    /** The id of the message this reply answers, so one reply is counted once even if the job is retried. */
    private function lastUserMessageId(AgentThread $thread): string
    {
        return (string) ($thread->messages()->withoutGlobalScopes()->where('role', 'user')->latest('created_at')->value('id') ?? 'none');
    }

    private function guardSpend(AgentThread $thread, ?User $user, ?int $limit, string $credentialId): void
    {
        if ($limit !== null && $this->usage->usedThisMonth((string) $thread->tenant_id, 'ai_tokens_own_key', $credentialId) >= $limit) {
            throw new RuntimeException('ai_token_limit_reached');
        }
        if ($user !== null && AssistantSettings::usedToday((string) $thread->tenant_id, $user->getKey()) >= AssistantSettings::cap((string) $thread->tenant_id)) {
            throw new RuntimeException('daily_token_cap_reached');
        }
    }

    /** @return array<string, mixed>|null */
    private function finalMeta(TurnDigest $digest, string $stop): ?array
    {
        $meta = $digest->isEmpty() ? [] : $digest->meta();
        if ($stop === 'stopped') {
            $meta['code'] = 'stopped';
        }
        if ($stop === 'max_tokens') {
            $meta['code'] = 'max_tokens';
        }

        return $meta === [] ? null : $meta;
    }

    /** The orchestrator's `ask_agent`: a fresh, one-question thread with the specialist, inside the same project. */
    private function delegate(AgentThread $parent, ?User $user, string $agent, string $question): string
    {
        $role = AgentRoles::get($agent);
        if ($role === null || ! $role['enabled'] || in_array($agent, ['orchestrator', 'assistant'], true)) {
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
            if ($blocks === []) {
                continue;
            }

            // Consecutive user-side turns (a person's words after tool results) are one user turn.
            if ($out !== [] && $out[count($out) - 1]['role'] === $role && $role === 'user') {
                $out[count($out) - 1]['blocks'] = [...$out[count($out) - 1]['blocks'], ...$blocks];

                continue;
            }
            $out[] = ['role' => $role, 'blocks' => $blocks];
        }

        return $out;
    }

    private function lastUserText(AgentThread $thread): string
    {
        return (string) AgentMessage::withoutGlobalScopes()->where('thread_id', $thread->getKey())->where('role', 'user')
            ->whereNotNull('text')->orderByDesc('seq')->value('text');
    }

    private function previousSpecialist(AgentThread $thread): ?string
    {
        $v = AgentMessage::withoutGlobalScopes()->where('thread_id', $thread->getKey())->whereNotNull('specialist')->orderByDesc('seq')->value('specialist');

        return is_string($v) ? $v : null;
    }

    /** AGENTS-MEMORY-001 — the person's own memory, in this workspace only, scrubbed and capped. A routine (no person) has none. */
    private function memory(AgentThread $thread, ?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $texts = AgentMemory::withoutGlobalScopes()->where('tenant_id', $thread->tenant_id)->where('user_id', $user->getKey())
            ->latest('updated_at')->limit(MemoryScrubber::MAX_PER_USER)->pluck('text')->all();

        return MemoryScrubber::forPrompt(array_values(array_map('strval', $texts)));
    }

    /** @return array{days?: int|null, campaign?: string|null} */
    private function context(AgentThread $thread): array
    {
        $c = (array) ($thread->context ?? []);

        return ['days' => isset($c['days']) ? (int) $c['days'] : null, 'campaign' => isset($c['campaign_id']) ? (string) $c['campaign_id'] : null];
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

    /** @param  array<string, mixed>|null  $meta */
    private function say(AgentThread $thread, string $text, string $role, ?string $specialist = null, ?array $meta = null): string
    {
        AgentMessage::withoutGlobalScopes()->create([
            'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->getKey(), 'role' => $role,
            'content' => [['type' => 'text', 'text' => $text]], 'text' => $text,
            'specialist' => $specialist, 'meta' => $meta === null ? null : array_filter($meta, static fn ($v): bool => $v !== [] && $v !== null),
        ]);

        return $text;
    }
}
