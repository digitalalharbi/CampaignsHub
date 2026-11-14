<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — where «did this confirmed payment reach our bank?» would be answered.
 *
 * Nothing in this codebase holds Moyasar settlement or payout data today, and no client for it exists. Rather than
 * invent an API, the check is written against this interface and the production binding is
 * {@see UnavailableSettlementSource}: it says it cannot answer, and the sweep raises nothing from it. A real source
 * plugs in here once the Owner provides the settlement credentials or export (BLOCKED_EXTERNAL_CREDENTIALS).
 */
interface SettlementSource
{
    /** False while there is no settlement data to read; the check then raises nothing and reports itself blocked. */
    public function isAvailable(): bool;

    /**
     * Which of these gateway payment ids appear in a settlement or payout.
     *
     * @param  list<string>  $providerPaymentIds
     * @return list<string>|null the settled ids, or null when the source could not be read (never read as «unsettled»)
     */
    public function settled(string $provider, array $providerPaymentIds): ?array;
}
