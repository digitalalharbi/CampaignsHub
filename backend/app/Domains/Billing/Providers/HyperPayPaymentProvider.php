<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * HyperPay (OPP / COPYandPAY) — PAY-GATEWAY-CONSOLE-001.
 *
 * `POST /v1/checkouts` (Bearer access token, `entityId`, amount in major units, `paymentType=DB`) answers with a checkout id and no
 * redirect address: the card form is HyperPay's own widget, loaded from HyperPay's host by a page CampaignsHub serves
 * (`HostedCheckoutController::hyperpay`, reached only by a signed URL). Card data never touches CampaignsHub.
 *
 * Webhooks are AES-256-GCM encrypted with the webhook's hex secret (IV and tag in `X-Initialization-Vector` and
 * `X-Authentication-Tag`): a body that decrypts is HyperPay's. Settlement still re-reads the payment from `GET /v1/query/{id}`
 * (PAY-CONFIRM-001). Status is HyperPay's `result.code`, matched against its published patterns.
 *
 * Test and live are different hosts, chosen by the gateway's mode in the console. Card-on-file charges need `createRegistration` and
 * HyperPay's account setup and are not wired: renewals arrive as invoices.
 *
 * Sources: hyperpay.docs.oppwa.com — parameters, integrations/widget, tutorials/webhooks, reference/resultCodes.
 */
final class HyperPayPaymentProvider implements PaymentProvider
{
    private const HOSTS = ['test' => 'https://eu-test.oppwa.com', 'live' => 'https://eu-prod.oppwa.com'];

    private const PAID = '/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0|000\.500\.)/';

    private const PENDING = '/^(000\.200|800\.400\.5|100\.400\.500|000\.400\.0[^3]|000\.400\.100)/';

    public function name(): string
    {
        return 'hyperpay';
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null && $this->entityId() !== null && $this->webhookKey() !== null;
    }

    public function host(): string
    {
        return self::HOSTS[app(PaymentGatewaySettings::class)->mode('hyperpay')] ?? self::HOSTS['test'];
    }

    public function createSession(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'awaiting_credentials', 'session_id' => null, 'checkout_url' => null, 'error' => null];
        }

        $returnUrl = (string) ($payload['return_url'] ?? '');

        try {
            $response = Http::withToken((string) $this->token())
                ->timeout(15)
                ->acceptJson()
                ->asForm()
                ->post($this->host().'/v1/checkouts', [
                    'entityId' => $this->entityId(),
                    'amount' => number_format((float) $payload['amount'], 2, '.', ''),
                    'currency' => strtoupper((string) ($payload['currency'] ?? 'SAR')),
                    'paymentType' => 'DB',
                    // Our reference: it comes back on the webhook and on every query.
                    'merchantTransactionId' => mb_substr((string) ($payload['reference'] ?? ''), 0, 255),
                    'shopperResultUrl' => $returnUrl,
                    'integrity' => 'true',
                ]);

            $body = (array) $response->json();
            $id = (string) ($body['id'] ?? '');
            $code = (string) ($body['result']['code'] ?? '');

            if (! $response->successful() || $id === '' || ! preg_match('/^000\.200\.100/', $code)) {
                return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => 'HyperPay refused the checkout: '.$response->status().' '.$code.' '.((string) ($body['result']['description'] ?? ''))];
            }

            // Signed RELATIVE and prefixed with the public API origin: behind the proxy the request's own scheme is http, and an
            // absolute signature would describe an address the browser never uses.
            $page = rtrim((string) config('ad_platforms.redirect_base', config('app.url')), '/').URL::temporarySignedRoute('api.v1.payments.hyperpay.checkout', now()->addMinutes(30), [
                'checkout' => $id,
                'integrity' => (string) ($body['integrity'] ?? ''),
                'return' => $returnUrl,
            ], false);

            return ['status' => 'created', 'session_id' => $id, 'checkout_url' => $page, 'error' => null];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'session_id' => null, 'checkout_url' => null, 'error' => $e->getMessage()];
        }
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        if (! $this->isConfigured()) {
            return ['verified' => false];
        }

        $iv = $tag = '';
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'x-initialization-vector') {
                $iv = trim((string) $value);
            } elseif ($lower === 'x-authentication-tag') {
                $tag = trim((string) $value);
            }
        }

        $wrapped = json_decode($rawBody, true);
        $cipher = is_array($wrapped) && is_string($wrapped['encryptedBody'] ?? null) ? $wrapped['encryptedBody'] : trim($rawBody);

        $plain = $this->decrypt($cipher, $iv, $tag);
        $event = $plain === null ? null : json_decode($plain, true);

        if (! is_array($event) || strtoupper((string) ($event['type'] ?? '')) !== 'PAYMENT' || ! is_array($event['payload'] ?? null)) {
            return ['verified' => false];
        }

        $payment = $event['payload'];
        $code = (string) ($payment['result']['code'] ?? '');

        return [
            'verified' => true,
            'event_id' => 'hyperpay:'.($payment['id'] ?? '').':'.$code,
            'type' => 'payment.'.strtolower((string) ($payment['paymentType'] ?? 'db')),
            'payment_id' => (string) ($payment['id'] ?? ''),
            'status' => $this->mapStatus($code, (string) ($payment['paymentType'] ?? 'DB')),
            'amount' => isset($payment['amount']) ? number_format((float) $payment['amount'], 2, '.', '') : null,
            'currency' => isset($payment['currency']) ? strtoupper((string) $payment['currency']) : null,
            'reference' => (string) ($payment['merchantTransactionId'] ?? ''),
            'payload' => $payment,
        ];
    }

    public function confirmsPayloadIntegrity(): bool
    {
        return false;
    }

    public function fetchPayment(string $providerPaymentId): ?array
    {
        if (! $this->isConfigured() || $providerPaymentId === '' || ! preg_match('/^[A-Za-z0-9.\-]{1,64}$/', $providerPaymentId)) {
            return null;
        }

        try {
            $response = Http::withToken((string) $this->token())
                ->timeout(10)
                ->acceptJson()
                ->get($this->host().'/v1/query/'.$providerPaymentId, ['entityId' => $this->entityId()]);

            if (! $response->successful()) {
                return null;
            }

            $body = (array) $response->json();
            $code = (string) ($body['result']['code'] ?? '');
            if ($code === '' || ($body['id'] ?? '') === '') {
                return null;
            }

            return [
                'status' => $this->mapStatus($code, (string) ($body['paymentType'] ?? 'DB')),
                'amount' => isset($body['amount']) ? number_format((float) $body['amount'], 2, '.', '') : null,
                'currency' => isset($body['currency']) ? strtoupper((string) $body['currency']) : null,
                'reference' => isset($body['merchantTransactionId']) ? (string) $body['merchantTransactionId'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function supportsUnattendedCharge(): bool
    {
        return false;
    }

    public function chargeStoredMethod(string $token, array $payload): array
    {
        return ['status' => 'unsupported', 'provider_payment_id' => null, 'error' => 'Card-on-file charges need HyperPay registrations enabled on the account, and are not wired.'];
    }

    public function savedPaymentMethodFrom(array $payload): ?array
    {
        return null;
    }

    public function paymentMethodFingerprint(array $payload): ?string
    {
        return null;
    }

    /** A refund (`RF`) that succeeded is money returned; any other type follows HyperPay's success / pending / rejected patterns. */
    private function mapStatus(string $code, string $paymentType): string
    {
        $succeeded = preg_match(self::PAID, $code) === 1;

        if (strtoupper($paymentType) === 'RF') {
            return $succeeded ? 'refunded' : 'pending';
        }

        return match (true) {
            $succeeded => 'paid',
            preg_match(self::PENDING, $code) === 1 => 'pending',
            default => 'failed',
        };
    }

    private function decrypt(string $hexCipher, string $hexIv, string $hexTag): ?string
    {
        $key = @hex2bin((string) $this->webhookKey());
        $iv = @hex2bin($hexIv);
        $tag = @hex2bin($hexTag);
        $cipher = @hex2bin($hexCipher);

        if ($key === false || $iv === false || $tag === false || $cipher === false || strlen($key) !== 32 || $tag === '') {
            return null;
        }

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private function token(): ?string
    {
        return app(PaymentGatewaySettings::class)->value('hyperpay', 'access_token');
    }

    private function entityId(): ?string
    {
        $id = app(PaymentGatewaySettings::class)->value('hyperpay', 'entity_id');

        return $id !== null && preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? $id : null;
    }

    private function webhookKey(): ?string
    {
        return app(PaymentGatewaySettings::class)->value('hyperpay', 'webhook_secret');
    }
}
