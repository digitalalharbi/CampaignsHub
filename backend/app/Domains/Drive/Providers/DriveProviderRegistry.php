<?php

declare(strict_types=1);

namespace App\Domains\Drive\Providers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the Drive adapter from config (config/drive.php). Every provider key maps to a concrete
 * DriveProvider class; by default the only live entry is the NullDriveProvider, which reports "not configured".
 *
 * The `drive.sandbox` flag selects the deterministic SandboxDriveProvider for demo / E2E — but ONLY off
 * production, so a misconfiguration can never surface demo files as if they were live in production. A real
 * Google Drive integration is enabled by pointing `drive.default` at a configured implementation; no call-site
 * changes.
 */
final class DriveProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    /** @return array<string,string> provider key => provider class */
    private function map(): array
    {
        /** @var array<string,string> $map */
        $map = config('drive.providers', []);

        return $map;
    }

    public function default(): DriveProvider
    {
        return $this->for($this->defaultKey());
    }

    /**
     * The active provider key. When `drive.sandbox` is enabled off-production, the Sandbox wins so the UI is
     * fully exercisable; otherwise the honest configured default (Null out of the box).
     */
    public function defaultKey(): string
    {
        if ((bool) config('drive.sandbox', false) && ! app()->environment('production')) {
            return 'sandbox';
        }

        /** @var string $key */
        $key = config('drive.default', 'null');

        return $key;
    }

    public function for(string $provider): DriveProvider
    {
        $class = $this->map()[$provider] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("No Drive provider configured for [{$provider}].");
        }

        $instance = $this->container->make($class);
        if (! $instance instanceof DriveProvider) {
            throw new InvalidArgumentException("Provider [{$class}] must implement DriveProvider.");
        }

        return $instance;
    }

    public function isConfigured(string $provider): bool
    {
        return $this->for($provider)->isConfigured();
    }
}
