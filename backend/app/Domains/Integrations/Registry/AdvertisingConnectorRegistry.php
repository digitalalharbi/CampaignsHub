<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Registry;

use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Providers\GoogleAdsConnector;
use App\Domains\Integrations\Providers\LinkedInConnector;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Integrations\Providers\SnapchatConnector;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\Providers\XConnector;
use App\Domains\Integrations\Sandbox\SandboxAdvertisingConnector;

/**
 * Registry of available advertising connectors. The Sandbox connector is only registered outside
 * production so fake data can never appear on a live deployment.
 */
final class AdvertisingConnectorRegistry
{
    /**
     * Additional keys a connector must answer to, because stored data and connector keys disagree.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'google_ads' => ['google'],
    ];

    /** @var array<string, AdvertisingConnector> */
    private array $connectors = [];

    public function __construct(bool $includeSandbox = true)
    {
        /** @var list<class-string<AdvertisingConnector>> $classes */
        $classes = [
            MetaConnector::class,
            TikTokConnector::class,
            SnapchatConnector::class,
            XConnector::class,
            LinkedInConnector::class,
            GoogleAdsConnector::class,
        ];

        if ($includeSandbox) {
            array_unshift($classes, SandboxAdvertisingConnector::class);
        }

        foreach ($classes as $class) {
            $connector = new $class;
            $this->connectors[$connector->key()] = $connector;

            // Provider keys drifted: the Google connector registers itself as "google_ads" while every
            // stored row (daily_metrics, external_accounts, external_campaigns) uses "google". Without
            // this alias a Google account resolves to NO connector, so its sync is recorded as
            // "no connector registered" instead of the truthful awaiting-credentials state.
            foreach (self::ALIASES[$connector->key()] ?? [] as $alias) {
                $this->connectors[$alias] = $connector;
            }
        }
    }

    /** @return array<string, AdvertisingConnector> */
    public function all(): array
    {
        return $this->connectors;
    }

    public function get(string $key): ?AdvertisingConnector
    {
        return $this->connectors[$key] ?? null;
    }
}
