<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management;

use App\Domains\Campaigns\Management\Write\WriteAdapterRegistry;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Support\AdPlatforms;

/**
 * CAMPAIGN-MGMT-DOMAIN-001 — the one place a provider write is allowed to exist.
 *
 * CampaignsHub is becoming a control plane as well as a monitor, and the first rule of that is the
 * Owner's: never expose a write action for a provider unless that exact write capability is
 * implemented and permission-gated. This registry is where «implemented» is written down, per
 * provider and per capability, and {@see self::allowed()} is the gate every surface asks before it
 * draws a button.
 *
 * Four statuses, in the order a capability passes through them:
 *
 *   * NOT_IMPLEMENTED — nothing calls the provider; the surface draws nothing.
 *   * IMPLEMENTED_NOT_VERIFIED — the call exists and is tested against fixtures; the surface may
 *     draw it, labelled as unverified, behind the permission.
 *   * AWAITING_CREDENTIALS — implemented, and the round trip that would verify it needs provider
 *     credentials this install does not hold.
 *   * VERIFIED — a real Production write round-trip was observed on the provider, and the evidence
 *     reference (the write's own id on the provider, with the date) is recorded here. A VERIFIED
 *     entry with no evidence is refused by construction — see {@see self::entries()}.
 *
 * Today every cell is NOT_IMPLEMENTED. That is the honest state, and the registry exists so that it
 * is stated once rather than discovered surface by surface.
 */
final class WriteCapabilityRegistry
{
    public const NOT_IMPLEMENTED = 'not_implemented';

    public const IMPLEMENTED_NOT_VERIFIED = 'implemented_not_verified';

    public const AWAITING_CREDENTIALS = 'awaiting_credentials';

    public const VERIFIED = 'verified';

    /**
     * CAMPAIGN-MGMT-WRITE-001 — the PLATFORM does not allow this (Google has no copy endpoint; no
     * platform lets an objective change after creation). Distinct from NOT_IMPLEMENTED, which is a
     * fact about CampaignsHub: the interface must never send an operator to a platform's own screen
     * for something the platform forbids, nor tell them the platform forbids what we simply lack.
     */
    public const PROVIDER_UNSUPPORTED = 'provider_unsupported';

    /** The write capabilities the Owner named, each with the tenant permission that gates it. */
    public const CAPABILITIES = [
        'create_campaign' => 'campaigns.create',
        'edit_campaign' => 'campaigns.update',
        'pause_resume' => 'campaigns.pause',
        'schedule' => 'campaigns.update',
        'budget_change' => 'campaigns.budget.change',
        'bid_strategy' => 'campaigns.update',
        'objective' => 'campaigns.update',
        'ad_set_management' => 'campaigns.update',
        'ad_creation' => 'campaigns.create',
        'creative_binding' => 'campaigns.update',
        'targeting' => 'campaigns.update',
        'placements' => 'campaigns.update',
        'publish' => 'campaigns.launch',
        'duplicate' => 'campaigns.create',
        // CAMPAIGN-MGMT-WRITE-001 — archive or delete, in the provider's own semantics; confirmed in the interface.
        'remove' => 'campaigns.update',
    ];

    /**
     * What is implemented, per provider. A capability absent here is NOT_IMPLEMENTED.
     *
     * Shape: provider → capability → ['status' => …, 'evidence' => ?string]. `evidence` is the
     * provider-side reference of the Production write that verified the capability, and it is
     * REQUIRED for VERIFIED — the registry refuses to report a verified capability without it.
     *
     * A method rather than a constant: an empty constant is `array{}` to static analysis, which then
     * refuses every offset read on it — and the day the first write is admitted, this is where it is
     * declared, with its evidence beside it.
     *
     * @return array<string, array<string, array{status: string, evidence: ?string}>>
     */
    private static function declared(): array
    {
        /*
         * CAMPAIGN-MGMT-WRITE-001 — derived from the adapters, never typed by hand.
         *
         * A capability is IMPLEMENTED_NOT_VERIFIED when the provider's adapter performs at least one
         * action it governs, and AWAITING_CREDENTIALS while the platform's own app credentials are
         * missing on this installation (no write can reach the platform without them). VERIFIED is
         * not derivable: it needs a Production round-trip on record, which no adapter can claim.
         */
        $adapters = app(WriteAdapterRegistry::class);
        $out = [];
        foreach ($adapters->all() as $provider => $adapter) {
            $configured = self::platformConfigured($provider);
            foreach (array_keys(self::CAPABILITIES) as $capability) {
                $state = $adapters->capabilityState($provider, $capability);
                $out[$provider][$capability] = [
                    'status' => match ($state) {
                        'implemented' => $configured ? self::IMPLEMENTED_NOT_VERIFIED : self::AWAITING_CREDENTIALS,
                        'provider_unsupported' => self::PROVIDER_UNSUPPORTED,
                        default => self::NOT_IMPLEMENTED,
                    },
                    'evidence' => null,
                ];
            }
        }

        return $out;
    }

    private static function platformConfigured(string $provider): bool
    {
        try {
            return PlatformCredentials::for($provider)->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every provider × every capability, with its status and the permission that gates it.
     *
     * @return list<array{provider: string, capability: string, status: string, permission: string, evidence: ?string}>
     */
    public static function entries(): array
    {
        $out = [];
        foreach (AdPlatforms::ORDER as $provider) {
            foreach (self::CAPABILITIES as $capability => $permission) {
                $out[] = self::entry($provider, $capability, $permission, self::declared()[$provider][$capability] ?? null);
            }
        }

        return $out;
    }

    /**
     * The gate: may THIS user see and press THIS write on THIS provider?
     *
     * Implemented (not merely declared) AND permitted. A permission alone never opens a write the
     * code cannot perform; an implementation alone never opens a write the role may not perform.
     */
    public static function allowed(string $provider, string $capability, callable $hasPermission): bool
    {
        $permission = self::CAPABILITIES[$capability] ?? null;
        if ($permission === null) {
            return false;
        }
        $canonical = AdPlatforms::canonical($provider);
        $entry = self::entry($canonical, $capability, $permission, self::declared()[$canonical][$capability] ?? null);

        return in_array($entry['status'], [self::IMPLEMENTED_NOT_VERIFIED, self::VERIFIED], true) && (bool) $hasPermission($permission);
    }

    /**
     * @param  array{status: string, evidence: ?string}|null  $declared
     * @return array{provider: string, capability: string, status: string, permission: string, evidence: ?string}
     */
    private static function entry(string $provider, string $capability, string $permission, ?array $declared): array
    {
        $status = $declared['status'] ?? self::NOT_IMPLEMENTED;
        $evidence = $declared['evidence'] ?? null;

        /*
         * VERIFIED without evidence is not a status, it is a claim — and this registry does not carry
         * claims. It is demoted to IMPLEMENTED_NOT_VERIFIED, which is what it actually is.
         */
        if ($status === self::VERIFIED && ($evidence === null || trim($evidence) === '')) {
            $status = self::IMPLEMENTED_NOT_VERIFIED;
        }

        return [
            'provider' => $provider,
            'capability' => $capability,
            'status' => $status,
            'permission' => $permission,
            'evidence' => $status === self::VERIFIED ? $evidence : null,
        ];
    }

    /**
     * The registry as a declared table — for the test that proves the demotion by injection.
     *
     * @param  array<string, array<string, array{status: string, evidence: ?string}>>  $declared
     * @return list<array{provider: string, capability: string, status: string, permission: string, evidence: ?string}>
     */
    public static function entriesFrom(array $declared): array
    {
        $out = [];
        foreach (AdPlatforms::ORDER as $provider) {
            foreach (self::CAPABILITIES as $capability => $permission) {
                $out[] = self::entry($provider, $capability, $permission, $declared[$provider][$capability] ?? null);
            }
        }

        return $out;
    }
}
