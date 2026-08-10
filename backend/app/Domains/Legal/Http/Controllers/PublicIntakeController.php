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
use Illuminate\Validation\ValidationException;

/**
 * LEGAL-002 — the three things a visitor can actually send, each landing somewhere a human sees.
 *
 * Public and unauthenticated by necessity: the people most likely to need the contact form are not
 * customers yet, and someone asking for their data to be deleted may well have lost access to the
 * account. Every one is rate limited at the route, and every one carries the honeypot below.
 *
 * ## What each returns
 *
 * A contact message returns nothing to track, because there is nothing to track — it is a message.
 * A support ticket and a data request both return a REFERENCE, because both are things the sender is
 * entitled to follow up on, and «we received it» with no handle is the same as silence.
 */
final class PublicIntakeController extends Controller
{
    public function __construct(private readonly DeletionBlockers $blockers) {}

    /** Real people never fill a hidden field; bots fill every field they find. */
    private function refuseBots(Request $request): void
    {
        if (filled($request->input('website'))) {
            throw ValidationException::withMessages(['website' => 'Rejected.']);
        }
    }

    /** @return array<string, mixed> */
    private function trace(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'locale' => str_starts_with((string) $request->getPreferredLanguage(['ar', 'en']), 'en') ? 'en' : 'ar',
        ];
    }

    public function contact(Request $request): JsonResponse
    {
        $this->refuseBots($request);

        /*
         * LOGIN-HELP-001 — a closed `topic` may stand in for the open `subject` and `message`.
         *
         * The «تواصل معنا» panel on `/login` asks which of five things the sender needs and leaves
         * the details optional, because insisting on ten characters of prose from somebody who has
         * already told us exactly what they want is a form arguing with its own question. The
         * long-form contact page is unchanged: with no topic, both fields stay required.
         */
        $hasTopic = filled($request->input('topic'));

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:160'],
            'topic' => ['nullable', Rule::in(ContactMessage::TOPICS)],
            'source' => ['nullable', Rule::in(ContactMessage::SOURCES)],
            'subject' => $hasTopic
                ? ['nullable', 'string', 'max:200']
                : ['required', 'string', 'min:2', 'max:200'],
            'message' => $hasTopic
                ? ['nullable', 'string', 'max:5000']
                : ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $topic = $data['topic'] ?? null;
        $standIn = $topic === null ? null : ContactMessage::subjectForTopic($topic);

        ContactMessage::create([
            ...$data,
            // A topic with no prose still has to read as a message when a human opens the queue.
            'subject' => filled($data['subject'] ?? null) ? $data['subject'] : $standIn,
            'message' => filled($data['message'] ?? null) ? $data['message'] : $standIn,
            'source' => $data['source'] ?? 'contact_page',
            ...$this->trace($request),
        ]);

        /*
         * No reference is returned, deliberately.
         *
         * Handing back a code implies a queue the sender can chase, and a contact enquiry has no such
         * thing. Somebody reads it and replies by email. Inventing a tracking number for it would be
         * a promise of a process that does not exist.
         */
        return ApiResponse::success([
            'received' => true,
        ], __('api.contact_received'));
    }

    public function support(Request $request): JsonResponse
    {
        $this->refuseBots($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'min:2', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
        ]);

        $user = $request->user();

        $ticket = SupportTicket::create([
            ...$data,
            'category' => $data['category'] ?? 'general',
            'reference' => SupportTicket::makeReference(),
            // Context when we have it: a ticket from a known account saves an exchange establishing who
            // is writing. Absent for a signed-out sender, which is normal rather than suspicious.
            'user_id' => $user?->id,
            'tenant_id' => $user?->currentTenant()?->id,
            ...$this->trace($request),
        ]);

        return ApiResponse::success([
            'reference' => $ticket->reference,
        ], __('api.support_ticket_created'));
    }

    /**
     * A data-subject request, with the blockers evaluated at submission.
     *
     * Evaluated NOW rather than only at review, so the requester learns immediately that an unpaid
     * invoice is standing in the way instead of waiting a day to be told. The operator still reviews
     * it — this is information, not a decision.
     */
    public function dataRequest(Request $request): JsonResponse
    {
        $this->refuseBots($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(DataRequest::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = $request->user();
        $tenantId = $user?->currentTenant()?->id;

        $record = new DataRequest;
        $record->fill([
            ...$data,
            'reference' => DataRequest::makeReference(),
            'user_id' => $user?->id,
            'tenant_id' => $tenantId,
            ...$this->trace($request),
        ]);

        $blockers = $record->isDestructive() ? $this->blockers->forTenant($tenantId ? (string) $tenantId : null) : [];

        /*
         * A destructive request that is blocked opens as `blocked`, not as `pending`.
         *
         * The status is the honest one from the first moment: nothing about this request is waiting
         * on us to look at it — it is waiting on something the requester can go and settle. Calling
         * it pending would put it in the wrong queue and tell the requester nothing.
         */
        $record->status = $blockers !== [] ? 'blocked' : 'pending';
        $record->blockers = $blockers ?: null;
        $record->save();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'action' => 'legal.data_request.submitted',
            'entity_type' => DataRequest::class,
            'entity_id' => (string) $record->getKey(),
            'after' => ['type' => $record->type, 'status' => $record->status, 'blockers' => array_column($blockers, 'code')],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return ApiResponse::success([
            'reference' => $record->reference,
            'status' => $record->status,
            // The reasons travel back so the page can say what is standing in the way, in the
            // requester's own language, rather than a bare «blocked».
            'blockers' => $blockers,
        ], __('api.data_request_received'));
    }
}
