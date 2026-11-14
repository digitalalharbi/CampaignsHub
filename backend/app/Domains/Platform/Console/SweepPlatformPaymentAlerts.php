<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Platform\Alerts\PlatformAlertInbox;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — tell the platform owner about customers stuck at payment or sign-up.
 *
 * Reads the payment and sign-up records, files new alerts in the platform inbox and emails platform administrators.
 * It asks no gateway anything, moves no money and changes no payment, registration or subscription.
 */
final class SweepPlatformPaymentAlerts extends Command
{
    protected $signature = 'platform:payment-alerts';

    protected $description = 'File payment and sign-up alerts for the platform owner (abandoned checkouts, unverified payments, mismatches, blocking errors).';

    public function handle(PlatformAlertInbox $inbox, TenantContext $tenants): int
    {
        if (config('platform_alerts.enabled', true) !== true) {
            $this->line('Platform payment alerts are switched off (PLATFORM_ALERTS_ENABLED).');

            return self::SUCCESS;
        }

        // Every record read here is platform-owned or read across workspaces for this one administrative purpose.
        $tenants->enterPlatformScope();

        $result = $inbox->sweep();

        app(ScheduledRunRows::class)->report($result['raised'] + $result['grown'] + $result['cleared']);
        $this->line(sprintf(
            'Raised %d, grew %d, cleared %d, mailed %d. Settlement check: %s.',
            $result['raised'], $result['grown'], $result['cleared'], $result['mailed'], $result['settlement_check'],
        ));

        return self::SUCCESS;
    }
}
