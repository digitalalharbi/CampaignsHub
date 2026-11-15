<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Sends every SMTP message FROM the verified, authenticated platform mailbox (NOTIF-V2-ENGINE-001, spec §4).
 *
 * ## The defect
 *
 * The production example authenticates as `MAIL_USERNAME=info@campaignshub.io` and writes `MAIL_FROM_ADDRESS=no-reply@…`.
 * Hostinger's SMTP accepts mail only from the mailbox that logged in (or an alias it owns); anything else is refused
 * with «553 5.7.1 Sender address rejected: not owned by user». `MailFailure` filed that as a recipient problem and the
 * 554 variant as transient, so the ledgers retried it and the rows sat «queued».
 *
 * ## The fix, in code
 *
 * When the SMTP username is an address and the From differs, the message gets a `Sender` header with the authenticated
 * mailbox. Symfony derives the SMTP envelope (MAIL FROM / Return-Path) from `Sender` when it is present, so the provider
 * sees the mailbox it authenticated, the reader still sees the From and Reply-To they expect, and SPF/DMARC alignment
 * holds because both are on the same domain. A message that already names a Sender is left alone. The production
 * example now also sets `MAIL_FROM_ADDRESS` to the authenticated mailbox; the live `.env` is the Owner's to change.
 */
final class AlignEnvelopeSender
{
    public function handle(MessageSending $event): void
    {
        $sender = self::verifiedSender();
        if ($sender === null || $event->message->getSender() !== null) {
            return;
        }

        $from = $event->message->getFrom()[0] ?? null;
        if ($from !== null && strcasecmp($from->getAddress(), $sender) === 0) {
            return;
        }

        $event->message->sender(new Address($sender));
    }

    /** The authenticated SMTP mailbox, when the default mailer is SMTP and its username is an address. */
    public static function verifiedSender(): ?string
    {
        if (config('mail.align_envelope_sender', true) !== true) {
            return null;
        }
        $mailer = (string) config('mail.default');
        if ((string) config("mail.mailers.{$mailer}.transport") !== 'smtp') {
            return null;
        }
        $username = trim((string) config("mail.mailers.{$mailer}.username"));

        return filter_var($username, FILTER_VALIDATE_EMAIL) !== false ? strtolower($username) : null;
    }
}
