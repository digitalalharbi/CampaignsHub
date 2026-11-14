<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Jobs\RunAgentTurnJob;
use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Models\AgentMemory;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentRoutine;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Services\AgentChatRetention;
use App\Domains\Agents\Services\AgentLiveState;
use App\Domains\Agents\Services\AssistantSettings;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Agents\Support\AgentRoutineCatalog;
use App\Domains\Agents\Support\MemoryScrubber;
use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AGENTS-CENTER-001 — the Agent Center's conversations, over HTTP.
 *
 * Posting a message stores it and queues the reply; the screen reads the thread until its status is
 * `idle` or `failed`. Using an agent needs `ai.use`; reading needs `ai.view`; both are scoped to a project
 * the person can see.
 */
final class AgentChatController extends Controller
{
    /**
     * AGENTS-ASSISTANT-001 — the assistant and its specialists (the earlier `orchestrator` is not offered for new conversations;
     * its stored conversations still open).
     */
    public function roles(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success(['agents' => collect(['assistant', ...AgentRoles::SPECIALISTS])->map(function (string $k): array {
            $r = (array) AgentRoles::get($k);

            return [
                'key' => $k, 'ar' => $r['ar'], 'en' => $r['en'], 'enabled' => $r['enabled'], 'why_disabled' => $r['why_disabled'],
                'can_propose' => in_array('propose_change', $r['tools'], true),
                'job' => $r['job'], 'autonomy' => $r['autonomy'],
            ];
        })->values()->all()], 'Agents.');
    }

    /**
     * GET agents/team — AGENTS-TEAM-001: the specialists grouped by JOB and by AUTONOMY, each with its one-line job, its last activity
     * in this project (what the person may see: their own conversations and the project's scheduled runs), its pending proposals
     * and whether any of its schedules is on.
     */
    public function team(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $uid = $request->user()->getKey();

        $visibleThreads = AgentThread::query()->where('project_id', $project)
            ->where(fn ($q) => $q->where('created_by', $uid)->orWhereNull('created_by'))->select('id');
        $lastBySpecialist = AgentMessage::query()->whereIn('thread_id', $visibleThreads)->whereNotNull('specialist')
            ->groupBy('specialist')->selectRaw('specialist, MAX(created_at) AS at')->pluck('at', 'specialist');
        $lastByThread = AgentThread::query()->where('project_id', $project)
            ->where(fn ($q) => $q->where('created_by', $uid)->orWhereNull('created_by'))
            ->groupBy('agent_key')->selectRaw('agent_key, MAX(updated_at) AS at')->pluck('at', 'agent_key');
        $pending = ActionProposal::query()->where('project_id', $project)->where('status', ActionProposal::PENDING)
            ->groupBy('agent_key')->selectRaw('agent_key, COUNT(*) AS n')->pluck('n', 'agent_key');
        $routines = AgentRoutine::query()->where('project_id', $project)->where('enabled', true)->pluck('routine')->all();
        $scheduled = [];
        foreach (AgentRoutineCatalog::all() as $k => $r) {
            $scheduled[$r['agent']] ??= ['has' => false, 'on' => false];
            $scheduled[$r['agent']]['has'] = true;
            $scheduled[$r['agent']]['on'] = $scheduled[$r['agent']]['on'] || in_array($k, $routines, true);
        }

        $members = collect(AgentRoles::SPECIALISTS)->map(function (string $k) use ($lastBySpecialist, $lastByThread, $pending, $scheduled): array {
            $r = (array) AgentRoles::get($k);
            $times = array_filter([$lastBySpecialist[$k] ?? null, $lastByThread[$k] ?? null]);
            $last = $times === [] ? null : Carbon::parse((string) max(array_map(static fn ($t): string => Carbon::parse((string) $t)->toIso8601String(), $times)));

            return [
                'key' => $k, 'ar' => $r['ar'], 'en' => $r['en'], 'job' => $r['job'], 'autonomy' => $r['autonomy'],
                'job_ar' => $r['job_ar'], 'job_en' => $r['job_en'], 'enabled' => $r['enabled'], 'why_disabled' => $r['why_disabled'],
                'last_activity_at' => $last?->toIso8601String(),
                'pending_proposals' => (int) ($pending[$k] ?? 0),
                'schedule' => isset($scheduled[$k]) ? ($scheduled[$k]['on'] ? 'on' : 'off') : 'none',
            ];
        })->values()->all();

        return ApiResponse::success(['jobs' => AgentRoles::JOBS, 'members' => $members], 'Team.');
    }

    /**
     * AGENT-CHAT-PRIVACY-001 — a conversation with an agent is the PERSON'S, not the project's.
     *
     * The list, the transcript and the reply used to ask only «is it in this project?», so any colleague with `ai.view` could read
     * what another had asked the agent — and write into their thread, which then ran the agent as the colleague. Now a thread is
     * reachable only by its author. The one shared kind is a scheduled routine's run (no author; `created_by` null): it is a
     * project output, readable by anyone who may view the agents, never written into, and removed only by `ai.manage`.
     *
     * @return Builder<AgentThread>
     */
    private function visible(Request $request, string $project)
    {
        $uid = $request->user()->getKey();

        return AgentThread::query()->where('project_id', $project)
            ->where(fn ($q) => $q->where('created_by', $uid)->orWhereNull('created_by'));
    }

    /** GET agent-threads — mine (plus the project's scheduled runs), searchable and filterable; q reads titles AND message text. */
    public function threads(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'agent' => ['nullable', Rule::in(array_keys(AgentRoles::all()))],
            'status' => ['nullable', Rule::in(['idle', 'thinking', 'failed'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->visible($request, $project);
        if (($q = trim((string) ($data['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(fn ($w) => $w->where('title', 'ilike', $like)
                ->orWhereIn('id', AgentMessage::query()->where('text', 'ilike', $like)->select('thread_id')));
        }
        foreach (['agent' => 'agent_key', 'status' => 'status'] as $param => $column) {
            if (! empty($data[$param])) {
                $query->where($column, $data[$param]);
            }
        }
        if (! empty($data['from'])) {
            $query->where('updated_at', '>=', Carbon::parse($data['from'])->startOfDay());
        }
        if (! empty($data['to'])) {
            $query->where('updated_at', '<=', Carbon::parse($data['to'])->endOfDay());
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('pinned')->latest('updated_at')->forPage((int) ($data['page'] ?? 1), 30)->get(['id', 'agent_key', 'title', 'status', 'updated_at', 'created_by', 'pinned']);

        return ApiResponse::success([
            'threads' => $rows->map(fn (AgentThread $t): array => [
                'id' => (string) $t->getKey(), 'agent' => $t->agent_key, 'title' => $t->title, 'status' => $t->status,
                'updated_at' => $t->updated_at?->toIso8601String(),
                // A scheduled run belongs to the project, not to a person.
                'shared' => $t->created_by === null,
                'pinned' => (bool) $t->pinned,
            ])->all(),
            'total' => $total,
            'retention_days' => self::retentionDays(),
        ], 'Threads.');
    }

    public function show(Request $request, string $project, string $thread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $row = $this->visible($request, $project)->findOrFail($thread);

        return ApiResponse::success([
            'id' => (string) $row->getKey(), 'agent' => $row->agent_key, 'title' => $row->title,
            'status' => $row->status, 'error' => $row->last_error, 'shared' => $row->created_by === null,
            'pinned' => (bool) $row->pinned, 'context' => $row->context,
            'messages' => $row->messages()->get()->filter(fn (AgentMessage $m): bool => $m->role !== 'tool' || $m->tool_calls !== null)
                ->map(fn (AgentMessage $m): array => [
                    'id' => (string) $m->getKey(), 'role' => $m->role, 'text' => $m->text,
                    // The tool NAMES and outcome only: a tool's input or result never reaches the screen from here.
                    'tools' => $m->tool_calls === null ? null : array_map(static fn (array $t): array => array_intersect_key($t, array_flip(['name', 'ok'])), (array) $m->tool_calls),
                    'at' => $m->created_at?->toIso8601String(),
                    'specialist' => $m->specialist, 'meta' => $m->meta,
                ])->values()->all(),
        ], 'Thread.');
    }

    /** DELETE agent-threads/{thread} — my own conversation (a shared scheduled run needs `ai.manage`); never one still being answered. */
    public function destroy(Request $request, string $project, string $thread, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $row = $this->visible($request, $project)->findOrFail($thread);
        abort_if($row->created_by === null && ! $request->user()->hasPermission('ai.manage'), 403, 'A scheduled run is removed by someone who manages the agents.');
        abort_if($row->status === 'thinking', 409, 'The agent is still answering; try again in a moment.');

        $this->erase([$row], $audit, $request);

        return ApiResponse::success(['deleted' => 1], 'Conversation deleted.');
    }

    /** DELETE agent-threads — clear MY history in this project (never a colleague's, never a shared run, never one in progress). */
    public function clear(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $rows = AgentThread::query()->where('project_id', $project)->where('created_by', $request->user()->getKey())->where('status', '!=', 'thinking')->get();
        $this->erase($rows->all(), $audit, $request);

        return ApiResponse::success(['deleted' => $rows->count()], 'History cleared.');
    }

    /** @param list<AgentThread> $threads */
    private function erase(array $threads, AuditLogger $audit, Request $request): void
    {
        DB::transaction(function () use ($threads, $audit, $request): void {
            foreach ($threads as $t) {
                AgentMessage::query()->where('thread_id', $t->getKey())->delete();
                $t->delete();
            }
            $audit->log(action: 'agent.threads.deleted', entityType: AgentThread::class, entityId: (string) ($threads[0]->getKey() ?? ''), after: ['count' => count($threads), 'by' => $request->user()->getKey()]);
        });
    }

    /** How long THIS workspace keeps a conversation after its last message — shown on screen and enforced by `agents:purge-chat`. */
    public static function retentionDays(): int
    {
        return AgentChatRetention::forTenant(app(TenantContext::class)->tenantId());
    }

    /**
     * Start a thread with a first message, or add one to an existing thread; the reply is queued.
     *
     * AGENTS-ASSISTANT-001: a new conversation starts with «المساعد» (`assistant`) unless an agent is named. `context` carries the
     * chips the person sees (period, campaign); a campaign must be this project's. «تذكّر …» / «انسَ …» are handled here, in code,
     * without a model call: memory changes only on the person's own words, never on anything a tool returned.
     */
    public function send(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use'), 403);

        $data = $request->validate([
            'thread_id' => ['nullable', 'uuid'],
            'agent' => ['nullable', Rule::in(array_keys(AgentRoles::all()))],
            'message' => ['required', 'string', 'max:'.(int) config('agents.assistant.max_message_chars', 4000)],
            'context' => ['nullable', 'array'],
            'context.days' => ['nullable', 'integer', Rule::in([1, 7, 14, 30, 90])],
            'context.campaign_id' => ['nullable', 'uuid'],
        ]);
        $user = $request->user();
        $context = $this->context($project, (array) ($data['context'] ?? []));

        $thread = isset($data['thread_id'])
            ? AgentThread::query()->where('project_id', $project)->where('created_by', $user->getKey())->findOrFail($data['thread_id'])
            : AgentThread::query()->create([
                'project_id' => $project, 'agent_key' => $data['agent'] ?? 'assistant',
                'title' => mb_substr($data['message'], 0, 160), 'created_by' => $user->getKey(),
            ]);

        abort_if($thread->status === 'thinking', 409, 'This agent is still answering the previous message.');
        if (array_key_exists('context', $data)) {
            $thread->forceFill(['context' => $context === [] ? null : $context])->save();
        }

        AgentMessage::query()->create([
            'thread_id' => $thread->getKey(), 'role' => 'user',
            'content' => [['type' => 'text', 'text' => $data['message']]], 'text' => $data['message'],
        ]);

        // AGENTS-MEMORY-001 — «تذكّر …» and «انسَ …» in an assistant conversation: answered in code, no model, no tokens.
        if ($thread->agent_key === 'assistant' && ($memory = $this->memoryCommand($request, $thread, $data['message'])) !== null) {
            $thread->touch();

            return ApiResponse::success(['thread_id' => (string) $thread->getKey(), 'status' => 'idle', 'memory' => $memory], 'Memory updated.', status: 202);
        }

        // AGENTS-ASSISTANT-001 — the person's daily cap is refused up front, in words the screen knows, before anything is queued.
        if (AssistantSettings::usedToday((string) $thread->tenant_id, $user->getKey()) >= AssistantSettings::cap((string) $thread->tenant_id)) {
            $thread->forceFill(['status' => 'failed', 'last_error' => 'daily_token_cap_reached'])->save();

            return ApiResponse::success(['thread_id' => (string) $thread->getKey(), 'status' => 'failed', 'error' => 'daily_token_cap_reached'], 'Daily cap reached.', status: 202);
        }

        $thread->forceFill(['status' => 'thinking', 'last_error' => null])->save();
        AgentLiveState::clear((string) $thread->getKey());

        RunAgentTurnJob::dispatch((string) $thread->getKey(), (int) $user->getKey());

        $suggested = $thread->agent_key === 'assistant' ? MemoryScrubber::suggestion($data['message']) : null;

        return ApiResponse::success(['thread_id' => (string) $thread->getKey(), 'status' => $thread->fresh()?->status, 'suggested_memory' => $suggested], 'Message received.', status: 202);
    }

    /** GET agent-threads/{thread}/live — AGENTS-STREAM-001: the reply as it is being written (one cache read). */
    public function live(Request $request, string $project, string $thread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $row = $this->visible($request, $project)->findOrFail($thread);
        $state = AgentLiveState::get((string) $row->getKey());

        return ApiResponse::success([
            'status' => $row->status, 'error' => $row->last_error,
            'phase' => $state['phase'] ?? null, 'specialist' => $state['specialist'] ?? null,
            'text' => $row->status === 'thinking' ? (string) ($state['text'] ?? '') : '',
            'tools' => $state['tools'] ?? [], 'seq' => (int) ($state['seq'] ?? 0),
        ], 'Live.');
    }

    /** POST agent-threads/{thread}/stop — the author stops their own reply; the running turn stops reading and spends nothing more. */
    public function stop(Request $request, string $project, string $thread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use'), 403);
        $row = AgentThread::query()->where('project_id', $project)->where('created_by', $request->user()->getKey())->findOrFail($thread);
        if ($row->status === 'thinking') {
            AgentLiveState::requestStop((string) $row->getKey());
        }

        return ApiResponse::success(['status' => $row->status, 'stopping' => $row->status === 'thinking'], 'Stopping.');
    }

    /** PATCH agent-threads/{thread} — rename or pin MY conversation (a shared scheduled run is the project's and is not renamed here). */
    public function update(Request $request, string $project, string $thread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $data = $request->validate(['title' => ['sometimes', 'string', 'min:1', 'max:160'], 'pinned' => ['sometimes', 'boolean']]);
        $row = AgentThread::query()->where('project_id', $project)->where('created_by', $request->user()->getKey())->findOrFail($thread);
        $row->timestamps = false;
        $row->forceFill(array_filter(['title' => isset($data['title']) ? trim($data['title']) : null, 'pinned' => $data['pinned'] ?? null], static fn ($v): bool => $v !== null))->save();

        return ApiResponse::success(['id' => (string) $row->getKey(), 'title' => $row->title, 'pinned' => (bool) $row->pinned], 'Conversation updated.');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{days?: int, campaign_id?: string}
     */
    private function context(string $project, array $raw): array
    {
        $out = [];
        if (! empty($raw['days'])) {
            $out['days'] = (int) $raw['days'];
        }
        if (! empty($raw['campaign_id'])) {
            // The campaign must be this project's — checked here, never trusted from the screen.
            abort_unless(UnifiedCampaign::query()->where('project_id', $project)->whereKey($raw['campaign_id'])->exists(), 422, 'That campaign is not in this project.');
            $out['campaign_id'] = (string) $raw['campaign_id'];
        }

        return $out;
    }

    /** @return array<string, mixed>|null the memory outcome, or null when the message is not a memory command */
    private function memoryCommand(Request $request, AgentThread $thread, string $message): ?array
    {
        $uid = $request->user()->getKey();
        $remember = MemoryScrubber::rememberRequest($message);
        if ($remember !== null) {
            $clean = MemoryScrubber::clean($remember);
            $count = AgentMemory::query()->where('user_id', $uid)->count();
            if ($clean === null || $count >= MemoryScrubber::MAX_PER_USER) {
                $code = $clean === null ? 'memory_rejected' : 'memory_full';
                $this->reply($thread, $code === 'memory_rejected' ? 'لم أحفظ هذا: لا أحفظ كلمات مرور أو مفاتيح أو أرقام تواصل، والحد 280 حرفًا.' : 'الذاكرة ممتلئة (30). احذف شيئًا من الإعدادات أولًا.', $code);

                return ['action' => 'rejected', 'code' => $code];
            }
            $row = AgentMemory::query()->create(['user_id' => $uid, 'text' => $clean, 'source' => 'user']);
            $this->reply($thread, 'تم، سأتذكّر: «'.$clean.'»', 'memory_saved', ['memory_id' => (string) $row->getKey()]);

            return ['action' => 'saved', 'id' => (string) $row->getKey(), 'text' => $clean];
        }

        $forget = MemoryScrubber::forgetRequest($message);
        if ($forget !== null) {
            $query = AgentMemory::query()->where('user_id', $uid);
            if ($forget !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $forget).'%';
                $query->where('text', 'ilike', $like);
            }
            $n = $query->delete();
            $this->reply($thread, $n > 0 ? 'تم، نسيت '.$n.' من تفضيلاتك.' : 'لم أجد تفضيلًا يطابق ذلك.', 'memory_forgotten', ['count' => $n]);

            return ['action' => 'forgotten', 'count' => $n];
        }

        return null;
    }

    /** @param  array<string, mixed>  $extra */
    private function reply(AgentThread $thread, string $text, string $code, array $extra = []): void
    {
        AgentMessage::query()->create([
            'thread_id' => $thread->getKey(), 'role' => 'assistant', 'specialist' => null,
            'content' => [['type' => 'text', 'text' => $text]], 'text' => $text, 'meta' => ['code' => $code] + $extra,
        ]);
        $thread->forceFill(['status' => 'idle', 'last_error' => null])->save();
    }
}
