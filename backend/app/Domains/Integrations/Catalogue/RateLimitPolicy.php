<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — what this product DOES when a provider throttles it.
 *
 * Not what the provider publishes: `ProviderDefinition::$rateLimitNote` says that. There is one policy
 * in the code today, so there is one case. Adding a case means adding the behaviour, which is what
 * keeps this from becoming a list of intentions.
 */
enum RateLimitPolicy: string
{
    /** `PlatformHttp`: bounded retries on a 429 or a 5xx, honouring `Retry-After` within a ceiling. */
    case PlatformHttpRetry = 'platform_http_retry';
}
