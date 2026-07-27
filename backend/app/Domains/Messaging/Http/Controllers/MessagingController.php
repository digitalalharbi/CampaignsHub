<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Messaging\Services\MessagingService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant messaging: client ⇄ internal-team threads tied to a request/project. Tenant isolation comes from the
 * models' global scope (route-model binding 404s cross-tenant). Reads need messaging.view; mutations
 * messaging.manage.
 */
final class MessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function threads(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('messaging.view'), 403);

        $query = MessageThread::query()->latest('last_message_at');
        if ($status = $request->string('status')->toString()) {
            if (in_array($status, ['open', 'closed'], true)) {
                $query->where('status', $status);
            }
        }

        return ApiResponse::success($query->limit(200)->get()->all(), 'Message threads.');
    }

    public function show(Request $request, MessageThread $messageThread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('messaging.view'), 403);

        return ApiResponse::success([
            'thread' => $messageThread,
            'messages' => $messageThread->messages()->orderBy('created_at')->limit(500)->get()->all(),
            'unread' => [
                'client' => $this->messaging->unreadCountFor($messageThread, 'client'),
                'team' => $this->messaging->unreadCountFor($messageThread, 'team'),
            ],
        ], 'Message thread.');
    }

    public function storeThread(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('messaging.manage'), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:2', 'max:200'],
            'client_workspace_id' => ['nullable', 'uuid'],
            'request_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'body' => ['nullable', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array'],
        ]);

        $thread = $this->messaging->openThread([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'client_workspace_id' => $data['client_workspace_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'subject' => $data['subject'],
            'created_by' => $request->user()->id, // guaranteed by the messaging.manage guard above
        ]);

        // An optional opening message is posted from the team side.
        if (! empty($data['body'])) {
            $this->messaging->postMessage(
                $thread,
                'team',
                $data['body'],
                $data['attachments'] ?? null,
                $request->user()->id,
            );
        }

        return ApiResponse::success($thread->refresh(), 'Message thread created.', status: 201);
    }

    public function postMessage(Request $request, MessageThread $messageThread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('messaging.manage'), 403);

        $data = $request->validate([
            'author_type' => ['required', 'string', 'in:client,team,system'],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'attachments' => ['nullable', 'array'],
        ]);

        $message = $this->messaging->postMessage(
            $messageThread,
            $data['author_type'],
            $data['body'],
            $data['attachments'] ?? null,
            $request->user()->id,
        );

        return ApiResponse::success($message, 'Message posted.', status: 201);
    }

    public function markRead(Request $request, MessageThread $messageThread): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('messaging.manage'), 403);

        $data = $request->validate([
            'side' => ['required', 'string', 'in:client,team'],
        ]);

        $cleared = $this->messaging->markRead($messageThread, $data['side']);

        return ApiResponse::success(
            ['cleared' => $cleared, 'unread' => $this->messaging->unreadCountFor($messageThread, $data['side'])],
            'Marked read.',
        );
    }
}
