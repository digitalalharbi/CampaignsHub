<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use InvalidArgumentException;

/**
 * PAY-GATEWAY-CONSOLE-001 — the payment gateways the platform may take its own subscription revenue through, and what each needs.
 *
 * One definition per gateway: the credential fields the merchant pastes from the gateway's dashboard (only those the adapter
 * actually reads), which are secret, the legacy environment key each falls back to, how a test key is told from a live one, and
 * what the adapter can do. Nothing here holds a value; values live encrypted in `provider_configurations` (`payment.{key}`) or,
 * for an install configured before the console existed, in the environment.
 */
final class PaymentGatewayCatalogue
{
    /** The order the console shows them in. Moyasar is the intended primary. */
    public const KEYS = ['moyasar', 'tap', 'streampay', 'hyperpay', 'paytabs', 'stripe'];

    /**
     * @return array{
     *     key: string, label: array{ar: string, en: string},
     *     fields: list<array{key: string, label: array{ar: string, en: string}, secret: bool, required: bool, env: ?string}>,
     *     test_prefix: ?string, live_prefix: ?string, prefixed_field: ?string,
     *     webhook: array{scheme: string, header: ?string, events: list<string>},
     *     capabilities: array{hosted_checkout: bool, status_lookup: bool, refund: bool, recurring: bool, webhook: bool},
     *     methods: list<string>, docs: string
     * }
     */
    public static function get(string $key): array
    {
        $all = self::all();

        if (! isset($all[$key])) {
            throw new InvalidArgumentException("Unknown payment gateway [{$key}].");
        }

        return $all[$key];
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $field = static fn (string $key, string $ar, string $en, bool $secret, bool $required = true, ?string $env = null): array => [
            'key' => $key, 'label' => ['ar' => $ar, 'en' => $en], 'secret' => $secret, 'required' => $required, 'env' => $env,
        ];

        return [
            'moyasar' => [
                'key' => 'moyasar', 'label' => ['ar' => 'ميسر', 'en' => 'Moyasar'],
                'fields' => [
                    $field('secret_key', 'المفتاح السري', 'Secret key', true, true, 'MOYASAR_SECRET_KEY'),
                    $field('publishable_key', 'المفتاح العام', 'Publishable key', false, false, 'MOYASAR_PUBLISHABLE_KEY'),
                    $field('webhook_token', 'رمز الويب هوك السري', 'Webhook secret token', true, true, 'MOYASAR_WEBHOOK_TOKEN'),
                ],
                'test_prefix' => 'sk_test_', 'live_prefix' => 'sk_live_', 'prefixed_field' => 'secret_key',
                'webhook' => ['scheme' => 'A shared secret carried in the body as `secret_token`; every paid event is re-read from Moyasar before it settles.', 'header' => null, 'events' => ['payment_paid', 'payment_failed', 'payment_refunded']],
                'capabilities' => ['hosted_checkout' => true, 'status_lookup' => true, 'refund' => false, 'recurring' => true, 'webhook' => true],
                'methods' => ['mada', 'visa', 'mastercard', 'applepay', 'stcpay'],
                'docs' => 'https://docs.moyasar.com',
            ],
            'tap' => [
                'key' => 'tap', 'label' => ['ar' => 'تاب', 'en' => 'Tap Payments'],
                'fields' => [
                    $field('secret_key', 'المفتاح السري', 'Secret key', true, true, 'TAP_SECRET_KEY'),
                    $field('publishable_key', 'المفتاح العام', 'Publishable key', false, false, 'TAP_PUBLISHABLE_KEY'),
                ],
                'test_prefix' => 'sk_test_', 'live_prefix' => 'sk_live_', 'prefixed_field' => 'secret_key',
                'webhook' => ['scheme' => 'An HMAC-SHA256 `hashstring` header keyed with the secret key.', 'header' => 'hashstring', 'events' => ['CAPTURED', 'FAILED', 'DECLINED', 'CANCELLED']],
                'capabilities' => ['hosted_checkout' => true, 'status_lookup' => true, 'refund' => false, 'recurring' => false, 'webhook' => true],
                'methods' => ['mada', 'visa', 'mastercard', 'applepay', 'stcpay'],
                'docs' => 'https://developers.tap.company',
            ],
            'streampay' => [
                'key' => 'streampay', 'label' => ['ar' => 'ستريم باي', 'en' => 'StreamPay'],
                'fields' => [],
                'test_prefix' => null, 'live_prefix' => null, 'prefixed_field' => null,
                'webhook' => ['scheme' => '', 'header' => null, 'events' => []],
                'capabilities' => ['hosted_checkout' => false, 'status_lookup' => false, 'refund' => false, 'recurring' => false, 'webhook' => false],
                'methods' => [],
                'docs' => 'https://streampay.sa',
            ],
            'hyperpay' => [
                'key' => 'hyperpay', 'label' => ['ar' => 'هايبر باي', 'en' => 'HyperPay'],
                'fields' => [],
                'test_prefix' => null, 'live_prefix' => null, 'prefixed_field' => null,
                'webhook' => ['scheme' => '', 'header' => null, 'events' => []],
                'capabilities' => ['hosted_checkout' => false, 'status_lookup' => false, 'refund' => false, 'recurring' => false, 'webhook' => false],
                'methods' => [],
                'docs' => 'https://www.hyperpay.com',
            ],
            'paytabs' => [
                'key' => 'paytabs', 'label' => ['ar' => 'باي تابس', 'en' => 'PayTabs'],
                'fields' => [],
                'test_prefix' => null, 'live_prefix' => null, 'prefixed_field' => null,
                'webhook' => ['scheme' => '', 'header' => null, 'events' => []],
                'capabilities' => ['hosted_checkout' => false, 'status_lookup' => false, 'refund' => false, 'recurring' => false, 'webhook' => false],
                'methods' => [],
                'docs' => 'https://site.paytabs.com',
            ],
            'stripe' => [
                'key' => 'stripe', 'label' => ['ar' => 'سترايب', 'en' => 'Stripe'],
                'fields' => [
                    $field('secret_key', 'المفتاح السري', 'Secret key', true, true, 'STRIPE_SECRET_KEY'),
                    $field('publishable_key', 'المفتاح العام', 'Publishable key', false, false, 'STRIPE_PUBLISHABLE_KEY'),
                    $field('webhook_secret', 'سر توقيع الويب هوك', 'Webhook signing secret', true, true, 'STRIPE_WEBHOOK_SECRET'),
                ],
                'test_prefix' => 'sk_test_', 'live_prefix' => 'sk_live_', 'prefixed_field' => 'secret_key',
                'webhook' => ['scheme' => 'An HMAC-SHA256 `Stripe-Signature` header keyed with the webhook signing secret.', 'header' => 'Stripe-Signature', 'events' => ['checkout.session.completed', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.refunded']],
                'capabilities' => ['hosted_checkout' => true, 'status_lookup' => true, 'refund' => false, 'recurring' => true, 'webhook' => true],
                'methods' => ['visa', 'mastercard', 'applepay'],
                'docs' => 'https://docs.stripe.com',
            ],
        ];
    }
}
