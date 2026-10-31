<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use App\Domains\Billing\Gateways\PaymentGatewaySettings;

/**
 * The gateways that take the PLATFORM's money (PAY-001).
 *
 * Identical machinery to `PaymentProviderRegistry`, pointed at a different config file. The contract
 * keeps the four revenue streams separate, and that separation has to reach the configuration: an
 * agency may take its clients' money through one gateway while paying CampaignsHub through another,
 * and a single `billing.default` would make those the same decision.
 */
final class SubscriptionProviderRegistry extends PaymentProviderRegistry
{
    protected function namespace(): string
    {
        return 'subscriptions';
    }

    /**
     * PAY-GATEWAY-CONSOLE-001 — the primary gateway chosen in the platform console, or the environment's when none was chosen.
     * Read per call, so a switch is in effect on the next checkout with no restart.
     */
    public function defaultKey(): string
    {
        return app(PaymentGatewaySettings::class)->primary();
    }
}
