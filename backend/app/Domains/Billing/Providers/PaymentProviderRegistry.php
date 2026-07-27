<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the payment gateway adapter from config (config/billing.php). Every provider key maps to a concrete
 * PaymentProvider class; by default the only entry is the NullPaymentProvider, which reports "not configured".
 * A real gateway is enabled by pointing a config entry at a configured implementation — no call-site changes.
 */
final class PaymentProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    /** @return array<string,string> provider key => provider class */
    private function map(): array
    {
        /** @var array<string,string> $map */
        $map = config('billing.providers', []);

        return $map;
    }

    public function default(): PaymentProvider
    {
        return $this->for($this->defaultKey());
    }

    public function defaultKey(): string
    {
        /** @var string $key */
        $key = config('billing.default', 'null');

        return $key;
    }

    public function for(string $provider): PaymentProvider
    {
        $class = $this->map()[$provider] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("No payment provider configured for [{$provider}].");
        }

        $instance = $this->container->make($class);
        if (! $instance instanceof PaymentProvider) {
            throw new InvalidArgumentException("Provider [{$class}] must implement PaymentProvider.");
        }

        return $instance;
    }

    public function isConfigured(string $provider): bool
    {
        return $this->for($provider)->isConfigured();
    }
}
