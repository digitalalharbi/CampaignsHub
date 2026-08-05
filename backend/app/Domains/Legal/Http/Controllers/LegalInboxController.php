<?php

declare(strict_types=1);

namespace App\Domains\Legal\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Models\SupportTicket;
use App\Domains\Legal\Services\DeletionBlockers;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
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
    public function __construct(private readonly DeletionBlockers $blockers) {}

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
            'outstanding' => DataRequest::query()
                ->whereIn('status', ['pending', 'verifying', 'in_review', 'blocked'])->count(),
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

        AuditLog::create([
            'tenant_id' => $dataRequest->tenant_id,
            'user_id' => $request->user()?->id,
            'action' => 'legal.data_request.'.$status,
            'entity_type' => DataRequest::class,
            'entity_id' => (string) $dataRequest->getKey(),
            'after' => ['status' => $status, 'type' => $dataRequest->type],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return ApiResponse::success($dataRequest->fresh(), 'Request updated.');
    }
}
