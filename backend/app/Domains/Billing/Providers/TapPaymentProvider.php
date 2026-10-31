<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PAY-TAP-001 — Tap Payments, a Saudi and GCC gateway, behind the same port Moyasar and Stripe use.
 *
 * ## What is verified, and what is not
 *
 * Written against Tap's published API (charges at `https://api.tap.company/v2/charges`, bearer secret
 * key; webhook signed with HMAC-SHA256 over a fixed string in the `hashstring` header). It has NOT
 * been run against a live or sandbox Tap account: this install holds no Tap credentials, so the
 * state is `awaiting_credentials` until `TAP_SECRET_KEY` is set, and the first sandbox charge is the
 * acceptance test. Nothing here is presented as proven against Tap itself.
 *
 * ## One secret, and it is the signing key
 *
 * Tap signs webhooks with the SECRET API KEY, so there is no separate webhook token to forget. A
 * configured adapter can therefore always verify what it is sent; an unconfigured one verifies
 * nothing.
 *
 * ## The body is re-read before it settles
 *
 * The signature covers id, amount, currency, references, status and creation time, but not our own
 * `reference`. `confirmsPayloadIntegrity()` answers false on purpose, so `ApplySubscriptionPaymentEvent`
 * re-asks Tap backend-to-backend (`fetchPayment`) before a payment is settled — the same belt and
 * braces the shared-secret gateway needs, applied here because it costs one request and removes a
 * class of mistake (a webhook for a different charge, a replayed body).
 *
 * ## Saved cards are not wired
 *
 * `supportsUnattendedCharge()` is false and `savedPaymentMethodFrom()` null: Tap's card-on-file flow
 * needs its own token objects and a merchant account with it enabled, none of which can be tested
 * here. The honest state is the attended renewal the product already falls back to.
 */
final class TapPaymentProvider implements PaymentProvider
{
    private const API = 'https://api.tap.company/v2';

    /** ISO currencies Tap quotes with three decimals; everything else it quotes with two. */
    private const THREE_DECIMALS = ['BHD', 'KWD', 'OMR', 'JOD'];

    public function name(): string
    {
        return 'tap';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey() !== null;
    }

    /**
     * Tap needs a customer with a first name and an email to open a charge. A payload without one is
     * a refused attempt that says so — never a charge opened with an invented address.
     */
    public function createSession(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'awaiting_credentials', 'session_id' => null, 'checkout_url' => null, 'error' => null];
        }

        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $email = trim((string) ($customer['email'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));

        if ($email === '' || $name === '') {
            return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => 'Tap needs the customer\'s name and email to open a charge.'];
        }

        $currency = strtoupper((string) ($payload['currency'] ?? 'SAR'));
        $reference = (string) ($payload['reference'] ?? '');

        try {
            $response = Http::withToken((string) $this->secretKey())
                ->timeout(15)
                ->acceptJson()
                ->asJson()
                ->post(self::API.'/charges', [
                    'amount' => (float) $this->decimal((float) $payload['amount'], $currency),
                    'currency' => $currency,
                    'description' => (string) ($payload['description'] ?? 'CampaignsHub subscription'),
                    'customer' => ['first_name' => $name, 'email' => $email],
                    'source' => ['id' => 'src_all'],
                    'redirect' => ['url' => (string) ($payload['return_url'] ?? '')],
                    // Our own reference travels with the charge and comes back, which is how a verified
                    // event finds the payment it belongs to.
                    'reference' => ['transaction' => $reference, 'order' => $reference],
                    'metadata' => ['reference' => $reference],
                ]);

            if (! $response->successful()) {
                return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => 'Tap refused the charge: '.$response->status()];
            }

            $body = (array) $response->json();
            $url = (string) ($body['transaction']['url'] ?? '');

            if (($body['id'] ?? '') === '' || $url === '') {
                return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => 'Tap answered without a checkout address.'];
            }

            return ['status' => 'created', 'session_id' => (string) $body['id'], 'checkout_url' => $url, 'error' => null];
        } catch (Throwable $e) {
            // A gateway that could not be reached is a FAILED attempt, never a silent success.
            return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * The `hashstring` header is HMAC-SHA256, keyed with the secret key, over
     * `x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{…}x_payment_reference{…}x_status{…}x_created{…}`
     * with the amount rounded to the currency's own decimals. Compared in constant time.
     */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        if (! $this->isConfigured()) {
            return ['verified' => false];
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            return ['verified' => false];
        }

        $received = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'hashstring') {
                $received = trim((string) $value);
            }
        }

        $expected = hash_hmac('sha256', $this->signedString($body), (string) $this->secretKey());

        if ($received === '' || ! hash_equals($expected, strtolower($received))) {
            return ['verified' => false];
        }

        $currency = strtoupper((string) ($body['currency'] ?? ''));
        $status = $this->mapStatus((string) ($body['status'] ?? ''));

        return [
            'verified' => true,
            // A charge fires several events as it moves (initiated, captured…): the id alone would
            // make the second a duplicate of the first, so the status is part of the dedupe key.
            'event_id' => (string) ($body['id'] ?? '').':'.strtoupper((string) ($body['status'] ?? '')),
            'type' => 'charge.'.strtolower((string) ($body['status'] ?? '')),
            'payment_id' => (string) ($body['id'] ?? ''),
            'status' => $status,
            'amount' => isset($body['amount']) ? $this->decimal((float) $body['amount'], $currency) : null,
            'currency' => $currency === '' ? null : $currency,
            'reference' => (string) (($body['metadata']['reference'] ?? $body['reference']['transaction'] ?? '') ?: ''),
            'payload' => $body,
        ];
    }

    public function confirmsPayloadIntegrity(): bool
    {
        return false;
    }

    public function fetchPayment(string $providerPaymentId): ?array
    {
        if (! $this->isConfigured() || $providerPaymentId === '') {
            return null;
        }

        try {
            $response = Http::withToken((string) $this->secretKey())
                ->timeout(10)
                ->acceptJson()
                ->get(self::API.'/charges/'.rawurlencode($providerPaymentId));

            if (! $response->successful()) {
                return null;
            }

            $body = (array) $response->json();
            if (($body['id'] ?? null) === null) {
                return null;
            }

            $currency = strtoupper((string) ($body['currency'] ?? ''));

            return [
                'status' => $this->mapStatus((string) ($body['status'] ?? '')),
                'amount' => isset($body['amount']) ? $this->decimal((float) $body['amount'], $currency) : null,
                'currency' => $currency === '' ? null : $currency,
                'reference' => (string) (($body['metadata']['reference'] ?? $body['reference']['transaction'] ?? '') ?: '') ?: null,
            ];
        } catch (Throwable) {
            // A gateway we could not reach has told us nothing. Silence is not confirmation.
            return null;
        }
    }

    public function supportsUnattendedCharge(): bool
    {
        return false;
    }

    public function chargeStoredMethod(string $token, array $payload): array
    {
        return ['status' => 'unsupported', 'provider_payment_id' => null, 'error' => 'Saved-card renewal is not wired for Tap.'];
    }

    public function savedPaymentMethodFrom(array $payload): ?array
    {
        return null;
    }

    public function paymentMethodFingerprint(array $payload): ?string
    {
        return null;
    }

    /** @param array<string,mixed> $body */
    private function signedString(array $body): string
    {
        $currency = strtoupper((string) ($body['currency'] ?? ''));
        $amount = isset($body['amount']) ? $this->decimal((float) $body['amount'], $currency) : '';

        return 'x_id'.($body['id'] ?? '')
            .'x_amount'.$amount
            .'x_currency'.$currency
            .'x_gateway_reference'.($body['reference']['gateway'] ?? '')
            .'x_payment_reference'.($body['reference']['payment'] ?? '')
            .'x_status'.($body['status'] ?? '')
            .'x_created'.($body['transaction']['created'] ?? '');
    }

    /** The amount in major units, to the number of decimals the currency carries. */
    private function decimal(float $amount, string $currency): string
    {
        return number_format($amount, in_array($currency, self::THREE_DECIMALS, true) ? 3 : 2, '.', '');
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'CAPTURED' => 'paid',
            'FAILED', 'DECLINED', 'RESTRICTED', 'ABANDONED', 'CANCELLED', 'TIMEDOUT', 'VOID' => 'failed',
            'REFUNDED' => 'refunded',
            'IN_PROGRESS', 'AUTHORIZED' => 'processing',
            default => 'pending',
        };
    }

    private function secretKey(): ?string
    {
        $key = app(PaymentGatewaySettings::class)->value('tap', 'secret_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
