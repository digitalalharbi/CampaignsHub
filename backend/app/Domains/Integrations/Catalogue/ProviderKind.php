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
    /*
     * GA4-INTEGRATION-001 — a measurement source is not an advertising platform.
     *
     * Google Analytics 4 reports what happened on the CLIENT'S OWN SITE. It buys nothing, it has no
     * ad account, no business manager and no campaign hierarchy, and its revenue is measured under
     * a different attribution model from any ad platform's. Putting it in the advertising family
     * would have it counted as a platform in every «which platforms are connected» answer, offered
     * a campaign drill-down it cannot serve, and — worst — blended into a ROAS beside figures that
     * are not comparable with it.
     *
     * A third kind rather than a flag, because every place that branches on the family (the
     * credential file, the callback route, the registries, the Connection Hub grouping) has to make
     * a deliberate decision about it, and an enum makes the compiler ask.
     */
    case Measurement = 'measurement';

    /** The URL segment that keeps the callback families apart. */
    public function routeSegment(): string
    {
        return match ($this) {
            self::Advertising => 'ads',
            self::Commerce => 'commerce',
            self::Measurement => 'measurement',
        };
    }

    /** What the Connection Hub files it under — «Paid Media» is not where a measurement source goes. */
    public function group(): string
    {
        return match ($this) {
            self::Advertising => 'paid_media',
            self::Commerce => 'commerce',
            self::Measurement => 'analytics_measurement',
        };
    }
}
