<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Support\AdPlatforms;
use InvalidArgumentException;

/**
 * INTEG-OAUTH-001 — what one ad platform's endpoints are, and which keys this install holds for it.
 *
 * The single place that answers "may we call this platform at all?". Everything downstream — the
 * connector's status, the OAuth controller, the setup page, the sync scheduler — asks this object
 * rather than reaching into config, so there is exactly one definition of configured and it cannot
 * drift into a laxer one somewhere convenient.
 *
 * ## Two sources, and which one wins (PROVCFG-001)
 *
 * The PROTOCOL half — authorise URL, token URL, API host — is code, and stays in
 * `config/ad_platforms.php`: it is a fact about the platform, identical on every install, and an
 * operator has no business editing it from a browser.
 *
 * The CREDENTIAL half comes from `ProviderConfigurationService`, which reads what the platform
 * operator entered at `/admin/settings/integrations` and falls back to `.env` only when nothing was
 * entered. That is why this class no longer reads `client_id` out of config directly: an install
 * configured through the console and one configured through the environment must reach exactly the
 * same code path, or the console would be a second, quieter configuration system.
 *
 * `isConfigured()` is deliberately all-or-nothing against the provider's own required-field list in
 * `ProviderCatalogue`. A partial configuration is the dangerous case, not the harmless one: Google Ads
 * with an OAuth client and no developer token authenticates cleanly and is then refused by every API
 * call, which reads to a customer as "connected, and your numbers are zero".
 */
final class PlatformCredentials
{
    /** @param array<string,mixed> $config */
    private function __construct(
        public readonly string $platform,
        private readonly array $config,
    ) {}

    /**
     * @throws InvalidArgumentException when the key is not a provider this product integrates with
     */
    public static function for(string $platform): self
    {
        /*
         * COMMERCE-001 — Salla and Zid come through here too, and deliberately so.
         *
         * They are not advertising platforms, and `ProviderCatalogue` keeps them described as
         * themselves. But «may we call this provider at all?» is the SAME question for a store as for
         * an ad account, and the answer has to have one definition: the moment a second credentials
         * class existed, `isConfigured()` would mean one thing for six providers and something
         * slightly laxer for the other two.
         *
         * So only the protocol half is chosen by kind — `ad_platforms.php` or `commerce_platforms.php`
         * — and everything after that is identical.
         */
        $definition = ProviderCatalogue::has($platform) ? ProviderCatalogue::get($platform) : null;
        $canonical = $definition?->key ?? AdPlatforms::canonical($platform);
        $root = $definition?->kind === ProviderKind::Commerce ? 'commerce_platforms' : 'ad_platforms';

        $config = config("{$root}.platforms.{$canonical}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("No platform configuration for '{$platform}'.");
        }

        $settings = app(ProviderConfigurationService::class);

        // The credential half, overlaid onto the protocol half. `values()` already applied the
        // database-over-environment rule, so a key absent from both arrives here as null and
        // `missing()` names it — rather than the config's own stale `.env` read winning quietly.
        return new self($canonical, [
            ...$config,
            ...$settings->values($canonical),
            'scopes' => $settings->scopes($canonical),
        ]);
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
     * Read from `ProviderCatalogue`, not from this config file. The list used to live in both, and
     * two lists of required keys is one list that is wrong — the admin console would show a field as
     * optional while the connector refused without it.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        return ProviderCatalogue::get($this->platform)->requiredKeys();
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

    public function kind(): ProviderKind
    {
        return ProviderCatalogue::has($this->platform)
            ? ProviderCatalogue::get($this->platform)->kind
            : ProviderKind::Advertising;
    }

    public function isCommerce(): bool
    {
        return $this->kind() === ProviderKind::Commerce;
    }

    /**
     * Where this platform sends the browser back. Registered by hand in each developer console.
     *
     * Delegated to the catalogue rather than built here, because the admin setup page shows the
     * operator the URL to paste into the provider's console from the SAME method. Two constructions of
     * one URL is one URL that is wrong, and the failure is a redirect-mismatch error at the provider's
     * end — which reads to everybody as «the integration is broken» and names nothing.
     */
    public function redirectUri(): string
    {
        return ProviderCatalogue::has($this->platform)
            ? ProviderCatalogue::get($this->platform)->redirectUri()
            : config('ad_platforms.redirect_base').'/api/v1/oauth/ads/'.$this->platform.'/callback';
    }
}
