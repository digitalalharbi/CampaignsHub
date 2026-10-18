<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Jobs\RunAgentTurnJob;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Support\AgentRoles;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function threads(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success(['threads' => AgentThread::query()->where('project_id', $project)
            ->latest('updated_at')->limit(50)->get(['id', 'agent_key', 'title', 'status', 'updated_at'])
            ->map(fn (AgentThread $t): array => ['id' => (string) $t->getKey(), 'agent' => $t->agent_key, 'title' => $t->title, 'status' => $t->status, 'updated_at' => $t->updated_at?->toIso8601String()])
            ->all()], 'Threads.');
    }

    public function show(Request $request, string $project, string $thread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $row = AgentThread::query()->where('project_id', $project)->findOrFail($thread);

        return ApiResponse::success([
            'id' => (string) $row->getKey(), 'agent' => $row->agent_key, 'title' => $row->title,
            'status' => $row->status, 'error' => $row->last_error,
            'messages' => $row->messages()->get()->filter(fn (AgentMessage $m): bool => $m->role !== 'tool' || $m->tool_calls !== null)
                ->map(fn (AgentMessage $m): array => [
                    'id' => (string) $m->getKey(), 'role' => $m->role, 'text' => $m->text,
                    'tools' => $m->tool_calls, 'at' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
        ], 'Thread.');
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
            ? AgentThread::query()->where('project_id', $project)->findOrFail($data['thread_id'])
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
