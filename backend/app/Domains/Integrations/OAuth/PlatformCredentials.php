<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Support\AdPlatforms;
use InvalidArgumentException;

/**
 * INTEG-OAUTH-001 — what one ad platform's `config/ad_platforms.php` entry says, read once.
 *
 * The single place that answers "may we call this platform at all?". Everything downstream — the
 * connector's status, the OAuth controller, the setup page, the sync scheduler — asks this object
 * rather than reaching into config, so there is exactly one definition of configured and it cannot
 * drift into a laxer one somewhere convenient.
 *
 * `isConfigured()` is deliberately all-or-nothing against the platform's own `requires` list. A
 * partial configuration is the dangerous case, not the harmless one: Google Ads with an OAuth client
 * and no developer token authenticates cleanly and is then refused by every API call, which reads to a
 * customer as "connected, and your numbers are zero".
 */
final class PlatformCredentials
{
    /** @param array<string,mixed> $config */
    private function __construct(
        public readonly string $platform,
        private readonly array $config,
    ) {}

    /** @throws InvalidArgumentException when the key is not one of the six platforms */
    public static function for(string $platform): self
    {
        $canonical = AdPlatforms::canonical($platform);
        $config = config("ad_platforms.platforms.{$canonical}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("No ad-platform configuration for '{$platform}'.");
        }

        return new self($canonical, $config);
    }

    /** @return list<string> the six platform keys, in the products order */
    public static function keys(): array
    {
        return AdPlatforms::ORDER;
    }

    public function label(): string
    {
        return (string) $this->config['label'];
    }

    public function get(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = $this->config['scopes'] ?? [];

        return $scopes;
    }

    public function authorizeUrl(): string
    {
        return (string) $this->config['authorize_url'];
    }

    public function tokenUrl(): string
    {
        return (string) $this->config['token_url'];
    }

    public function apiBase(): string
    {
        return rtrim((string) $this->config['api_base'], '/');
    }

    /**
     * Everything this platform needs before a single call is worth making.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        /** @var list<string> $requires */
        $requires = $this->config['requires'] ?? ['client_id', 'client_secret'];

        return $requires;
    }

    /**
     * Which required values are absent — the exact list a setup page shows.
     *
     * Returning the names rather than a boolean matters: "awaiting credentials" is not actionable,
     * and «ينقص: developer_token» is.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->requires(), fn (string $key) => $this->get($key) === null));
    }

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }

    /** Where this platform sends the browser back. Registered by hand in each developer console. */
    public function redirectUri(): string
    {
        return config('ad_platforms.redirect_base').'/api/v1/oauth/ads/'.$this->platform.'/callback';
    }
}
