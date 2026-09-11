<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Notifications\Services\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /** How many messages either surface renders at once. */
    public const WINDOW = 500;

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

    /**
     * The window of a conversation a reader is shown, and the truth about what is not in it.
     *
     * Both surfaces read the thread as `orderBy('created_at')->limit(500)`. Ascending, then capped,
     * is the OLDEST five hundred: past that length the client opened the conversation on a discussion
     * from months ago and the reply sent that morning was not on the page — silently, because the
     * request is 200 and the count is under the cap the code asked for. The client route then marked
     * the whole thread read, clearing the badge for messages nobody was shown.
     *
     * So the window is taken from the END and turned back into reading order, and the caller is given
     * the total and the number withheld rather than left to infer them from a page that looks whole.
     *
     * Ordered by `id` after `created_at`: a burst posted inside the same second ties on the timestamp,
     * and a tie at the window's edge decides which message is the newest — which is the one thing this
     * must not get wrong.
     *
     * @return array{messages: Collection<int, Message>, total: int, withheld: int, older_before: ?string}
     */
    public function window(MessageThread $thread, ?string $before = null, int $limit = self::WINDOW): array
    {
        $total = Message::where('thread_id', $thread->getKey())->count();

        $query = Message::where('thread_id', $thread->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit);

        /*
         * `$before` is a message id, and the step back is taken on the SAME key the order uses.
         *
         * Paging on `created_at` alone would skip or repeat every message that shares a second with
         * the cursor, which a burst routinely does; `(created_at, id) < (cursor)` is the row-value
         * comparison that makes one page end exactly where the next begins. A cursor naming a message
         * in another thread — or none at all — is simply ignored, because an unreadable cursor must
         * return the newest window rather than an empty conversation.
         */
        $cursor = $before === null
            ? null
            : Message::where('thread_id', $thread->getKey())->whereKey($before)->first();

        if ($cursor !== null) {
            $query->whereRaw('(created_at, id) < (?, ?)', [$cursor->created_at, $cursor->getKey()]);
        }

        $messages = $query->get()->reverse()->values();

        /*
         * `older` counts what lies BEFORE this window, which is not `total - shown` once a reader has
         * paged back: with a cursor, everything after the window is already behind them.
         */
        $oldest = $messages->first();
        $older = $oldest === null
            ? 0
            : Message::where('thread_id', $thread->getKey())
                ->whereRaw('(created_at, id) < (?, ?)', [$oldest->created_at, $oldest->getKey()])
                ->count();

        return [
            'messages' => $messages,
            'total' => $total,
            'withheld' => $older,
            /* The cursor for the window before this one — null when the reader is at the beginning. */
            'older_before' => $older > 0 ? (string) $oldest?->getKey() : null,
        ];
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
