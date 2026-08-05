<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Contracts;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Unified contract every advertising-platform connector (Meta, TikTok, Snapchat, X, LinkedIn,
 * Google Ads, …) must implement. Concrete SDK calls are hidden behind implementations; callers
 * depend only on this interface. Every implementation is exercised by the shared contract test.
 *
 * Connectors that lack real credentials return {@see ConnectorStatus::AwaitingCredentials} and must
 * NOT fabricate success — only the Sandbox connector returns deterministic fake data, and it clearly
 * marks records as sandbox.
 */
interface AdvertisingConnector
{
    /** Stable machine key, e.g. "meta", "tiktok", "sandbox". */
    public function key(): string;

    /** Human label, e.g. "Meta Marketing API". */
    public function label(): string;

    public function status(): ConnectorStatus;

    /** Begin an OAuth authorization flow; returns the provider URL to redirect the user to. */
    public function authorizationUrl(string $state): string;

    /**
     * Exchange the OAuth callback for tokens. Implementations persist tokens securely (never here in
     * source/logs). Returns the resulting connection status.
     *
     * @param  array<string,mixed>  $callback
     */
    public function handleCallback(array $callback): ConnectorStatus;

    public function healthCheck(): HealthResult;

    /** @return array<int,array<string,mixed>> Ad accounts available to the connected identity. */
    public function listAdAccounts(): array;

    public function syncCampaigns(string $adAccountId): SyncResult;

    /**
     * The targeting-and-budget layer beneath a campaign (STRUCT-001).
     *
     * Meta calls it an ad set, Google and TikTok an ad group, Snapchat an ad squad and X a line item.
     * A platform that genuinely has no such level — LinkedIn — returns an EMPTY result rather than one
     * synthesised row per campaign, and its ads hang directly off the campaign instead.
     */
    public function syncAdSets(string $adAccountId): SyncResult;

    /**
     * The ads themselves, each carrying whatever the platform says about its creative.
     *
     * A row names its parents by the PLATFORM's ids (`ad_set_external_id`, `campaign_external_id`),
     * not ours, because the connector has never seen our rows and resolving them here would need a
     * database lookup inside an adapter.
     */
    public function syncAds(string $adAccountId): SyncResult;

    public function syncInsights(string $adAccountId, string $from, string $to): SyncResult;
}
