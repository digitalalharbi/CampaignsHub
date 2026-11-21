<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — how a delivery proves it came from the provider.
 *
 * Declared because the two schemes need different credentials and different comparisons, and
 * `WebhookSignature` is what implements them. The contract test drives that class with exactly what
 * each case names, so a declaration that does not match the verifier fails.
 */
enum WebhookVerification: string
{
    /** Keyed digest over the raw body (Meta, Salla). */
    case HmacSha256 = 'hmac_sha256';

    /** The username and password given when the subscription was created (Zid signs nothing). */
    case HttpBasic = 'http_basic';
}
