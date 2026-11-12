<?php

declare(strict_types=1);

namespace App\Domains\Legal\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Models\SupportTicket;
use App\Domains\Legal\Services\ConnectionRevocations;
use App\Domains\Legal\Services\DeletionBlockers;
use App\Domains\Legal\Services\DeletionVerification;
use App\Domains\Legal\Services\PlatformInboxReplies;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * LEGAL-002 — the platform operator's queues: enquiries, tickets and data-subject requests.
 *
 * Behind the `platform` gate. These records name people who are not customers and, in the case of a
 * data request, carry a statutory obligation — neither belongs in a tenant's workspace.
 *
 * ## Why the three are separate endpoints rather than one «inbox»
 *
 * They are answered by different people on different clocks. An enquiry is sales, a ticket is
 * support, and a data request is a legal obligation with a deadline. A merged list sorted by date
 * would bury the one with the deadline under the two without.
 */
final class LegalInboxController extends Controller
{
    /** Everything not yet concluded — the number that matters against a statutory clock. */
    private const DATA_OUTSTANDING = ['pending', 'verifying', 'in_review', 'blocked'];

    public function __construct(
        private readonly DeletionBlockers $blockers,
        private readonly DeletionVerification $verification,
        private readonly ConnectionRevocations $revocations,
        private readonly PlatformInboxReplies $replies,
    ) {}

    /**
     * GET /admin/inbox/summary — PLATFORM-INBOX-ATTENTION-001: the three queues' counters in one answer.
     *
     * `unread` is a row no administrator has opened (`read_at` is null); the second figure is each queue's own
     * «still needs something» — new enquiries, open tickets, data requests not yet concluded.
     */
    public function summary(): JsonResponse
    {
        $contact = ['unread' => ContactMessage::query()->whereNull('read_at')->count(), 'open' => ContactMessage::query()->where('status', 'new')->count()];
        $support = ['unread' => SupportTicket::query()->whereNull('read_at')->count(), 'open' => SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count()];
        $data = ['unread' => DataRequest::query()->whereNull('read_at')->count(), 'open' => DataRequest::query()->whereIn('status', self::DATA_OUTSTANDING)->count()];

        return ApiResponse::success([
            'contact' => $contact,
            'support' => $support,
            'data' => $data,
            'unread' => $contact['unread'] + $support['unread'] + $data['unread'],
        ], 'Platform inbox.');
    }

    /** GET /admin/legal/contact-messages/{message} — opens it, which marks it read. */
    public function showContactMessage(Request $request, ContactMessage $message): JsonResponse
    {
        $this->markRead($message, $request);
        if ($message->status === 'new') {
            $message->forceFill(['status' => 'read'])->save();
        }

        return ApiResponse::success([
            'message' => $message->fresh(),
            'replies' => $this->replies->thread('contact_message', (string) $message->getKey()),
        ], 'Contact message.');
    }

    /** GET /admin/legal/support-tickets/{ticket} */
    public function showSupportTicket(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->markRead($ticket, $request);

        return ApiResponse::success([
            'ticket' => $ticket->fresh(),
            'replies' => $this->replies->thread('support_ticket', (string) $ticket->getKey()),
        ], 'Support ticket.');
    }

    /** GET /admin/legal/data-requests/{dataRequest} */
    public function showDataRequest(Request $request, DataRequest $dataRequest): JsonResponse
    {
        $this->markRead($dataRequest, $request);

        return ApiResponse::success(['request' => $dataRequest->fresh()], 'Data request.');
    }

    /** POST /admin/legal/contact-messages/{message}/reply — by email, to the address the visitor gave. */
    public function replyContactMessage(Request $request, ContactMessage $message): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $reply = $this->replies->reply($message, $request->user(), $data['body']);

        return ApiResponse::success([
            'reply' => ['id' => $reply->id, 'delivery_status' => $reply->delivery_status],
            'message' => $message->fresh(),
            'replies' => $this->replies->thread('contact_message', (string) $message->getKey()),
        ], 'Reply recorded.');
    }

    /** POST /admin/legal/support-tickets/{ticket}/reply */
    public function replySupportTicket(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $reply = $this->replies->reply($ticket, $request->user(), $data['body']);

        return ApiResponse::success([
            'reply' => ['id' => $reply->id, 'delivery_status' => $reply->delivery_status],
            'ticket' => $ticket->fresh(),
            'replies' => $this->replies->thread('support_ticket', (string) $ticket->getKey()),
        ], 'Reply recorded.');
    }

    /** The first administrator to open a row is recorded; opening it again changes nothing. */
    private function markRead(Model $row, Request $request): void
    {
        if ($row->getAttribute('read_at') === null) {
            $row->forceFill(['read_at' => now(), 'read_by' => $request->user()?->id])->save();
        }
    }

    public function contactMessages(Request $request): JsonResponse
    {
        $rows = ContactMessage::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return ApiResponse::success([
            'messages' => $rows->items(),
            'total' => $rows->total(),
            // The count that decides whether anyone needs to open this screen today.
            'unhandled' => ContactMessage::query()->where('status', 'new')->count(),
            'unread' => ContactMessage::query()->whereNull('read_at')->count(),
        ], 'Contact messages.');
    }

    public function updateContactMessage(Request $request, ContactMessage $message): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ContactMessage::STATUSES)],
            'operator_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $message->fill([
            ...$data,
            'handled_by' => $request->user()?->id,
            'handled_at' => now(),
        ])->save();

        return ApiResponse::success($message->fresh(), 'Message updated.');
    }

    public function supportTickets(Request $request): JsonResponse
    {
        $rows = SupportTicket::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return ApiResponse::success([
            'tickets' => $rows->items(),
            'total' => $rows->total(),
            'open' => SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count(),
            'unread' => SupportTicket::query()->whereNull('read_at')->count(),
        ], 'Support tickets.');
    }

    public function updateSupportTicket(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'operator_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket->fill([
            ...$data,
            'assigned_to' => $request->user()?->id,
            'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? now() : null,
        ])->save();

        return ApiResponse::success($ticket->fresh(), 'Ticket updated.');
    }

    public function dataRequests(Request $request): JsonResponse
    {
        $rows = DataRequest::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return ApiResponse::success([
            'requests' => $rows->items(),
            'total' => $rows->total(),
            // Everything not yet concluded — the number that matters against a statutory clock.
            'outstanding' => DataRequest::query()->whereIn('status', self::DATA_OUTSTANDING)->count(),
            'unread' => DataRequest::query()->whereNull('read_at')->count(),
        ], 'Data requests.');
    }

    /**
     * Move a data request along, re-checking the blockers whenever it is being completed.
     *
     * The re-check is the point. Blockers were evaluated at submission, and an invoice may have been
     * settled — or raised — since. Completing a destructive request without asking again would let an
     * operator delete a workspace whose subscription went active an hour ago, and the record would
     * show the stale reasons as though they had been current.
     */
    public function updateDataRequest(Request $request, DataRequest $dataRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(DataRequest::STATUSES)],
            'operator_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = $data['status'];

        /*
         * LEGAL-DELETE-001 — an unverified destructive request cannot be completed. Fail closed.
         *
         * The blocker check below asks whether it is SAFE to delete; this asks the question that has
         * to come first — whether the person who asked is the person whose data it is. Nobody proved
         * that for a request still sitting in `verifying`, and an operator working through a queue
         * has no way to tell by looking. So the refusal is the system's, not their judgement's.
         */
        if ($status === 'completed' && ! $this->verification->isActionable($dataRequest)) {
            return ApiResponse::error(
                'This deletion has not been verified by the requester, so it cannot be completed.',
                null,
                ['status' => $dataRequest->status, 'verification_required' => true],
                422,
            );
        }

        if ($status === 'completed' && $dataRequest->isDestructive()) {
            $blockers = $this->blockers->forTenant($dataRequest->tenant_id ? (string) $dataRequest->tenant_id : null);

            if ($blockers !== []) {
                $dataRequest->fill([
                    'status' => 'blocked',
                    'blockers' => $blockers,
                    'operator_note' => $data['operator_note'] ?? $dataRequest->operator_note,
                    'reviewed_by' => $request->user()?->id,
                    'reviewed_at' => now(),
                ])->save();

                return ApiResponse::error(
                    'This request cannot be completed while it is blocked.',
                    null,
                    ['blockers' => $blockers, 'status' => 'blocked'],
                    422,
                );
            }
        }

        $dataRequest->fill([
            'status' => $status,
            'operator_note' => $data['operator_note'] ?? $dataRequest->operator_note,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            // Clearing the reasons when it is no longer blocked keeps a stale list from being read as
            // current the next time somebody opens the record.
            'blockers' => $status === 'blocked' ? $dataRequest->blockers : null,
        ])->save();

        /*
         * Completing a deletion revokes the workspace's provider connections.
         *
         * Deleting our copy while a live OAuth token still points at their advertising account is
         * half a deletion — the access we were granted outlives the data it was granted for. Revoking
         * goes through the same path a person's own Disconnect uses, so there is one behaviour and
         * one audit action rather than a second, quieter one.
         */
        $revoked = $status === 'completed' && $dataRequest->isDestructive()
            ? $this->revocations->revokeForTenant($dataRequest->tenant_id ? (string) $dataRequest->tenant_id : null)
            : 0;

        AuditLog::create([
            'tenant_id' => $dataRequest->tenant_id,
            'user_id' => $request->user()?->id,
            'action' => 'legal.data_request.'.$status,
            'entity_type' => DataRequest::class,
            'entity_id' => (string) $dataRequest->getKey(),
            'after' => ['status' => $status, 'type' => $dataRequest->type, 'connections_revoked' => $revoked],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return ApiResponse::success($dataRequest->fresh(), 'Request updated.');
    }
}
