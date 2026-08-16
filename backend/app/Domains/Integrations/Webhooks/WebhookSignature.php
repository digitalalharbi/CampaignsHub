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
 * webhook secret and sends a bare hex digest.
 *
 * **Zid does not sign at all** (ZID-WEBHOOK-001). It sends HTTP Basic credentials — the username and
 * password given when the subscription was created — and publishes no signature scheme of any kind.
 * This class used to compute an HMAC for it and compare against an `x-zid-signature` header Zid never
 * sends, so every genuine Zid delivery was refused. The poll stays authoritative regardless
 * (`WebhookSupport::RequiresConfirmation`), and that is now a decision grounded in what Zid publishes
 * rather than in not knowing.
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

        /*
         * ZID-WEBHOOK-001 — Zid does not sign, so there is nothing here to compare a digest against.
         *
         * Handled before the secret is looked up, because the credential it needs is a PAIR and not a
         * signing key: asking for one and computing an HMAC with it is what made every genuine Zid
         * delivery fail.
         */
        if ($provider === 'zid') {
            return $this->basic($provider, $presented);
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
     * ZID-WEBHOOK-001 — HTTP Basic, which is the only authentication Zid publishes for webhooks.
     *
     * From Zid's Create Webhook reference: «If username and password are provided when creating a
     * webhook, Zid will include a Basic Authentication header when sending webhook requests … This
     * allows partners to verify that the webhook request is coming from Zid.» There is no signature,
     * no digest, and no header to compute one into — which is why the previous `x-zid-signature` HMAC
     * refused every real delivery.
     *
     * The whole header is compared in one `hash_equals` rather than the username and password
     * separately. Two comparisons leak which half was wrong through their timing, and a username is
     * usually the easier half to guess; one comparison over the expected credential answers only the
     * question we are willing to answer.
     *
     * The three rules at the top of this class are unchanged by the different scheme. A missing
     * credential still REFUSES — an endpoint that cannot verify is not an endpoint that lets things
     * through — and the comparison is still timing-safe.
     *
     * @return array{verified: bool, reason: ?string}
     */
    private function basic(string $provider, ?string $presented): array
    {
        $values = $this->settings->values($provider);
        $username = $values['webhook_username'] ?? null;
        $password = $values['webhook_password'] ?? null;

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return [
                'verified' => false,
                'reason' => 'No webhook username and password are configured for this provider.',
            ];
        }

        if ($presented === null || $presented === '') {
            return ['verified' => false, 'reason' => 'The delivery carried no authorisation header.'];
        }

        $expected = 'Basic '.base64_encode($username.':'.$password);

        return hash_equals($expected, $presented)
            ? ['verified' => true, 'reason' => null]
            : ['verified' => false, 'reason' => 'The credentials did not match.'];
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
