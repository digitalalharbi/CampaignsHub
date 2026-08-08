<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
 * ## Nothing here retries
 *
 * A failure is recorded and left. Retrying a password reset in a loop mails somebody the same link
 * four times; retrying a broken template mails nobody anything, repeatedly. Re-sending is a decision
 * a person makes — «send it again» is a button, not a background job.
 */
final class TransactionalMailer
{
    public function __construct(private readonly ProviderRegistry $providers) {}

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
        $id = (string) Uuid::uuid4();
        $transport = MailTransportState::current();

        if (! $this->claim($id, $recipient, $kind, $template, $locale, $transport, $tenantId, $userId, $dedupKey)) {
            return 'already_sent';
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

        try {
            Mail::to($recipient)->send($mail);
        } catch (Throwable $e) {
            return $this->finish($id, 'failed', $e->getMessage());
        }

        // A driver that works and reaches nobody is not a delivery. See the class docblock.
        return $transport === MailTransportState::SANDBOX
            ? $this->finish($id, 'sandbox', sentAt: Carbon::now())
            : $this->finish($id, 'sent', sentAt: Carbon::now());
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
    ): bool {
        return DB::table('mail_deliveries')->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'kind' => $kind,
            'recipient' => $recipient,
            'locale' => $locale,
            'template' => $template,
            'status' => 'claimed',
            'transport' => $transport,
            'attempts' => 1,
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

    private function finish(string $id, string $status, ?string $error = null, ?Carbon $sentAt = null): string
    {
        DB::table('mail_deliveries')->where('id', $id)->update([
            'status' => $status,
            'error' => $error,
            'sent_at' => $sentAt,
            'updated_at' => Carbon::now(),
        ]);

        return $status;
    }
}
