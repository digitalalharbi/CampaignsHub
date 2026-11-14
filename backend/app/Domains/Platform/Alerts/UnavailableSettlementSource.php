<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

use Illuminate\Support\Carbon;

/**
 * No settlement source: nothing is claimed about settlements, and the check raises nothing.
 *
 * Bound when `platform_alerts.settlement_source` is not `moyasar`. `BLOCKED_EXTERNAL_CREDENTIALS`.
 */
final class UnavailableSettlementSource implements SettlementSource
{
    public const STATE = SettlementSource::BLOCKED;

    public function state(): string
    {
        return self::BLOCKED;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function settled(string $provider, array $providerPaymentIds, Carbon $since): ?array
    {
        return null;
    }

    public function coverage(): array
    {
        return ['settlements' => 0, 'latest_at' => null, 'since' => null];
    }
}
