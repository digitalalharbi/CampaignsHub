<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;

/**
 * WEBHOOK-001 — is this delivery really from the provider?
 *
 * ## Three rules, and each of them has been somebody's incident
 *
 * 1. **The RAW body, never the parsed one.** An HMAC is over bytes. `json_encode(json_decode($body))`
 *    reorders keys, changes float formatting and re-escapes unicode, so a signature computed over it
 *    fails for a delivery that was perfectly valid — and, far worse, tempts whoever is debugging it
 *    into "just skip verification for now".
 * 2. **`hash_equals`, never `===`.** String comparison returns early on the first differing byte, and
 *    the timing of that is enough to recover a signature one character at a time. This is the whole
 *    reason the function exists in PHP.
 * 3. **No secret means REFUSED, never accepted.** The tempting default — "if we have no secret
 *    configured, let it through" — turns an unfinished setup into an open endpoint that writes
 *    whatever anybody posts into a customer's funnel. An endpoint that cannot verify refuses.
 *
 * ## Per-provider, because the schemes genuinely differ
 *
 * Meta signs with the APP SECRET and prefixes the digest with `sha256=`. Salla signs with a separate
 * webhook secret and sends a bare hex digest. Zid's scheme is declared but not confirmed by any
 * install here, so its deliveries are verified the same way and the poll stays authoritative — see
 * `WebhookSupport::RequiresConfirmation`.
 */
final class WebhookSignature
{
    public function __construct(private readonly ProviderConfigurationService $settings) {}

    /**
     * @return array{verified: bool, reason: ?string}
     */
    public function check(string $provider, string $rawBody, ?string $presented): array
    {
        $definition = ProviderCatalogue::get($provider);

        if ($definition->webhooks === WebhookSupport::PollingOnly) {
            return ['verified' => false, 'reason' => 'This provider does not deliver webhooks here.'];
        }

        $secret = $this->secretFor($provider);

        if ($secret === null) {
            return ['verified' => false, 'reason' => 'No webhook secret is configured for this provider.'];
        }

        if ($presented === null || $presented === '') {
            return ['verified' => false, 'reason' => 'The delivery carried no signature.'];
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        // Meta prefixes the digest; the others send it bare. Stripping a prefix that is not there is
        // harmless, and NOT stripping one that is there fails every legitimate Meta delivery.
        $offered = str_starts_with($presented, 'sha256=') ? substr($presented, 7) : $presented;

        return hash_equals($expected, $offered)
            ? ['verified' => true, 'reason' => null]
            : ['verified' => false, 'reason' => 'The signature did not match.'];
    }

    /**
     * The secret this provider signs with.
     *
     * Meta signs webhooks with the APP SECRET rather than a separate value, so `webhook_secret` is
     * optional there and the app secret is the fallback. Getting this wrong fails every delivery with
     * a signature mismatch, which reads as an attack rather than as a configuration detail.
     */
    private function secretFor(string $provider): ?string
    {
        $values = $this->settings->values($provider);

        return $values['webhook_secret']
            ?? ($provider === 'meta' ? $values['client_secret'] ?? null : null);
    }
}
