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

    /** The `provider_configurations.provider` row holding this profile's stored values. */
    public function storageKey(): string
    {
        return match ($this) {
            self::Live => 'meta',
            self::Candidate => 'meta.candidate',
        };
    }
}
