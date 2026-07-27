<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors\Contracts;

use App\Domains\Integrations\Connectors\Enums\Capability;
use App\Domains\Integrations\Connectors\Enums\ConnectionState;
use App\Domains\Integrations\Connectors\NullConnector;
use App\Domains\Integrations\Connectors\ValueObjects\SyncWindow;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Unified Connection Center adapter contract. Every provider — real advertising platforms, analytics,
 * e-commerce, storage — is exposed through this one interface so the Connection Center can treat them
 * uniformly while remaining brutally HONEST about what each can actually do:
 *
 *  - {@see self::capabilities()} declares the adapter SURFACE (shape), not a claim that data flows.
 *  - {@see self::hasCredentials()} is the single source of truth for whether a LIVE sync is possible.
 *  - A connector without real credentials must NEVER fabricate success: {@see self::syncMetrics()}
 *    returns a failed/no-op {@see SyncResult} and {@see self::authUrl()} returns null.
 *
 * The only fully-functional adapter shipped here is the Sandbox (clearly-labelled demo data). Real
 * platforms are backed by {@see NullConnector} until credentials
 * are provisioned — an external dependency documented per provider in config/connectors.php.
 */
interface Connector
{
    /** Stable provider key, e.g. "meta_ads", "ga4", "sandbox". */
    public function provider(): string;

    /** Human label, e.g. "Meta Ads". */
    public function label(): string;

    /**
     * Declared capabilities as canonical {@see Capability} string values.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    public function supports(Capability $capability): bool;

    /** Real, usable credentials exist for a live sync. Null-backed connectors always return false. */
    public function hasCredentials(): bool;

    /** Deterministic demo connector (never real platform data). */
    public function isSandbox(): bool;

    /** OAuth authorize URL, or null when OAuth is not configured (awaiting external dependency). */
    public function authUrl(?ProviderConnection $connection = null): ?string;

    /**
     * Exchange an OAuth callback code for tokens and return the resulting honest state. Real connectors
     * without credentials return {@see ConnectionState::AwaitingCredentials} rather than pretending.
     *
     * @param  array<string,mixed>  $params
     */
    public function exchangeCode(string $code, array $params = []): ConnectionState;

    /**
     * Pull metrics for the window. The Sandbox returns clearly-labelled demo records; a Null-backed
     * connector returns a failed no-op {@see SyncResult} — it never fabricates data.
     */
    public function syncMetrics(?ProviderConnection $connection, SyncWindow $window): SyncResult;

    /** Refresh the access token. Returns true on success; Null-backed connectors return false. */
    public function refreshToken(ProviderConnection $connection): bool;
}
