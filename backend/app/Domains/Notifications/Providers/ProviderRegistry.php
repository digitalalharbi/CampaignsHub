<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the delivery adapter for a channel from config (config/providers.php). Every channel maps to a
 * concrete MessageProvider class; by default the Null* adapters, which report "not configured". A real
 * provider is enabled by pointing the channel's config entry at a configured implementation — no call site
 * changes.
 */
final class ProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    /** @return array<string,string> channel => provider class */
    private function map(): array
    {
        /** @var array<string,string> $map */
        $map = config('providers.channels', []);

        return $map;
    }

    public function for(string $channel): MessageProvider
    {
        $class = $this->map()[$channel] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("No delivery provider configured for channel [{$channel}].");
        }

        $provider = $this->container->make($class);
        if (! $provider instanceof MessageProvider) {
            throw new InvalidArgumentException("Provider [{$class}] must implement MessageProvider.");
        }

        return $provider;
    }

    public function isConfigured(string $channel): bool
    {
        return $this->for($channel)->isConfigured();
    }
}
