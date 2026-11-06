<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — the optional things a provider may be able to do (§1.8).
 *
 * The question a caller asks, instead of `instanceof SnapchatConnector` or `$provider === 'zid'`:
 * `ProviderCatalogue::supports($provider, ProviderCapability::AbandonedCarts)`. An enum so a misspelt
 * capability does not compile into a quiet «no», and so `ProviderDefinition::supports()` has to answer
 * for every case the day one is added.
 *
 * None is required of anyone. `ProviderCapabilityContractTest` holds each declaration to the code in
 * both directions: a provider may not claim what it does not implement, nor implement what it did not
 * claim.
 */
enum ProviderCapability: string
{
    /** Listing what the authorised identity can reach, through the engine's discovery path. */
    case Discovery = 'discovery';

    /** A `grant_type=refresh_token` round trip. */
    case RefreshToken = 'refresh_token';

    /** The stored access token has an expiry this product must act on. */
    case TokenExpiry = 'token_expiry';

    /** Renewed by exchanging the live token, not with a refresh token (Meta's `fb_exchange_token`). */
    case TokenExchange = 'token_exchange';

    /** An inbound endpoint exists and verifies deliveries (`WebhookSupport::hasEndpoint()`). */
    case Webhooks = 'webhooks';

    /** Discovered resources carry the provider parent they sit under. */
    case Hierarchy = 'hierarchy';

    /** Ad-set and ad metric grains (`ReportsEntityGrains`). */
    case EntityGrains = 'entity_grains';

    /** Creative-level stats (`ReportsCreativeInsights`). */
    case CreativeInsights = 'creative_insights';

    /** Reach deduplicated over a window (`ReportsPeriodReach`). */
    case PeriodReach = 'period_reach';

    /** The store platform publishes abandoned carts. */
    case AbandonedCarts = 'abandoned_carts';

    /** Reads forward from a stored position rather than re-asking a window (`ReadsIncrementally`). */
    case IncrementalCursor = 'incremental_cursor';

    /** Throttling is handled by a named policy (`RateLimitPolicy`). */
    case RateLimitPolicy = 'rate_limit_policy';
}
