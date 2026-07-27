<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Notifications\Services\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The messaging lifecycle: open a thread → post messages (client | team | system) → mark read → close.
 *
 * Unread is derived, never a stored counter: a side's unread is the thread's messages whose read_by_<side>_at
 * is still null. Posting stamps the author's own side as read and leaves the OTHER side null — that is the
 * "new unread" for the recipient. markRead() clears one side's unread by stamping every null read column.
 *
 * When a CLIENT posts, the internal team is notified through the shared NotificationDispatcher (type 'message',
 * action_url to the thread) — honest delivery via the existing engine, no new notification path here.
 */
final class MessagingService
{
    private const AUTHOR_TYPES = ['client', 'team', 'system'];

    private const SIDES = ['client', 'team'];

    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Open a new conversation. tenant_id auto-fills from the TenantContext when omitted (BelongsToTenant).
     *
     * @param  array<string,mixed>  $data
     */
    public function openThread(array $data): MessageThread
    {
        return MessageThread::create([
            'tenant_id' => $data['tenant_id'] ?? null,
            'client_workspace_id' => $data['client_workspace_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'subject' => $data['subject'] ?? 'Conversation',
            'status' => $data['status'] ?? 'open',
            'last_message_at' => $data['last_message_at'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Post a message into a thread. Bumps the thread's last_message_at, marks the author's own side read and
     * leaves the OTHER side unread. A client post additionally raises a team notification.
     *
     * @param  array<int,mixed>|null  $attachments
     */
    public function postMessage(
        MessageThread $thread,
        string $authorType,
        string $body,
        ?array $attachments = null,
        ?int $authorUserId = null,
    ): Message {
        if (! in_array($authorType, self::AUTHOR_TYPES, true)) {
            throw new InvalidArgumentException("Unknown author_type [{$authorType}].");
        }

        $now = Carbon::now();

        $message = DB::transaction(function () use ($thread, $authorType, $body, $attachments, $authorUserId, $now): Message {
            $message = Message::create([
                'tenant_id' => $thread->tenant_id,
                'thread_id' => $thread->getKey(),
                'author_type' => $authorType,
                'author_user_id' => $authorUserId,
                'body' => $body,
                'attachments' => $attachments,
                // The author has, by definition, seen their own message; the other side has a fresh unread.
                'read_by_client_at' => $authorType === 'client' ? $now : null,
                'read_by_team_at' => $authorType === 'team' ? $now : null,
            ]);

            // A closed thread reopens on a new post; last_message_at orders the inbox.
            $thread->forceFill([
                'last_message_at' => $message->created_at ?? $now,
                'status' => 'open',
            ])->save();

            return $message;
        });

        if ($authorType === 'client') {
            $this->notifyTeam($thread, $body);
        }

        return $message;
    }

    /**
     * Clear one side's unread by stamping every still-unread message in the thread for that side.
     *
     * @return int the number of messages newly marked read
     */
    public function markRead(MessageThread $thread, string $side): int
    {
        $column = $this->readColumn($side);

        return Message::where('thread_id', $thread->getKey())
            ->whereNull($column)
            ->update([$column => Carbon::now()]);
    }

    /** A side's unread count: messages in the thread not yet read by that side. */
    public function unreadCountFor(MessageThread $thread, string $side): int
    {
        return Message::where('thread_id', $thread->getKey())
            ->whereNull($this->readColumn($side))
            ->count();
    }

    /** Close a thread. Idempotent. */
    public function closeThread(MessageThread $thread): MessageThread
    {
        $thread->forceFill(['status' => 'closed'])->save();

        return $thread;
    }

    /** Raise a team-wide notification for a client post via the shared dispatcher (honest delivery). */
    private function notifyTeam(MessageThread $thread, string $body): void
    {
        $this->notifications->dispatch([
            'tenant_id' => $thread->tenant_id,
            'client_workspace_id' => $thread->client_workspace_id,
            'project_id' => $thread->project_id,
            'user_id' => null, // team-wide (no per-user preference to honor)
            'type' => 'message',
            'severity' => 'info',
            'title' => 'New client message',
            'message' => Str::limit($body, 140),
            'source' => 'messaging',
            'entity_type' => 'message_thread',
            'entity_id' => (string) $thread->getKey(),
            'action_url' => '/app/messages/'.$thread->getKey(),
        ]);
    }

    private function readColumn(string $side): string
    {
        if (! in_array($side, self::SIDES, true)) {
            throw new InvalidArgumentException("Unknown side [{$side}]; expected client|team.");
        }

        return $side === 'client' ? 'read_by_client_at' : 'read_by_team_at';
    }
}
