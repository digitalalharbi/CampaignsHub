<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * What a stored status means to a reader — EMAIL-DELIVERY-LEDGER-001 (§17.4.4).
 *
 * The ledgers store `sent` after a transport returned without throwing. That word overclaimed on every
 * screen that printed it: the receiving server took the message and nobody knows what happened next.
 * Renaming the stored value would touch four `delivery_status` columns and a wire contract to fix
 * nothing a person sees (the reasoning `TransactionalMailer::asDeliveryStatus` already records), so
 * the honest words live here, once, and every surface reads through this.
 *
 * - `queued` — waiting for a worker or a retry; nothing has been tried for this attempt.
 * - `attempted` — claimed and in flight; a crash here leaves this state, and it is never re-sent.
 * - `accepted` — the transport took it. This is NOT receipt.
 * - `delivered` — a recorded piece of evidence says a person or a provider confirmed it. Only
 *   `mail:confirm-delivery` writes that; no job and no transport can.
 * - `failed` — it did not leave, with a named reason.
 * - `suppressed` — deliberately not sent: no provider, a sandbox driver, a preference, nothing to report.
 */
final class MailDeliveryState
{
    public const QUEUED = 'queued';

    public const ATTEMPTED = 'attempted';

    public const ACCEPTED = 'accepted';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const SUPPRESSED = 'suppressed';

    public const ALL = [self::QUEUED, self::ATTEMPTED, self::ACCEPTED, self::DELIVERED, self::FAILED, self::SUPPRESSED];

    public static function of(string $status, mixed $deliveredAt = null): string
    {
        return match ($status) {
            'queued' => self::QUEUED,
            'claimed' => self::ATTEMPTED,
            // Evidence only ever promotes something a transport accepted.
            'sent' => $deliveredAt !== null ? self::DELIVERED : self::ACCEPTED,
            'failed' => self::FAILED,
            default => self::SUPPRESSED,
        };
    }

    /** Why a message was suppressed, in a stable word a screen can translate. Null for every other state. */
    public static function suppressionReason(string $status): ?string
    {
        return match ($status) {
            'awaiting_credentials', 'awaiting_provider_credentials' => 'no_provider',
            'sandbox' => 'sandbox_transport',
            'skipped' => 'nothing_to_report',
            'suppressed' => 'preference',
            default => null,
        };
    }
}
