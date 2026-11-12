<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Legal\Models\PlatformInboxReply;
use App\Domains\Legal\Models\SupportTicket;
use App\Domains\Notifications\Mail\PlatformReplyMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Requests\Models\ExternalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * PLATFORM-INBOX-ATTENTION-001 — an administrator answers somebody who wrote to the platform from the website.
 *
 * Three things a visitor can be answered about: a contact message, a support ticket, a service ordered from the
 * official site (platform-owned only — a subscriber's request is answered from that subscriber's inbox).
 *
 * The rules, each of which is a test:
 *
 * - **The recipient is the record's own address.** Nobody types an address here, so this cannot be turned into a
 *   way to send mail as CampaignsHub to anyone at all.
 * - **Through the mail ledger.** `TransactionalMailer::queue()`, so the attempt is a `mail_deliveries` row with its
 *   real state (`queued`, `sent`, `awaiting_credentials`, `failed`, `suppressed`, refused) and that state is what
 *   the console shows — never «sent» when it was not (contract S18).
 * - **Kept.** A `platform_inbox_replies` row with the author, the body, the recipient and the ledger key, and an
 *   audit entry that names the record, not the body.
 * - **Rate limited** per record (`requests.platform_inbox.replies_per_record_per_hour`), on top of the route's
 *   per-administrator throttle: a double click or a stuck retry does not mail a visitor five times.
 *
 * A service request's reply is also a client-visible comment, so the visitor reads it on their tracking page.
 */
final class PlatformInboxReplies
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly AuditLogger $audit,
    ) {}

    public function reply(ContactMessage|SupportTicket|ExternalRequest $record, User $author, string $body): PlatformInboxReply
    {
        [$type, $id, $recipient, $about, $reference, $locale] = $this->describe($record);

        // The record must carry an address to answer; nothing here ever takes one from the request.
        abort_if(trim($recipient) === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false, 422, __('api.validation'));

        $key = 'platform-reply:'.$type.':'.$id;
        $perHour = max(1, (int) config('requests.platform_inbox.replies_per_record_per_hour', 5));
        // `hit()` returns the new count: the check and the increment are one step, so two clicks cannot both pass.
        if (RateLimiter::hit($key, 3600) > $perHour) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }

        $reply = DB::transaction(function () use ($record, $type, $id, $recipient, $author, $body): PlatformInboxReply {
            $reply = new PlatformInboxReply;
            $reply->forceFill([
                'id' => (string) Str::uuid(),
                'subject_type' => $type,
                'subject_id' => $id,
                'author_id' => (int) $author->getKey(),
                'recipient' => $recipient,
                'body' => $body,
                'delivery_status' => 'queued',
            ]);
            $reply->mail_dedup_key = 'platform-reply:'.$reply->id;
            $reply->save();

            $this->afterReply($record, $author, $body);

            return $reply;
        });

        $status = $this->mailer->queue(
            recipient: $recipient,
            // No visitor-typed text (their name, their subject) goes into a mail CampaignsHub signs: the address on the
            // record is the visitor's word, so echoing their words to it would let a form become a branded lure.
            mail: new PlatformReplyMail(about: $about, body: $body, lang: $locale, reference: $reference),
            kind: PlatformInboxNotifier::KIND,
            template: 'mail.platform-reply',
            locale: $locale,
            tenantId: null,
            userId: null,
            dedupKey: (string) $reply->mail_dedup_key,
        );

        $reply->forceFill(['delivery_status' => TransactionalMailer::asDeliveryStatus($status)])->save();

        $this->audit->log('platform.inbox.replied', $type, $id, null, [
            'reply_id' => $reply->id,
            'delivery_status' => $reply->delivery_status,
        ], userId: (int) $author->getKey());

        return $reply;
    }

    /** @return list<array{id: string, author: string|null, body: string, delivery_status: string, at: string|null}> */
    public function thread(string $type, string $id): array
    {
        $ids = PlatformInboxReply::query()->where('subject_type', $type)->where('subject_id', $id)
            ->orderBy('created_at')->limit(100)->get();
        $names = User::query()->whereIn('id', $ids->pluck('author_id')->filter()->unique())->pluck('name', 'id');

        // The ledger is the truth about delivery: a reply queued an hour ago reads its row's final state, not «queued».
        $ledger = DB::table('mail_deliveries')->whereIn('dedup_key', $ids->pluck('mail_dedup_key')->filter())->pluck('status', 'dedup_key');

        return $ids->map(fn (PlatformInboxReply $r): array => [
            'id' => (string) $r->id,
            'author' => $r->author_id === null ? null : ($names[$r->author_id] ?? null),
            'body' => (string) $r->body,
            'delivery_status' => isset($ledger[$r->mail_dedup_key])
                ? TransactionalMailer::asDeliveryStatus((string) $ledger[$r->mail_dedup_key])
                : (string) $r->delivery_status,
            'at' => $r->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: string} */
    private function describe(ContactMessage|SupportTicket|ExternalRequest $record): array
    {
        if ($record instanceof ExternalRequest) {
            // Never a subscriber's request: those are answered from the subscriber's own inbox.
            abort_unless($record->isPlatformOwned() && $record->tenant_id === null, 404);

            return ['service_request', (string) $record->id, (string) $record->contact_email,
                (string) $record->type->name_ar, (string) $record->reference, 'ar'];
        }

        if ($record instanceof SupportTicket) {
            $en = $record->locale === 'en';

            return ['support_ticket', (string) $record->getKey(), (string) $record->email,
                $en ? 'Your support ticket' : 'تذكرة الدعم', (string) $record->reference, $en ? 'en' : 'ar'];
        }

        $en = $record->locale === 'en';

        return ['contact_message', (string) $record->getKey(), (string) $record->email,
            $en ? 'Your message from the contact form' : 'رسالتك من نموذج التواصل', null, $en ? 'en' : 'ar'];
    }

    private function afterReply(ContactMessage|SupportTicket|ExternalRequest $record, User $author, string $body): void
    {
        if ($record instanceof ContactMessage) {
            $record->forceFill([
                'status' => in_array($record->status, ['new', 'read'], true) ? 'answered' : $record->status,
                'handled_by' => $author->getKey(),
                'handled_at' => now(),
                'read_at' => $record->read_at ?? now(),
                'read_by' => $record->read_by ?? $author->getKey(),
            ])->save();

            return;
        }

        if ($record instanceof SupportTicket) {
            $record->forceFill([
                'status' => in_array($record->status, ['open', 'in_progress'], true) ? 'waiting_on_customer' : $record->status,
                'assigned_to' => $record->assigned_to ?? $author->getKey(),
                'read_at' => $record->read_at ?? now(),
                'read_by' => $record->read_by ?? $author->getKey(),
            ])->save();

            return;
        }

        // The visitor reads it on their tracking page as well as in their inbox.
        $record->comments()->create([
            'visibility' => 'client',
            'author_id' => $author->getKey(),
            'author_label' => 'CampaignsHub',
            'body' => $body,
        ]);
        $record->events()->create([
            'type' => 'comment',
            'is_client_visible' => true,
            'actor_id' => $author->getKey(),
            'message' => 'Team replied',
            'created_at' => now(),
        ]);
        $record->forceFill(['last_activity_at' => now()])->save();
    }
}
