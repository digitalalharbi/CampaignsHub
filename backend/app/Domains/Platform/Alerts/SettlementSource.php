<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

use Illuminate\Support\Carbon;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — where «did this confirmed payment reach our bank?» is answered.
 *
 * The check is written against this interface. Production binds {@see MoyasarSettlementSource}, which reads Moyasar's
 * Settlement API with the gateway secret key the platform already stores (Owner decision 2026-11-13). Without that key
 * the source reports {@see self::BLOCKED}; when Moyasar refuses the key it reports {@see self::REFUSED} — and in both
 * cases the sweep raises nothing, because «we could not look» is never «the money did not arrive».
 */
interface SettlementSource
{
    public const AVAILABLE = 'available';

    /** No settlement source is configured (no key, or the source switched off). */
    public const BLOCKED = 'BLOCKED_EXTERNAL_CREDENTIALS';

    /** The gateway refused the stored key for settlements (401/403); the check is off until it is retried. */
    public const REFUSED = 'refused_by_gateway';

    /**
     * The account has never been settled at all, so «not in a settlement» says nothing about one payment. Shown once to
     * the owner as information; no payment is reported until a settlement history exists.
     */
    public const NO_HISTORY = 'no_settlement_history';

    /** One of the states above. */
    public function state(): string;

    /** True only in the `available` state; the check raises nothing otherwise. */
    public function isAvailable(): bool;

    /**
     * Which of these gateway payment ids appear in a settlement created on or after `$since`.
     *
     * @param  list<string>  $providerPaymentIds
     * @return list<string>|null the settled ids, or null when the source could not be read (never read as «unsettled»)
     */
    public function settled(string $provider, array $providerPaymentIds, Carbon $since): ?array;

    /**
     * What the last successful `settled()` read covered — the evidence an alert states.
     *
     * @return array{settlements: int, latest_at: ?string, since: ?string}
     */
    public function coverage(): array;
}
