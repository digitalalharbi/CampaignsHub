<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/**
 * The providers CampaignsHub can write to, and nothing else.
 *
 * X, LinkedIn and OpenAI ads are absent on purpose: X carries no write by standing Owner rule, and
 * the other two have no adapter. An absent provider answers every question with NOT_IMPLEMENTED.
 */
final class WriteAdapterRegistry
{
    /** @var array<string, ProviderWriteAdapter> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [];
        foreach ([new MetaWriteAdapter, new GoogleAdsWriteAdapter, new SnapchatWriteAdapter, new TikTokWriteAdapter] as $adapter) {
            $this->adapters[$adapter->provider()] = $adapter;
        }
    }

    public function for(string $provider): ?ProviderWriteAdapter
    {
        return $this->adapters[$provider] ?? null;
    }

    /** @return array<string, ProviderWriteAdapter> */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * Whether the provider's adapter implements ANY action governed by this registry capability —
     * and, when none is implemented, whether that is because the PLATFORM forbids every one.
     *
     * @return 'implemented'|'provider_unsupported'|'not_implemented'
     */
    public function capabilityState(string $provider, string $capability): string
    {
        $adapter = $this->for($provider);
        if ($adapter === null) {
            return 'not_implemented';
        }

        if ($capability === 'create_campaign' || $capability === 'publish') {
            return $adapter->createRefusal() === null ? 'implemented' : (string) $adapter->createRefusal();
        }
        if ($capability === 'ad_set_management') {
            foreach (WriteAction::cases() as $action) {
                if ($adapter->refusal(WriteLevel::AdSet, $action) === null) {
                    return 'implemented';
                }
            }

            return 'not_implemented';
        }
        if ($capability === 'objective') {
            // No supported platform lets a campaign's objective change after creation.
            return 'provider_unsupported';
        }

        $refusals = [];
        foreach (WriteAction::cases() as $action) {
            if ($action->capability() !== $capability) {
                continue;
            }
            foreach (WriteLevel::cases() as $level) {
                $refusal = $adapter->refusal($level, $action);
                if ($refusal === null) {
                    return 'implemented';
                }
                $refusals[] = $refusal;
            }
        }

        return $refusals !== [] && array_unique($refusals) === [WriteRefusal::PROVIDER_UNSUPPORTED]
            ? 'provider_unsupported'
            : 'not_implemented';
    }
}
