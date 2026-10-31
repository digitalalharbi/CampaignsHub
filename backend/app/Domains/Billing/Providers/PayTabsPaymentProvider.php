<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PayTabs (PT2 API, Saudi region) — PAY-GATEWAY-CONSOLE-001.
 *
 * Hosted Payment Page: `POST /payment/request` with the profile's Server Key in the `authorization` header (no «Bearer»), answered
 * with `redirect_url` and `tran_ref`. Card data never touches CampaignsHub.
 *
 * The callback is signed — header `Signature` = HMAC-SHA256 of the raw body with the Server Key — and that only proves PayTabs sent
 * it. Money still settles only on PayTabs' own answer to `POST /payment/query`, read over our connection (PAY-CONFIRM-001), so a
 * leaked key or a replayed body cannot settle a figure PayTabs does not report.
 *
 * Test and live share one host; the profile decides (a test profile takes test cards only and is never converted). Tokenised
 * recurring charges need PayTabs to enable them on the account and are not wired: renewals arrive as invoices.
 *
 * Sources: docs.paytabs.com (HPP steps 2, 7), support.paytabs.com articles 60000992876, 60000718961, 60000815793.
 */
final class PayTabsPaymentProvider implements PaymentProvider, ReconcilesCheckoutSessions
{
    /** The Saudi regional endpoint: a profile used against another region's domain fails authentication. */
    private const API = 'https://secure.paytabs.sa';

    public function name(): string
    {
        return 'paytabs';
    }

    public function isConfigured(): bool
    {
        return $this->serverKey() !== null && $this->profileId() !== null;
    }

    public function createSession(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'awaiting_credentials', 'session_id' => null, 'checkout_url' => null, 'error' => null];
        }

        $reference = mb_substr((string) ($payload['reference'] ?? ''), 0, 64);

        try {
            $response = Http::withHeaders(['authorization' => (string) $this->serverKey()])
                ->timeout(15)
                ->acceptJson()
                ->asJson()
                ->post(self::API.'/payment/request', [
                    'profile_id' => (int) $this->profileId(),
                    'tran_type' => 'sale',
                    'tran_class' => 'ecom',
                    // Our own reference is the cart id; it comes back on the callback and on every query.
                    'cart_id' => $reference,
                    'cart_description' => mb_substr((string) ($payload['description'] ?? 'CampaignsHub subscription'), 0, 128),
                    'cart_currency' => strtoupper((string) ($payload['currency'] ?? 'SAR')),
                    'cart_amount' => (float) number_format((float) $payload['amount'], 2, '.', ''),
                    'return' => (string) ($payload['return_url'] ?? ''),
                    'callback' => PaymentGatewaySettings::webhookUrl('paytabs'),
                ]);

            if (! $response->successful()) {
                return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => 'PayTabs refused the payment request: '.$response->status().$this->message($response->json())];
            }

            $body = (array) $response->json();
            $url = (string) ($body['redirect_url'] ?? '');
            $tranRef = (string) ($body['tran_ref'] ?? '');

            if ($url === '' || $tranRef === '') {
                return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => 'PayTabs answered without a payment page.'];
            }

            return ['status' => 'created', 'session_id' => $tranRef, 'checkout_url' => $url, 'error' => null];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => $e->getMessage()];
        }
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        if (! $this->isConfigured()) {
            return ['verified' => false];
        }

        $received = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'signature') {
                $received = strtolower(trim((string) $value));
            }
        }

        $expected = hash_hmac('sha256', $rawBody, (string) $this->serverKey());

        if ($received === '' || ! hash_equals($expected, $received)) {
            return ['verified' => false];
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body) || ($body['tran_ref'] ?? '') === '') {
            return ['verified' => false];
        }

        $code = strtoupper((string) ($body['payment_result']['response_status'] ?? ''));

        return [
            'verified' => true,
            // One transaction moves through statuses, and a refund is its own transaction: reference and status together.
            'event_id' => 'paytabs:'.$body['tran_ref'].':'.$code,
            'type' => strtolower((string) ($body['tran_type'] ?? 'sale')).'.'.strtolower($code),
            'payment_id' => (string) $body['tran_ref'],
            'status' => $this->mapStatus($code, (string) ($body['tran_type'] ?? 'sale')),
            'amount' => isset($body['cart_amount']) ? number_format((float) $body['cart_amount'], 2, '.', '') : null,
            'currency' => isset($body['cart_currency']) ? strtoupper((string) $body['cart_currency']) : null,
            'reference' => (string) ($body['cart_id'] ?? ''),
            'payload' => $body,
        ];
    }

    /** A signed callback proves the sender, not the money: settlement re-reads the transaction from PayTabs. */
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
            // `tran_ref` OR `cart_id`, never both — PayTabs answers 400 to the pair.
            $response = Http::withHeaders(['authorization' => (string) $this->serverKey()])
                ->timeout(10)
                ->acceptJson()
                ->asJson()
                ->post(self::API.'/payment/query', ['profile_id' => (int) $this->profileId(), 'tran_ref' => $providerPaymentId]);

            if (! $response->successful()) {
                return null;
            }

            $body = (array) $response->json();
            if (($body['tran_ref'] ?? '') === '') {
                return null;
            }

            return [
                'status' => $this->mapStatus(strtoupper((string) ($body['payment_result']['response_status'] ?? '')), (string) ($body['tran_type'] ?? 'sale')),
                'amount' => isset($body['cart_amount']) ? number_format((float) $body['cart_amount'], 2, '.', '') : null,
                'currency' => isset($body['cart_currency']) ? strtoupper((string) $body['cart_currency']) : null,
                'reference' => isset($body['cart_id']) ? (string) $body['cart_id'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * PAY-RECONCILE-001 — the session id IS the transaction reference, so asking PayTabs again is one query. A callback that
     * arrived before PayTabs' own record said «A» is deduplicated on its retry; this is what settles it.
     */
    public function paidPaymentIdForSession(string $sessionId): ?string
    {
        $answer = $this->fetchPayment($sessionId);

        return ($answer['status'] ?? null) === 'paid' ? $sessionId : null;
    }

    public function supportsUnattendedCharge(): bool
    {
        return false;
    }

    public function chargeStoredMethod(string $token, array $payload): array
    {
        return ['status' => 'unsupported', 'provider_payment_id' => null, 'error' => 'Tokenised recurring charges need PayTabs to enable them on the account, and are not wired.'];
    }

    public function savedPaymentMethodFrom(array $payload): ?array
    {
        return null;
    }

    public function paymentMethodFingerprint(array $payload): ?string
    {
        return null;
    }

    /**
     * `A` authorised, `D` declined, `E` error, `V` voided, `C` cancelled, `P`/`H` pending or on hold. A refund is its own
     * transaction (`tran_type` refund) whose authorised status means the money went back.
     */
    private function mapStatus(string $code, string $tranType): string
    {
        if (strtolower($tranType) === 'refund') {
            return $code === 'A' ? 'refunded' : 'pending';
        }

        return match ($code) {
            'A' => 'paid',
            'D', 'E', 'V', 'C', 'X' => 'failed',
            default => 'pending',
        };
    }

    private function message(mixed $body): string
    {
        return is_array($body) && is_string($body['message'] ?? null) ? ' — '.$body['message'] : '';
    }

    private function serverKey(): ?string
    {
        return app(PaymentGatewaySettings::class)->value('paytabs', 'server_key');
    }

    private function profileId(): ?string
    {
        $id = app(PaymentGatewaySettings::class)->value('paytabs', 'profile_id');

        return $id !== null && ctype_digit($id) ? $id : null;
    }
}
