<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;

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
     * @return array{verified: bool, reason: ?string, app?: string} `app` (Meta only): which registered app's secret validated
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

        // META-MULTI-APP-001 — Meta has one signing secret PER registered app; every other provider has one.
        $secrets = $provider === 'meta' ? $this->metaSecrets() : array_filter(['default' => $this->secretFor($provider)], static fn ($v) => $v !== null);

        if ($secrets === []) {
            return ['verified' => false, 'reason' => 'No webhook secret is configured for this provider.'];
        }

        if ($presented === null || $presented === '') {
            return ['verified' => false, 'reason' => 'The delivery carried no signature.'];
        }

        // Meta prefixes the digest; the others send it bare. Stripping a prefix that is not there is
        // harmless, and NOT stripping one that is there fails every legitimate Meta delivery.
        $offered = str_starts_with($presented, 'sha256=') ? substr($presented, 7) : $presented;

        $matched = [];
        foreach ($secrets as $app => $secret) {
            if (hash_equals(hash_hmac('sha256', $rawBody, $secret), $offered)) {
                $matched[] = $app;
            }
        }

        // Exactly one app must validate: two apps sharing a secret cannot say whose delivery this is, so it is refused, not guessed.
        if (count($matched) !== 1) {
            return ['verified' => false, 'reason' => $matched === [] ? 'The signature did not match.' : 'The signature matched more than one app.'];
        }

        return $provider === 'meta'
            ? ['verified' => true, 'reason' => null, 'app' => $matched[0]]
            : ['verified' => true, 'reason' => null];
    }

    /**
     * META-MULTI-APP-001 — the signing secret of every CONFIGURED registered Meta app, keyed by profile (live, saas, candidate).
     *
     * Live keeps its established rule (`webhook_secret`, else the App Secret); the others sign with their App Secret.
     *
     * @return array<string, string>
     */
    private function metaSecrets(): array
    {
        $secrets = array_filter([
            'live' => $this->secretFor('meta'),
            'saas' => app(MetaSaasCredentials::class)->value('client_secret'),
            'candidate' => app(MetaCandidateCredentials::class)->value('client_secret'),
        ], static fn ($v) => is_string($v) && $v !== '');

        return $secrets;
    }

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
