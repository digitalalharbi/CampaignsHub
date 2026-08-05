<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * PROVCFG-001 — what a provider is FOR, which decides what connecting one even means.
 *
 * An advertising provider is connected to discover AD ACCOUNTS; a commerce provider is connected to
 * discover STORES. They share the OAuth machinery and nothing after it, so the two callbacks and the
 * two webhook receivers live on separate routes rather than behind one endpoint with a branch in it.
 */
enum ProviderKind: string
{
    case Advertising = 'advertising';
    case Commerce = 'commerce';

    /** The URL segment that keeps the two callback families apart. */
    public function routeSegment(): string
    {
        return match ($this) {
            self::Advertising => 'ads',
            self::Commerce => 'commerce',
        };
    }
}
