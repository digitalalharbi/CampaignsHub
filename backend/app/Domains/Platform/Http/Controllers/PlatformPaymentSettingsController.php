<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payment gateways, from the platform owner's console (PAYSET-001).
 *
 * What this deliberately does NOT do is accept secret keys over the API and write them somewhere. A
 * console that stored gateway credentials in the database would put every customer's money behind a
 * row an SQL injection could read, and would mean rotating a key required a working application.
 * Keys live in the environment; this surface tells an operator **what the environment currently
 * supports and what is missing**, which is the question they actually have.
 *
 * The distinction that matters throughout: `live`, `sandbox` and `awaiting_credentials` are three
 * different states, and a provider that is incomplete is shown as incomplete rather than as an option
 * somebody could select and have fail silently.
 */
final class PlatformPaymentSettingsController extends Controller
{
    public function __construct(
        private readonly SubscriptionProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/admin/settings/integrations/payments
     *
     * Every provider, what it needs, what it has, and what it is therefore able to do.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'default' => (string) config('subscriptions.default'),
            'currency' => (string) config('subscriptions.currency'),
            'providers' => [
                $this->describeMoyasar(),
                $this->describeStripe(),
            ],
            // The notification transport belongs on this page too: a payment system that cannot tell
            // anybody a charge failed is only half configured, and an operator checking one will want
            // the other.
            'mail' => [
                'state' => MailTransportState::current(),
                'driver' => (string) config('mail.default'),
            ],
        ], 'Payment settings.');
    }

    /**
     * POST /api/v1/admin/settings/integrations/payments/{provider}/test
     *
     * A REAL round trip to the gateway, not a config check.
     *
     * The difference matters: config being present proves somebody typed something, and this proves
     * the gateway accepts it. An unconfigured provider is refused before any request is made, because
     * "we could not reach the gateway" and "you have not given us a key" are different problems with
     * different fixes.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $adapter = $this->providerOr404($provider);

        if (! $adapter->isConfigured()) {
            return ApiResponse::error(
                'This provider has no credentials, so there is nothing to test.',
                meta: ['status' => 'awaiting_credentials'],
                status: 422,
            );
        }

        /*
         * Tested by opening the smallest session the gateway will accept and reading the answer.
         *
         * Nothing is charged: a session is an intent, and it expires unused. Sending a real payment
         * to prove connectivity would be taking a customer's money to answer an operator's question.
         */
        $result = $adapter->createSession([
            'amount' => '1.00',
            'currency' => (string) config('subscriptions.currency'),
            'description' => 'CampaignsHub connection test',
            'reference' => 'connection-test:'.now()->timestamp,
            'return_url' => Frontend::origin().'/admin/settings',
        ]);

        $reachable = ($result['status'] ?? '') === 'created';

        $this->audit->log(
            action: 'platform.payments.connection_tested',
            entityType: 'payment_provider',
            entityId: $provider,
            after: ['reachable' => $reachable, 'status' => $result['status'] ?? null],
            userId: $request->user()?->id,
        );

        return ApiResponse::success([
            'provider' => $provider,
            'reachable' => $reachable,
            'status' => $result['status'] ?? 'failed',
            // The gateway's own words. A generic "connection failed" hides whether the key is wrong,
            // the account is closed, or the currency is unsupported — three different fixes.
            'error' => $result['error'] ?? null,
        ], $reachable ? 'The gateway accepted the request.' : 'The gateway refused the request.');
    }

    /**
     * GET /api/v1/admin/settings/integrations/payments/{provider}/webhook
     *
     * Where the gateway should send events, and what it must be configured with. An operator
     * setting up a webhook needs the URL and the secret's NAME, never the secret itself.
     */
    public function webhook(string $provider): JsonResponse
    {
        $this->providerOr404($provider);

        return ApiResponse::success([
            'provider' => $provider,
            'url' => rtrim((string) config('app.url'), '/')."/api/v1/payments/webhook/{$provider}",
            'authentication' => $provider === 'moyasar'
                ? 'A shared secret carried in the body as `secret_token`, set from MOYASAR_WEBHOOK_TOKEN.'
                : 'An HMAC-SHA256 signature in the `Stripe-Signature` header, set from STRIPE_WEBHOOK_SECRET.',
            'events' => $provider === 'moyasar'
                ? ['payment_paid', 'payment_failed', 'payment_refunded']
                : ['checkout.session.completed', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.refunded'],
        ], 'Webhook configuration.');
    }

    /**
     * Rotating a secret is an ENVIRONMENT change, and this says exactly which one.
     *
     * There is deliberately no endpoint that writes a new key. A console able to change a gateway
     * secret is a console whose compromise redirects every customer payment — and the rotation an
     * operator actually needs is at the provider, then in the environment, then a restart.
     */
    public function rotation(string $provider): JsonResponse
    {
        $this->providerOr404($provider);

        $variables = $provider === 'moyasar'
            ? ['MOYASAR_SECRET_KEY', 'MOYASAR_PUBLISHABLE_KEY', 'MOYASAR_WEBHOOK_TOKEN']
            : ['STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_WEBHOOK_SECRET'];

        return ApiResponse::success([
            'provider' => $provider,
            'variables' => $variables,
            'steps' => [
                'Issue the new key in the provider’s own dashboard, leaving the old one active.',
                'Set the new value in the environment and restart the application.',
                'Run the connection test on this page and confirm it reports reachable.',
                'Revoke the old key in the provider’s dashboard.',
            ],
            // Said plainly, because the obvious "add a rotate button" is the wrong thing to build.
            'note' => 'Secrets are never stored by CampaignsHub and cannot be changed from this console.',
        ], 'Secret rotation.');
    }

    /** @return array<string, mixed> */
    private function describeMoyasar(): array
    {
        $adapter = $this->providers->for('moyasar');

        return [
            'provider' => 'moyasar',
            'label' => ['ar' => 'ميسر', 'en' => 'Moyasar'],
            // Named, not inferred from whichever happens to be configured: Moyasar is the official
            // gateway whether or not anybody has supplied its keys yet.
            'role' => 'primary',
            'is_default' => config('subscriptions.default') === 'moyasar',
            'status' => $adapter->isConfigured() ? 'live' : 'awaiting_credentials',
            'available' => $adapter->isConfigured(),
            'environment' => $this->environmentOf((string) config('services.moyasar.secret_key')),
            'requires' => [
                ['key' => 'MOYASAR_SECRET_KEY', 'present' => $this->filled(config('services.moyasar.secret_key'))],
                ['key' => 'MOYASAR_PUBLISHABLE_KEY', 'present' => $this->filled(config('services.moyasar.publishable_key'))],
                ['key' => 'MOYASAR_WEBHOOK_TOKEN', 'present' => $this->filled(config('services.moyasar.webhook_token'))],
            ],
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/payments/webhook/moyasar',
        ];
    }

    /** @return array<string, mixed> */
    private function describeStripe(): array
    {
        $adapter = $this->providers->for('stripe');

        return [
            'provider' => 'stripe',
            'label' => ['ar' => 'سترايب', 'en' => 'Stripe'],
            'role' => 'alternative',
            'is_default' => config('subscriptions.default') === 'stripe',
            'status' => $adapter->isConfigured() ? 'live' : 'awaiting_credentials',
            'available' => $adapter->isConfigured(),
            'environment' => $this->environmentOf((string) config('services.stripe.secret_key')),
            'requires' => [
                ['key' => 'STRIPE_SECRET_KEY', 'present' => $this->filled(config('services.stripe.secret_key'))],
                ['key' => 'STRIPE_PUBLISHABLE_KEY', 'present' => $this->filled(config('services.stripe.publishable_key'))],
                ['key' => 'STRIPE_WEBHOOK_SECRET', 'present' => $this->filled(config('services.stripe.webhook_secret'))],
            ],
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/payments/webhook/stripe',
        ];
    }

    /**
     * Test keys or live keys — read from the key itself, never from a separate toggle.
     *
     * Both gateways prefix their test keys, and a toggle that could disagree with the key in use is
     * how an operator ends up certain they are in sandbox while taking real money.
     */
    private function environmentOf(string $key): string
    {
        if ($key === '') {
            return 'unset';
        }

        return str_contains($key, '_test') || str_starts_with($key, 'sk_test') ? 'sandbox' : 'live';
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function providerOr404(string $provider)
    {
        abort_unless(in_array($provider, ['moyasar', 'stripe'], true), 404);

        return $this->providers->for($provider);
    }
}
