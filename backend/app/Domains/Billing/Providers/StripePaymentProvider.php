<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stripe — the alternative gateway (PAY-001).
 *
 * **Awaiting Credentials.** No keys exist for this install, so nothing here can open a session or
 * verify an event. The signature scheme below is Stripe's real one, implemented rather than stubbed,
 * because a webhook verifier that is only written when credentials arrive is a verifier nobody has
 * ever tested.
 *
 * Stripe's scheme is stronger than Moyasar's: the `Stripe-Signature` header carries a timestamp and an
 * HMAC-SHA256 over `timestamp.rawBody`, so a verified event proves the body is unmodified AND recent.
 * The tolerance window matters — without it a captured webhook could be replayed a year later.
 */
final class StripePaymentProvider implements PaymentProvider
{
    private const API = 'https://api.stripe.com/v1';

    /** Five minutes, Stripe's own recommendation. Older than this and a valid signature is a replay. */
    private const TOLERANCE_SECONDS = 300;

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        // BOTH: a secret key with no webhook secret could take money that nothing is able to confirm.
        return $this->secretKey() !== null && $this->webhookSecret() !== null;
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
            $response = Http::withToken((string) $this->secretKey())
                ->asForm()
                ->post(self::API.'/checkout/sessions', [
                    'mode' => 'payment',
                    'success_url' => (string) ($payload['return_url'] ?? ''),
                    'cancel_url' => (string) ($payload['cancel_url'] ?? $payload['return_url'] ?? ''),
                    // Smallest currency unit, as with every gateway — see MoyasarPaymentProvider.
                    'line_items[0][price_data][currency]' => mb_strtolower((string) ($payload['currency'] ?? 'sar')),
                    'line_items[0][price_data][unit_amount]' => (int) round(((float) $payload['amount']) * 100),
                    'line_items[0][price_data][product_data][name]' => (string) ($payload['description'] ?? 'CampaignsHub subscription'),
                    'line_items[0][quantity]' => 1,
                    // Our reference comes back on the event, which is how it finds its payment.
                    'client_reference_id' => (string) ($payload['reference'] ?? ''),
                    'metadata[reference]' => (string) ($payload['reference'] ?? ''),
                ]);

            if (! $response->successful()) {
                return [
                    'status' => 'failed', 'session_id' => null, 'checkout_url' => null,
                    'error' => 'Stripe refused the session: '.$response->status(),
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

        $signature = $this->header($headers, 'stripe-signature');

        if ($signature === null || ! $this->signatureIsValid($rawBody, $signature)) {
            return ['verified' => false];
        }

        /** @var array<string,mixed>|null $body */
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            return ['verified' => false];
        }

        /** @var array<string,mixed> $object */
        $object = is_array($body['data']['object'] ?? null) ? $body['data']['object'] : [];
        $type = (string) ($body['type'] ?? '');

        return [
            'verified' => true,
            'event_id' => (string) ($body['id'] ?? ''),
            'type' => $type,
            'payment_id' => (string) ($object['payment_intent'] ?? $object['id'] ?? ''),
            'status' => $this->mapStatus($type, $object),
            'amount' => isset($object['amount_total']) || isset($object['amount'])
                ? number_format(((int) ($object['amount_total'] ?? $object['amount'])) / 100, 2, '.', '')
                : null,
            'currency' => mb_strtoupper((string) ($object['currency'] ?? 'SAR')),
            'reference' => (string) ($object['client_reference_id'] ?? $object['metadata']['reference'] ?? ''),
            'payload' => $body,
        ];
    }

    /**
     * Stripe's card fingerprint is stable across customers, which is exactly what "one trial per
     * payment method" needs (PAY-004).
     *
     * It is hashed again before storage by `TrialEligibility`, so the ledger holds no provider
     * identifier either.
     *
     * @param  array<string,mixed>  $payload
     */
    public function paymentMethodFingerprint(array $payload): ?string
    {
        /** @var array<string,mixed> $object */
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        $fingerprint = $object['payment_method_details']['card']['fingerprint']
            ?? $object['charges']['data'][0]['payment_method_details']['card']['fingerprint']
            ?? null;

        return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;
    }

    /**
     * Stripe's `t=…,v1=…` scheme: HMAC-SHA256 over "timestamp.rawBody" with the webhook secret.
     *
     * The timestamp check is not optional. Without it a signature stays valid forever, and a webhook
     * captured once could be replayed to re-confirm a payment that has since been refunded.
     */
    private function signatureIsValid(string $rawBody, string $header): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $this->webhookSecret());

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stripe says what happened in the event TYPE, not only in the object's status.
     *
     * Anything unrecognised stays `pending`. A default of `paid` is how an unfamiliar event type
     * becomes an activation nobody paid for.
     *
     * @param  array<string,mixed>  $object
     */
    private function mapStatus(string $type, array $object): string
    {
        return match (true) {
            $type === 'checkout.session.completed' => ($object['payment_status'] ?? '') === 'paid' ? 'paid' : 'processing',
            $type === 'payment_intent.succeeded' => 'paid',
            $type === 'charge.succeeded' => 'paid',
            $type === 'payment_intent.payment_failed' => 'failed',
            $type === 'charge.refunded' => 'refunded',
            str_starts_with($type, 'charge.dispute') => 'disputed',
            default => 'pending',
        };
    }

    /** @param  array<string,string>  $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (mb_strtolower((string) $key) === $name && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function secretKey(): ?string
    {
        $key = config('services.stripe.secret_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function webhookSecret(): ?string
    {
        $secret = config('services.stripe.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
