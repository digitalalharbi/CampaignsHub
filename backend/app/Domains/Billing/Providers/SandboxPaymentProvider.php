<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use Illuminate\Support\Str;

/**
 * A gateway that is honestly not a gateway (PAY-SANDBOX-001).
 *
 * The problem it solves is narrow and real. Since PLAN-PAID-001 no workspace exists until a payment
 * is confirmed, and a payment is confirmed only by a webhook an adapter cryptographically verified.
 * On a machine with no Moyasar or Stripe credentials that makes the entire registration journey
 * unwalkable — not by a customer, and not by the acceptance suite either. The wrong answers are
 * obvious: a "mark as paid" button, a policy that skips the gate off-production, or a test that
 * writes the paid row directly. Each of those proves that the product can be activated by something
 * other than money, which is the one thing this path exists to prevent.
 *
 * So instead: a real adapter, plugged into the real registry, whose webhook goes through the real
 * `ApplySubscriptionPaymentEvent` and is really verified — against a secret this installation
 * generated rather than one a bank issued. Every downstream guarantee is exercised: the signature
 * check, the idempotency key, the amount re-check, the single call site of `paymentConfirmed()`.
 *
 * What it must never do is look like a live gateway:
 *
 *  - `name()` is `sandbox`, and the interface surfaces it, so «Sandbox» is what the console and the
 *    applicant's status page display — never «Live», never «Awaiting Credentials», which are two
 *    different and equally untrue statements about this state.
 *  - `isConfigured()` is FALSE in production, whatever the environment variables say. A deployment
 *    that reaches for this by accident gets Awaiting Credentials, which strands a signup — the safe
 *    failure — rather than activating an account nobody paid for.
 */
final class SandboxPaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'sandbox';
    }

    /**
     * Never in production. Not "not by default" — never.
     *
     * The check is on the environment rather than on a config flag, because a config flag is
     * something a deploy can set by mistake and an environment name is what the deploy IS.
     */
    public function isConfigured(): bool
    {
        return ! app()->environment('production') && $this->secret() !== '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status: string, session_id?: string|null, checkout_url?: string|null, error?: string|null}
     */
    public function createSession(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'awaiting_credentials', 'session_id' => null, 'checkout_url' => null];
        }

        /*
         * The checkout URL points back at THIS application's sandbox endpoint, carrying the reference
         * and nothing else. It is a page the customer visits and returns from, exactly as a gateway's
         * page is — and, exactly as with a gateway's page, returning from it settles nothing. The
         * endpoint posts a signed event to the webhook, and it is the webhook that decides.
         */
        $reference = (string) ($payload['reference'] ?? '');

        return [
            'status' => 'created',
            'session_id' => 'sbx_'.Str::random(24),
            /*
             * The reference travels as a QUERY parameter, not as a path segment.
             *
             * An idempotency key contains colons (`subscription:<id>:<plan>:<term>`), and a
             * percent-encoded colon inside a path segment is not decoded back into the route
             * parameter — the charge was then looked up by a key that did not exist and the page
             * answered 404 for a payment that was perfectly real.
             */
            'checkout_url' => rtrim((string) config('app.url'), '/')
                .'/api/v1/payments/sandbox?ref='.rawurlencode($reference),
            'error' => null,
        ];
    }

    /**
     * @param  array<string,string>  $headers
     * @return array{verified: bool, event_id?: string|null, type?: string|null, payment_id?: string|null, status?: string|null}
     */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        if (! $this->isConfigured()) {
            return ['verified' => false];
        }

        /** @var array<string,mixed>|null $body */
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            return ['verified' => false];
        }

        /*
         * A real signature over the real body, checked in constant time.
         *
         * The point is not that this secret is precious — it is generated locally and protects
         * nothing of value. It is that the sandbox exercises the same code path a live gateway does,
         * so a bug in the verification, the idempotency key or the amount check shows up here rather
         * than in production.
         */
        $signature = (string) ($headers['x-sandbox-signature'] ?? $headers['X-Sandbox-Signature'] ?? '');
        $expected = hash_hmac('sha256', $rawBody, $this->secret());

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return ['verified' => false];
        }

        $data = (array) ($body['data'] ?? []);

        return [
            'verified' => true,
            'event_id' => (string) ($body['id'] ?? ''),
            'type' => (string) ($body['type'] ?? ''),
            'payment_id' => (string) ($data['id'] ?? ''),
            'status' => (string) ($data['status'] ?? 'pending'),
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'payload' => $body,
            'reference' => (string) (($data['metadata'] ?? [])['reference'] ?? ''),
        ];
    }

    /**
     * Yes — `X-Sandbox-Signature` is an HMAC over the raw body, the same shape Stripe uses.
     *
     * That is the point of the sandbox: it exercises the live code path rather than a shortcut, so
     * the branch that re-asks a gateway is one this adapter genuinely does not need instead of one
     * it quietly skips.
     */
    public function confirmsPayloadIntegrity(): bool
    {
        return true;
    }

    /**
     * There is no gateway to ask. The signature is the whole attestation here.
     *
     * @return array{status: string, amount: ?string, currency: ?string, reference: ?string}|null
     */
    public function fetchPayment(string $providerPaymentId): ?array
    {
        return null;
    }

    /**
     * No. The sandbox exists to make the ATTENDED path walkable without credentials.
     *
     * An unattended charge here would have to invent a settlement, and inventing one is precisely
     * what this adapter refuses to do everywhere else: it signs a real webhook and lets the real
     * pipeline decide. A renewal on a sandbox install therefore opens a checkout somebody walks
     * through, which is the honest simulation of what happens with no stored card.
     */
    public function supportsUnattendedCharge(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status: string, provider_payment_id?: string|null, error?: string|null}
     */
    public function chargeStoredMethod(string $token, array $payload): array
    {
        return ['status' => 'unsupported', 'provider_payment_id' => null, 'error' => null];
    }

    /**
     * No card on file, for the same reason there is no unattended charge (PAY-TOKEN-003).
     *
     * The sandbox could mint a plausible token and store it. It would then sit on a customer's
     * billing page as «Visa ···· 4242, renewals are taken automatically» while `chargeStoredMethod()`
     * two lines above answers `unsupported` — a claim the same class refuses in the next method. A
     * sandbox install renews through the checkout somebody walks through, and says so.
     *
     * @param  array<string,mixed>  $payload
     * @return array{token: string, customer_id?: ?string, brand?: ?string, last4?: ?string, exp_month?: ?int, exp_year?: ?int}|null
     */
    public function savedPaymentMethodFrom(array $payload): ?array
    {
        return null;
    }

    /**
     * No fingerprint, deliberately.
     *
     * There is no payment method here to identify, and returning a made-up one would either block
     * innocent customers from a trial or let the same imaginary card open them forever — the exact
     * failure the interface documents null for.
     */
    public function paymentMethodFingerprint(array $payload): ?string
    {
        return null;
    }

    /** The signing secret for this installation's sandbox. Empty means the sandbox is off. */
    public function secret(): string
    {
        return (string) config('subscriptions.sandbox_secret', '');
    }
}
