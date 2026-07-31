<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Moyasar — the official, primary gateway (PAY-001).
 *
 * **Awaiting Credentials.** No keys exist for this install, so `isConfigured()` is false, no session is
 * ever opened, and no webhook can verify. Everything below is the real integration rather than a
 * placeholder: it builds Moyasar's actual invoice payload and verifies its actual webhook scheme. What
 * it does NOT do is claim to have moved money.
 *
 * Two things about Moyasar shape this adapter:
 *
 * 1. Its webhooks authenticate with a SHARED SECRET carried in the body (`secret_token`), not an HMAC
 *    over the payload. That is weaker than a signature — it cannot prove the body is unmodified — so
 *    the comparison is constant-time and the amount is re-checked against our own record before
 *    anything is settled. A shared secret is what the provider offers; trusting the body because the
 *    secret matched is what we must not do.
 * 2. It publishes no card fingerprint. The best available identity for a payment method is the brand
 *    plus the last four digits, which is NOT unique — see `paymentMethodFingerprint()`.
 */
final class MoyasarPaymentProvider implements PaymentProvider
{
    private const API = 'https://api.moyasar.com/v1';

    public function name(): string
    {
        return 'moyasar';
    }

    public function isConfigured(): bool
    {
        // BOTH, deliberately. A secret key with no webhook token could open a checkout that nothing is
        // able to confirm — money would leave the customer and no account would ever activate.
        return $this->secretKey() !== null && $this->webhookToken() !== null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status: string, session_id?: string|null, checkout_url?: string|null, error?: string|null}
     */
    public function createSession(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'awaiting_credentials', 'session_id' => null, 'checkout_url' => null, 'error' => null];
        }

        try {
            /*
             * Moyasar takes the amount in the currency's SMALLEST unit. Sending 9.00 where 900 is
             * meant charges a hundredth of the intended fee; sending 900 where 9.00 is meant charges
             * a hundred times it. The conversion happens here, once, rather than at each call site.
             */
            $response = Http::withBasicAuth((string) $this->secretKey(), '')
                ->asForm()
                ->post(self::API.'/invoices', [
                    'amount' => (int) round(((float) $payload['amount']) * 100),
                    'currency' => (string) ($payload['currency'] ?? 'SAR'),
                    'description' => (string) ($payload['description'] ?? 'CampaignsHub subscription'),
                    'callback_url' => (string) ($payload['return_url'] ?? ''),
                    // Our own reference travels with the invoice and comes back on the webhook, which
                    // is how a verified event finds the payment it belongs to.
                    'metadata' => ['reference' => (string) ($payload['reference'] ?? '')],
                ]);

            if (! $response->successful()) {
                return [
                    'status' => 'failed', 'session_id' => null, 'checkout_url' => null,
                    'error' => 'Moyasar refused the invoice: '.$response->status(),
                ];
            }

            $body = $response->json();

            return [
                'status' => 'created',
                'session_id' => (string) ($body['id'] ?? ''),
                'checkout_url' => (string) ($body['url'] ?? ''),
                'error' => null,
            ];
        } catch (Throwable $e) {
            // A gateway that could not be reached is a FAILED attempt, never a silent success.
            return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string,string>  $headers
     * @return array{verified: bool, event_id?: string|null, type?: string|null, payment_id?: string|null, status?: string|null, amount?: string|null, currency?: string|null, reference?: string|null, payload?: array<string,mixed>}
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

        $token = (string) ($body['secret_token'] ?? '');

        // Constant-time, because a timing-variable comparison of a shared secret is a way to learn it.
        if ($token === '' || ! hash_equals((string) $this->webhookToken(), $token)) {
            return ['verified' => false];
        }

        /** @var array<string,mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return [
            'verified' => true,
            // Moyasar's event id is what makes re-delivery a no-op. Falling back to the payment id
            // keeps dedupe working on the older payload shape rather than processing twice.
            'event_id' => (string) ($body['id'] ?? $data['id'] ?? ''),
            'type' => (string) ($body['type'] ?? ''),
            'payment_id' => (string) ($data['id'] ?? ''),
            'status' => $this->mapStatus((string) ($data['status'] ?? '')),
            // Back into major units — see the note in createSession.
            'amount' => isset($data['amount']) ? number_format(((int) $data['amount']) / 100, 2, '.', '') : null,
            'currency' => (string) ($data['currency'] ?? 'SAR'),
            'reference' => (string) (($data['metadata']['reference'] ?? '') ?: ''),
            'payload' => $body,
        ];
    }

    /**
     * Moyasar publishes no card fingerprint (PAY-004).
     *
     * The most identifying thing on the payload is the brand and the last four digits, and that is NOT
     * unique — thousands of cards share it. Returning it as a fingerprint would block innocent
     * customers whose card happens to end in the same four digits as an earlier trial.
     *
     * So this returns null, and `TrialEligibility` falls back to the identities it can trust. That is
     * the honest answer for this provider, and the reason the contract says "according to the
     * provider's capabilities".
     *
     * @param  array<string,mixed>  $payload
     */
    public function paymentMethodFingerprint(array $payload): ?string
    {
        return null;
    }

    /** Moyasar's vocabulary, mapped onto ours. Anything unrecognised stays `pending`, never `paid`. */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'paid',
            'failed' => 'failed',
            'refunded' => 'refunded',
            'authorized', 'initiated' => 'processing',
            default => 'pending',
        };
    }

    private function secretKey(): ?string
    {
        $key = config('services.moyasar.secret_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function webhookToken(): ?string
    {
        $token = config('services.moyasar.webhook_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
