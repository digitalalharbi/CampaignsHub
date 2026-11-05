<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Jobs\SendQueuedMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * One way to send a message about somebody's ACCOUNT, and one place that records what happened —
 * MAIL-009.
 *
 * ## The defect this exists to remove
 *
 * `InvitationService::invite()` and `RegistrationVerificationService::send()` both wrote
 * `'awaiting_provider_credentials'` as a literal and composed no message at all. That was a true
 * statement about an install with no mailer — and an unconditional one. Wire real SMTP tomorrow and
 * both would keep reporting the same thing, no invitation would arrive, and the status column would
 * say the system knew. Honesty that cannot change when the world changes is just a constant.
 *
 * Here the status is the OUTCOME. `sent` is written after `Mail::send()` returns without throwing and
 * at no other time.
 *
 * ## Four states, and why `sandbox` is not `sent`
 *
 * - `awaiting_credentials` — the channel reports no provider. Nothing was attempted.
 * - `sandbox` — the driver is `log`, `array` or `null`: the send succeeds and reaches nobody. Written
 *   as its own state because an install that calls this `sent` is one where a developer's local run
 *   and a customer's production run are indistinguishable in the ledger.
 * - `sent` — a real transport accepted it.
 * - `failed` — it threw, and the transport's own message is kept.
 *
 * ## Exactly once, by index
 *
 * A caller that can name what it is sending — invitation `01J…`, the reset token's own hash — passes a
 * `dedupKey`, and the unique index refuses the second insert. The row is claimed BEFORE the send, so
 * a crash between transport and ledger leaves evidence that an attempt happened rather than a clean
 * slate that invites a repeat. This is the same shape as `DigestDispatcher`, for the same reason.
 *
 * ## `send()` does not retry; `queue()` does — EMAIL-DELIVERY-LEDGER-001
 *
 * A message carrying a secret — a code, a reset link, an invitation token — never goes near a queue.
 * The payload would sit in Redis and in Horizon's failed-job screen in the clear, and the person
 * waiting for it can simply ask again. So `send()` is one attempt, recorded, and `queue()` refuses
 * those kinds outright.
 *
 * A notice with nothing secret in it (a welcome, a role change, a reviewer's reminder) goes through
 * `queue()`: a `queued` row, a job, three tries with a backoff, and exactly one message however often
 * the queue redelivers the job, because each try must first win the row.
 *
 * ## Security mail and optional mail — §17.3.4
 *
 * The catalogue says which a kind is. An optional kind honours the person's switches and is recorded
 * `suppressed`; a mandatory kind never asks, so no preference, channel switch or verification-channel
 * setting can silence it.
 *
 * ## Unsubscribes and the daily limit — EMAIL-GAPS
 *
 * `MailSendGate` is asked at the last moment on both paths, so a recipient who leaves while a message
 * waits in the queue does not receive it. A refusal is a row with a status and a reason. A retry is not
 * charged to the allowance again, because it is the same message.
 *
 * ## A test message — EMAIL-TEST-SEND-001
 *
 * `TEST_KIND` belongs to no tenant, by construction: this class refuses one attributed to a tenant, so it
 * cannot reach a workspace's log, its daily allowance or its unsubscribes, and refuses to queue one, since
 * the person who asked is waiting for the transport's answer. Readers that a customer sees, or that decide
 * something for them, leave `TEST_KIND` rows out by name.
 *
 * ## Where the outcome also lands
 *
 * `report_deliveries` and `client_notifications` are what a screen reads. Rows there carrying this
 * row's dedup key in `mail_dedup_key` get every final state written back, so they never sit at `queued`
 * after the mail has been decided.
 */
final class TransactionalMailer
{
    /** The ledger's name for a platform admin's test message. See the class docblock. */
    public const TEST_KIND = 'test';

    /** One try and two retries; the backoff is what separates them. */
    public const MAX_ATTEMPTS = 3;

    /** Minutes to wait after the first and second failure. */
    public const BACKOFF_MINUTES = [1, 15];

    /** The tables whose `status` follows this ledger through `mail_dedup_key`. */
    private const FOLLOWERS = ['report_deliveries', 'client_notifications'];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly NotificationChoices $choices,
        private readonly MailSendGate $gate,
        private readonly MailUnsubscribes $unsubscribes,
    ) {}

    /**
     * Send one account message, and record the attempt whatever the outcome.
     *
     * @param  string  $kind  `password_reset` · `invitation` · `email_verification` · `security_alert` · `sign_in_code`
     * @param  string|null  $dedupKey  names the THING being sent, when the caller has such a name
     * @return string the state written to the ledger
     */
    public function send(
        string $recipient,
        Mailable $mail,
        string $kind,
        string $template,
        string $locale = 'ar',
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $dedupKey = null,
    ): string {
        if ($kind === self::TEST_KIND && $tenantId !== null) {
            throw new InvalidArgumentException('A test message belongs to no tenant.');
        }

        $id = (string) Uuid::uuid4();
        $transport = MailTransportState::current();

        if (! $this->claim($id, $recipient, $kind, $template, $locale, $transport, $tenantId, $userId, $dedupKey)) {
            return 'already_sent';
        }

        if ($this->switchedOff($kind, $tenantId, $userId)) {
            return $this->finish($id, 'suppressed');
        }

        /*
         * The channel is asked, not the mail config.
         *
         * `ProviderRegistry` is what the rest of this product treats as the answer to «can we email?»,
         * and it defaults to an adapter that reports no. Reading `mail.default` here instead would
         * make this class disagree with the digest about the same install.
         */
        if (! $this->providers->isConfigured('email')) {
            return $this->finish($id, 'awaiting_credentials');
        }

        $scope = MailUnsubscribes::scopeOf($kind);
        $refusal = $this->gate->refusal($tenantId, $recipient, $scope);

        if ($refusal !== null) {
            return $this->finish($id, $refusal);
        }

        $this->offerTheWayOut($mail, $tenantId, $recipient, $scope, $userId);

        DB::table('mail_deliveries')->where('id', $id)->update(['attempted_at' => Carbon::now()]);

        try {
            $sent = Mail::to($recipient)->send($mail);
        } catch (Throwable $e) {
            $failure = MailFailure::from($e);

            return $this->finish($id, 'failed', $failure['safe'], failureCode: $failure['code']);
        }

        return $this->finishAccepted($id, $transport, $sent);
    }

    /**
     * A notice with no secret in it, sent by a worker with retries. Returns the row's state now: `queued`
     * on a real queue, the outcome on the `sync` one.
     */
    public function queue(
        string $recipient,
        Mailable $mail,
        string $kind,
        string $template,
        string $locale = 'ar',
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $dedupKey = null,
    ): string {
        $id = $this->enqueue($recipient, $mail, $kind, $template, $locale, $tenantId, $userId, $dedupKey);

        if ($id === null) {
            return 'already_sent';
        }

        $status = (string) DB::table('mail_deliveries')->where('id', $id)->value('status');

        if ($status === 'queued') {
            $this->offerTheWayOut($mail, $tenantId, $recipient, MailUnsubscribes::scopeOf($kind), $userId);

            /*
             * A queue that cannot be reached is this message failing, not the caller's work failing:
             * the role change or the sign-in that asked for it has already happened.
             */
            try {
                SendQueuedMail::dispatch($id, $mail)->afterCommit();
            } catch (Throwable $e) {
                $this->abandon($id, $e);
            }

            $status = (string) DB::table('mail_deliveries')->where('id', $id)->value('status');
        }

        return $status;
    }

    /**
     * Record that this message is waiting, deciding only what can be decided without a worker.
     *
     * @return string|null the row's id; null when this exact message was already recorded
     */
    public function enqueue(
        string $recipient,
        Mailable $mail,
        string $kind,
        string $template,
        string $locale = 'ar',
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $dedupKey = null,
    ): ?string {
        if (MessageCatalogue::carriesSecret($kind)) {
            throw new InvalidArgumentException("«{$kind}» carries a secret and may not be queued; use send().");
        }

        if ($kind === self::TEST_KIND) {
            throw new InvalidArgumentException('A test message is one attempt whose answer goes back to the person waiting; use send().');
        }

        $id = (string) Uuid::uuid4();

        if (! $this->claim($id, $recipient, $kind, $template, $locale, MailTransportState::current(), $tenantId, $userId, $dedupKey, 'queued', 0)) {
            return null;
        }

        if ($this->switchedOff($kind, $tenantId, $userId)) {
            $this->finish($id, 'suppressed');
        } elseif (! $this->providers->isConfigured('email')) {
            $this->finish($id, 'awaiting_credentials');
        } elseif (($refusal = $this->gate->refusal($tenantId, $recipient, MailUnsubscribes::scopeOf($kind), charge: false)) !== null) {
            $this->finish($id, $refusal);
        }

        return $id;
    }

    /**
     * One try at a queued row: `sent`/`sandbox`/`failed`, `retry` when the queue should come back, or
     * `already_sent` when another try already finished it.
     *
     * The row is WON first, by an update that only matches `queued`. Two workers, or a job the queue
     * redelivered after a lost acknowledgement, cannot both win, which is what makes a retry safe. A
     * worker that dies between winning and finishing leaves `claimed`, and that is never re-sent: a
     * missed notice is recoverable, and a duplicate cannot be recalled.
     */
    public function deliverQueued(string $id, Mailable $mail): string
    {
        $won = DB::table('mail_deliveries')->where('id', $id)->where('status', 'queued')->update([
            'status' => 'claimed',
            'attempts' => DB::raw('attempts + 1'),
            'attempted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        if ($won === 0) {
            return 'already_sent';
        }

        $row = DB::table('mail_deliveries')->where('id', $id)->first();

        $refusal = $this->gate->refusal(
            $row->tenant_id === null ? null : (string) $row->tenant_id,
            (string) $row->recipient,
            MailUnsubscribes::scopeOf((string) $row->kind),
            charge: (int) $row->attempts === 1,
        );

        if ($refusal !== null) {
            return $this->finish($id, $refusal);
        }

        try {
            $sent = Mail::to((string) $row->recipient)->send($mail);
        } catch (Throwable $e) {
            $failure = MailFailure::from($e);
            $attempts = (int) $row->attempts;

            if ($attempts >= self::MAX_ATTEMPTS || MailFailure::isPermanent($failure['code'])) {
                return $this->finish($id, 'failed', $failure['safe'], failureCode: $failure['code']);
            }

            DB::table('mail_deliveries')->where('id', $id)->update([
                'status' => 'queued',
                'error' => $failure['safe'],
                'failure_code' => $failure['code'],
                'next_attempt_at' => Carbon::now()->addMinutes(self::BACKOFF_MINUTES[$attempts - 1] ?? 15),
                'updated_at' => Carbon::now(),
            ]);

            return 'retry';
        }

        return $this->finishAccepted($id, (string) $row->transport, $sent);
    }

    /** The worker gave up without an outcome of ours — killed, or out of tries. A waiting row must not wait forever. */
    public function abandon(string $id, Throwable $e): void
    {
        $failure = MailFailure::from($e);

        DB::table('mail_deliveries')->where('id', $id)->whereIn('status', ['queued', 'claimed'])->update([
            'status' => 'failed',
            'error' => $failure['safe'],
            'failure_code' => $failure['code'],
            'next_attempt_at' => null,
            'updated_at' => Carbon::now(),
        ]);

        $this->tellFollowers($id, 'failed', $failure['safe']);
    }

    /**
     * Record that a message was refused before it was composed, with the reason as its status.
     *
     * @return string the status, or `already_sent` when this exact message was already recorded
     */
    public function refuse(string $recipient, string $status, string $kind, string $template, string $locale, ?string $tenantId, ?int $userId, ?string $dedupKey): string
    {
        $id = (string) Uuid::uuid4();

        if (! $this->claim($id, $recipient, $kind, $template, $locale, MailTransportState::current(), $tenantId, $userId, $dedupKey, $status, 0)) {
            return 'already_sent';
        }

        return $this->finish($id, $status);
    }

    private function offerTheWayOut(Mailable $mail, ?string $tenantId, string $recipient, ?string $scope, ?int $userId): void
    {
        if (method_exists($mail, 'offerUnsubscribe')) {
            $mail->offerUnsubscribe($this->unsubscribes->url($tenantId, $recipient, $scope, $userId));
        }
    }

    /** @see self::FOLLOWERS */
    private function tellFollowers(string $id, string $status, ?string $error): void
    {
        $key = DB::table('mail_deliveries')->where('id', $id)->value('dedup_key');

        if ($key === null) {
            return;
        }

        foreach (self::FOLLOWERS as $table) {
            DB::table($table)->where('mail_dedup_key', $key)->update(['status' => self::asDeliveryStatus($status), 'error' => $error]);
        }
    }

    /** Has the person this is for switched this kind off? A mandatory or unknown kind never has. */
    private function switchedOff(string $kind, ?string $tenantId, ?int $userId): bool
    {
        $type = MessageCatalogue::typeOfKind($kind);

        if ($type === null || $tenantId === null || $userId === null || MessageCatalogue::isMandatory($type)) {
            return false;
        }

        return ! $this->choices->wants($userId, $tenantId, $type, 'email');
    }

    /** A driver that works and reaches nobody is not a delivery. See the class docblock. */
    private function finishAccepted(string $id, string $transport, mixed $sent): string
    {
        return $transport === MailTransportState::SANDBOX
            ? $this->finish($id, 'sandbox', sentAt: Carbon::now())
            : $this->finish($id, 'sent', sentAt: Carbon::now(), acceptedAt: Carbon::now(), messageId: self::messageId($sent));
    }

    /** The transport's Message-ID, when it gave one. A fake or a mock returns nothing, and that is fine. */
    public static function messageId(mixed $sent): ?string
    {
        // `is_callable`, not `method_exists`: Laravel's SentMessage forwards this through `__call`, so the
        // older check answered no for every real send and the ledger never held a Message-ID.
        if (! is_object($sent) || ! is_callable([$sent, 'getMessageId'])) {
            return null;
        }

        return mb_substr((string) $sent->getMessageId(), 0, 190) ?: null;
    }

    /**
     * Claim the row, or report that this exact message has already been attempted.
     *
     * ## `insertOrIgnore`, and why catching the exception was wrong
     *
     * The obvious version is an `insert` inside a `try`, treating the unique violation as the answer.
     * On PostgreSQL that is a trap: a failed statement inside a transaction ABORTS the transaction,
     * and every subsequent query on that connection answers `25P02 — current transaction is aborted`
     * until somebody rolls back. Catching the exception hides the throw and leaves the caller holding
     * a connection that can no longer do anything — so a duplicate invitation would not merely be
     * skipped, it would break whatever ran after it inside the same transaction.
     *
     * `insertOrIgnore` emits `ON CONFLICT DO NOTHING`, which is not an error at all: the statement
     * succeeds, the row is not written, and the affected count is zero. Same exactly-once guarantee,
     * no aborted transaction, and it holds whether or not a caller wrapped this in one.
     *
     * Found by a test that ran two identical sends and then counted the rows.
     */
    private function claim(
        string $id,
        string $recipient,
        string $kind,
        string $template,
        string $locale,
        string $transport,
        ?string $tenantId,
        ?int $userId,
        ?string $dedupKey,
        string $status = 'claimed',
        int $attempts = 1,
    ): bool {
        return DB::table('mail_deliveries')->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'kind' => $kind,
            'recipient' => $recipient,
            'locale' => $locale,
            'template' => $template,
            'status' => $status,
            'transport' => $transport,
            'attempts' => $attempts,
            'dedup_key' => $dedupKey,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]) > 0;
    }

    /**
     * This class's state, in the vocabulary the older `delivery_status` columns already use.
     *
     * Two vocabularies exist in this codebase and both are load-bearing. `awaiting_credentials` is
     * what a PROVIDER or an integration reports, and what `digest_sends.status` and this ledger use.
     * `awaiting_provider_credentials` is what the `delivery_status` column on
     * `registration_verifications`, `workspace_invitations`, `contact_verifications` and
     * `client_notifications` has always used — it is on the wire, and `AccountStatusPage` asserts it.
     *
     * Converging them by changing the wire value would be a rename dressed as a cleanup: it would
     * touch four tables and a frontend contract to fix nothing a user can see. The boundary is here.
     */
    public static function asDeliveryStatus(string $state): string
    {
        return $state === 'awaiting_credentials' ? 'awaiting_provider_credentials' : $state;
    }

    private function finish(
        string $id,
        string $status,
        ?string $error = null,
        ?Carbon $sentAt = null,
        ?string $failureCode = null,
        ?Carbon $acceptedAt = null,
        ?string $messageId = null,
    ): string {
        DB::table('mail_deliveries')->where('id', $id)->update([
            'status' => $status,
            'error' => $error,
            'failure_code' => $failureCode,
            'sent_at' => $sentAt,
            'accepted_at' => $acceptedAt,
            'provider_message_id' => $messageId,
            'next_attempt_at' => null,
            'updated_at' => Carbon::now(),
        ]);

        $this->tellFollowers($id, $status, $error);

        return $status;
    }
}
