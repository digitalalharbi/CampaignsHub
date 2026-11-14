<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

/**
 * The honest production binding: no settlement data exists here, so nothing is claimed about it.
 *
 * `BLOCKED_EXTERNAL_CREDENTIALS` — see {@see SettlementSource}.
 */
final class UnavailableSettlementSource implements SettlementSource
{
    public const STATE = 'BLOCKED_EXTERNAL_CREDENTIALS';

    public function isAvailable(): bool
    {
        return false;
    }

    public function settled(string $provider, array $providerPaymentIds): ?array
    {
        return null;
    }
}
