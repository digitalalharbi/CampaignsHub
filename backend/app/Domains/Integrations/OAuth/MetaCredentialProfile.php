<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

/**
 * META-CANDIDATE-001 — which Meta app a credential use belongs to.
 *
 * Exactly two, and deliberately not a general «many apps» feature: the Live app every customer
 * connection runs on, and ONE Candidate app used to prove a Facebook Login for Business round trip
 * before it is promoted. Every Meta credential use names one of these explicitly; nothing infers it.
 */
enum MetaCredentialProfile: string
{
    case Live = 'live';
    case Candidate = 'candidate';
    /*
     * META-SAAS-APP-001 — a THIRD, fully independent Meta app for customer connections (the SaaS app under App Review).
     * Unlike the Candidate it issues real customer connections; unlike a promotion it never replaces Live: each connection
     * is bound to the app that issued its token for the rest of its life (`provider_connections.app_profile`).
     */
    case Saas = 'saas';

    /** The `provider_configurations.provider` row holding this profile's stored values. */
    public function storageKey(): string
    {
        return match ($this) {
            self::Live => 'meta',
            self::Candidate => 'meta.candidate',
            self::Saas => 'meta.saas',
        };
    }

    /** The `config/ad_platforms.php` block holding this profile's environment fallback (Live reads the platform block). */
    public function configRoot(): string
    {
        return match ($this) {
            self::Live => 'ad_platforms.platforms.meta',
            self::Candidate => 'ad_platforms.meta_candidate',
            self::Saas => 'ad_platforms.meta_saas',
        };
    }

    /** The profile a stored connection was issued by; a connection made before profiles existed is Live. */
    public static function ofConnection(?string $stored): self
    {
        return self::tryFrom((string) $stored) ?? self::Live;
    }
}
