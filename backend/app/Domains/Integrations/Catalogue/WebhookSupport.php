<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * PROVCFG-001 — whether a provider can PUSH changes to us, answered per provider and honestly.
 *
 * The three cases are genuinely different, and collapsing them into a boolean is how a product ends
 * up shipping a webhook endpoint nobody will ever call and telling an operator to register a URL that
 * the provider has no field for.
 *
 * `RequiresConfirmation` is the case this codebase must not fake. Some providers document change
 * notifications only inside a partner programme, or only for objects other than the ones we read, and
 * no install here holds credentials to check. Claiming `Supported` would tell an operator to rely on
 * deliveries that may never arrive; claiming `PollingOnly` would tell them a capability is absent when
 * it may not be. It says exactly what is true: **the scheduled poll remains the source of truth**, an
 * endpoint exists and verifies its signature so nothing unverified is ever accepted, and the delivery
 * scheme is to be confirmed against the provider's own console when keys arrive.
 */
enum WebhookSupport: string
{
    /** Documented, public, and verified by this codebase — signature checked, event ids de-duplicated. */
    case Supported = 'supported';

    /** The provider offers no inbound change notification for the objects we read; we poll on a timer. */
    case PollingOnly = 'polling_only';

    /** An endpoint exists and verifies, but the poll stays authoritative until the scheme is confirmed. */
    case RequiresConfirmation = 'requires_confirmation';

    /** Whether an endpoint is exposed at all — true for both the confirmed and the unconfirmed case. */
    public function hasEndpoint(): bool
    {
        return $this !== self::PollingOnly;
    }

    /** Whether deliveries may be treated as authoritative, i.e. whether they can stand in for a poll. */
    public function isAuthoritative(): bool
    {
        return $this === self::Supported;
    }
}
