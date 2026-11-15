<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\Agents\Models\AgentMemory;
use App\Domains\Agents\Services\AssistantSettings;
use App\Domains\Agents\Support\MemoryScrubber;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * AGENTS-ASSISTANT-001 / AGENTS-MEMORY-001 — the Settings drawer of «المساعد».
 *
 * - The workspace's assistant model and the per-person daily token cap (`ai.manage` to change; anyone who may view sees them).
 * - My usage today against that cap (the chip).
 * - A key's connection test: one tiny request through the same client the agents use (a test binds a fake client; no test reaches
 *   a provider). The answer is a code, never the provider's text, and never the key.
 * - MY memory: list, add, edit, delete one, forget all, export — scoped to me AND this workspace, never anyone else's.
 */
final class AssistantController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success($this->shape($request), 'Assistant settings.');
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.manage'), 403);
        $data = $request->validate([
            'assistant_model' => ['sometimes', 'string', Rule::in(AssistantSettings::models())],
            'daily_token_cap' => ['sometimes', 'integer', 'min:'.AssistantSettings::CAP_MIN, 'max:'.AssistantSettings::CAP_MAX],
        ]);

        $tenant = Tenant::query()->findOrFail((string) $this->tenant->tenantId());
        $before = ['model' => AssistantSettings::model((string) $tenant->getKey()), 'cap' => AssistantSettings::cap((string) $tenant->getKey())];
        AssistantSettings::set($tenant, $data);
        $audit->log(action: 'agent.assistant_settings.updated', entityType: Tenant::class, entityId: (string) $tenant->getKey(), before: $before,
            after: ['model' => AssistantSettings::model((string) $tenant->getKey()), 'cap' => AssistantSettings::cap((string) $tenant->getKey())]);

        return ApiResponse::success($this->shape($request), 'Assistant settings saved.');
    }

    /** POST ai/credentials/{credential}/test — does the stored key answer? A code back: ok | refused_401 | rate_limited | failed. */
    public function testConnection(Request $request, string $credential, ModelClientFactory $factory): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.manage'), 403);
        $row = AIProviderCredential::query()->findOrFail($credential);   // tenant-scoped: another workspace's key is a 404
        $model = $row->provider === 'anthropic'
            ? AssistantSettings::model((string) $row->tenant_id)
            : (string) (((array) ($row->allowed_models ?? []))[0] ?? config("agents.models.{$row->provider}.analyst") ?? '');
        if ($model === '') {
            return ApiResponse::success(['ok' => false, 'code' => 'model_not_configured', 'masked_key' => $row->maskedKey()], 'Not tested.');
        }

        try {
            $turn = $factory->clientFor($row)->respond($model, 'Reply with the single word: ok', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'ping']]]], [], 64);
            $ok = $turn->stop !== 'refusal';
            $code = $ok ? 'ok' : 'refused';
        } catch (Throwable $e) {
            // The provider's own text may echo request details; only a status code leaves this method.
            $status = (int) $e->getCode();
            $message = $e->getMessage();
            $ok = false;
            $code = match (true) {
                $status === 401 || str_contains($message, '401') => 'refused_401',
                $status === 429 || str_contains($message, '429') => 'rate_limited',
                default => 'failed',
            };
        }
        $row->forceFill(['last_health_check_at' => now()])->save();

        return ApiResponse::success(['ok' => $ok, 'code' => $code, 'model' => $model, 'masked_key' => $row->maskedKey()], $ok ? 'Connected.' : 'Not connected.');
    }

    public function memories(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use') || $request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success(['memories' => $this->mine($request), 'max' => MemoryScrubber::MAX_PER_USER, 'max_chars' => MemoryScrubber::MAX_CHARS], 'Memories.');
    }

    public function exportMemories(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 403);

        return ApiResponse::success(['exported_at' => now()->toIso8601String(), 'memories' => $this->mine($request)], 'Memories export.');
    }

    public function storeMemory(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use'), 403);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000'], 'source' => ['nullable', Rule::in(['user', 'suggested'])]]);
        $clean = MemoryScrubber::clean($data['text']);
        abort_if($clean === null, 422, 'memory_rejected');
        abort_if(AgentMemory::query()->where('user_id', $request->user()->getKey())->count() >= MemoryScrubber::MAX_PER_USER, 422, 'memory_full');

        $row = AgentMemory::query()->create(['user_id' => $request->user()->getKey(), 'text' => $clean, 'source' => $data['source'] ?? 'user']);
        $audit->log(action: 'agent.memory.created', entityType: AgentMemory::class, entityId: (string) $row->getKey(), after: ['source' => $row->source]);

        return ApiResponse::success($this->row($row), 'Saved.', status: 201);
    }

    public function updateMemory(Request $request, string $memory): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use'), 403);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        $row = AgentMemory::query()->where('user_id', $request->user()->getKey())->findOrFail($memory);
        $clean = MemoryScrubber::clean($data['text']);
        abort_if($clean === null, 422, 'memory_rejected');
        $row->forceFill(['text' => $clean])->save();

        return ApiResponse::success($this->row($row), 'Saved.');
    }

    public function destroyMemory(Request $request, string $memory, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user() !== null, 403);
        $row = AgentMemory::query()->where('user_id', $request->user()->getKey())->findOrFail($memory);
        $row->delete();
        $audit->log(action: 'agent.memory.deleted', entityType: AgentMemory::class, entityId: (string) $memory, after: ['count' => 1]);

        return ApiResponse::success(['deleted' => 1], 'Deleted.');
    }

    /** DELETE ai/memories — forget everything I asked the assistant to remember, in this workspace. */
    public function forgetAll(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user() !== null, 403);
        $n = AgentMemory::query()->where('user_id', $request->user()->getKey())->delete();
        $audit->log(action: 'agent.memory.forgotten', entityType: AgentMemory::class, entityId: (string) $request->user()->getKey(), after: ['count' => $n]);

        return ApiResponse::success(['deleted' => $n], 'Forgotten.');
    }

    /** @return array<string, mixed> */
    private function shape(Request $request): array
    {
        $tenantId = (string) $this->tenant->tenantId();

        return [
            'model' => AssistantSettings::model($tenantId),
            'models' => AssistantSettings::models(),
            'default_model' => AssistantSettings::defaultModel(),
            'daily_token_cap' => AssistantSettings::cap($tenantId),
            'cap_min' => AssistantSettings::CAP_MIN,
            'cap_max' => AssistantSettings::CAP_MAX,
            'used_today' => AssistantSettings::usedToday($tenantId, $request->user()->getKey()),
            'max_tool_rounds' => (int) config('agents.assistant.max_tool_rounds', 6),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function mine(Request $request): array
    {
        return AgentMemory::query()->where('user_id', $request->user()->getKey())->latest('updated_at')->get()
            ->map(fn (AgentMemory $m): array => $this->row($m))->values()->all();
    }

    /** @return array<string, mixed> */
    private function row(AgentMemory $m): array
    {
        return ['id' => (string) $m->getKey(), 'text' => $m->text, 'source' => $m->source, 'updated_at' => $m->updated_at?->toIso8601String()];
    }
}
