<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

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
}
