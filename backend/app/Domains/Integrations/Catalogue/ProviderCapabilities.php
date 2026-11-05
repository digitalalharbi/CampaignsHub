<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — what a provider's ADAPTER does, declared beside what the provider is.
 *
 * Refresh, webhooks and auth are not repeated here: `ProviderDefinition` already holds them, and a
 * second copy would be a place for the two to disagree. `ProviderDefinition::supports()` answers for
 * both halves, so a caller never needs to know which half a fact lives in.
 *
 * Everything defaults to «no», so a new provider claims only what it builds. Every value below is a
 * statement about the code as it stands, enforced by `ProviderCapabilityContractTest`; what a provider's
 * API could do but this repository does not ask of it is not declared.
 */
final class ProviderCapabilities
{
    /**
     * @param  string  $accountType  how a resource is stored in `external_accounts.account_type`
     * @param  array{key: string, label: string, labelAr: string}|null  $parentLevel  the provider's own
     *                                                                                name for the parent discovery records
     * @param  bool  $parentStep  whether the wizard draws that parent as a step (see `ProviderHierarchy`)
     */
    public function __construct(
        public readonly ResourceType $resourceType,
        public readonly string $accountType,
        public readonly bool $discovery = true,
        public readonly bool $tokenExpiry = false,
        public readonly bool $tokenExchange = false,
        public readonly ?array $parentLevel = null,
        public readonly bool $parentStep = false,
        public readonly bool $entityGrains = false,
        public readonly bool $creativeInsights = false,
        public readonly bool $periodReach = false,
        public readonly bool $abandonedCarts = false,
        public readonly bool $incrementalCursor = false,
        public readonly ?RateLimitPolicy $rateLimit = RateLimitPolicy::PlatformHttpRetry,
        public readonly ?WebhookVerification $webhookVerification = null,
    ) {}
}
