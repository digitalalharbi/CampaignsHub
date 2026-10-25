<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Jobs\RunAgentTurnJob;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Audit\AuditLogger;
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
    public function roles(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success(['agents' => collect(AgentRoles::all())->map(fn (array $r, string $k): array => [
            'key' => $k, 'ar' => $r['ar'], 'en' => $r['en'], 'enabled' => $r['enabled'], 'why_disabled' => $r['why_disabled'],
            'can_propose' => in_array('propose_change', $r['tools'], true),
        ])->values()->all()], 'Agents.');
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
        $rows = $query->latest('updated_at')->forPage((int) ($data['page'] ?? 1), 30)->get(['id', 'agent_key', 'title', 'status', 'updated_at', 'created_by']);

        return ApiResponse::success([
            'threads' => $rows->map(fn (AgentThread $t): array => [
                'id' => (string) $t->getKey(), 'agent' => $t->agent_key, 'title' => $t->title, 'status' => $t->status,
                'updated_at' => $t->updated_at?->toIso8601String(),
                // A scheduled run belongs to the project, not to a person.
                'shared' => $t->created_by === null,
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
            'messages' => $row->messages()->get()->filter(fn (AgentMessage $m): bool => $m->role !== 'tool' || $m->tool_calls !== null)
                ->map(fn (AgentMessage $m): array => [
                    'id' => (string) $m->getKey(), 'role' => $m->role, 'text' => $m->text,
                    'tools' => $m->tool_calls, 'at' => $m->created_at?->toIso8601String(),
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

    /** How long a conversation is kept after its last message — shown on screen and enforced by `agents:purge-chat`. */
    public static function retentionDays(): int
    {
        return max(1, (int) config('agents.chat_retention_days', 90));
    }

    /** Start a thread with a first message, or add one to an existing thread; the reply is queued. */
    public function send(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.use'), 403);

        $data = $request->validate([
            'thread_id' => ['nullable', 'uuid'],
            'agent' => ['required_without:thread_id', Rule::in(array_keys(AgentRoles::all()))],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $thread = isset($data['thread_id'])
            ? AgentThread::query()->where('project_id', $project)->where('created_by', $request->user()->getKey())->findOrFail($data['thread_id'])
            : AgentThread::query()->create([
                'project_id' => $project, 'agent_key' => $data['agent'],
                'title' => mb_substr($data['message'], 0, 160), 'created_by' => $request->user()->getKey(),
            ]);

        abort_if($thread->status === 'thinking', 409, 'This agent is still answering the previous message.');

        AgentMessage::query()->create([
            'thread_id' => $thread->getKey(), 'role' => 'user',
            'content' => [['type' => 'text', 'text' => $data['message']]], 'text' => $data['message'],
        ]);
        $thread->forceFill(['status' => 'thinking', 'last_error' => null])->save();

        RunAgentTurnJob::dispatch((string) $thread->getKey(), (int) $request->user()->getKey());

        return ApiResponse::success(['thread_id' => (string) $thread->getKey(), 'status' => $thread->fresh()?->status], 'Message received.', status: 202);
    }
}
