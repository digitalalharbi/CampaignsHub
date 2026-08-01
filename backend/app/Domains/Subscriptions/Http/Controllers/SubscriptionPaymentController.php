<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Services\ApplySubscriptionPaymentEvent;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying for a subscription, and hearing back about it (PAY-002).
 *
 * Both endpoints are public and neither trusts its caller. `checkout` opens a charge for an
 * application that owes one — it can be called by anyone holding the registration id, and the worst
 * that does is create a pending payment for a fee that applicant already owed. `webhook` is where the
 * gateway speaks, and the adapter's signature check is the only thing that makes anything happen.
 *
 * What is deliberately ABSENT is any endpoint a browser could use to say "I have paid". Returning
 * from a payment page is not evidence: it can be closed, faked, or replayed.
 */
final class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionCheckout $checkout,
        private readonly ApplySubscriptionPaymentEvent $events,
        private readonly SubscriptionProviderRegistry $providers,
    ) {}

    /** GET /api/v1/payments/providers — what can actually take money right now. */
    public function providers(): JsonResponse
    {
        $configured = (array) config('subscriptions.providers', []);
        $default = (string) config('subscriptions.default');

        $providers = [];

        foreach (array_keys($configured) as $key) {
            if ($key === 'null') {
                continue;
            }

            $adapter = $this->providers->for((string) $key);

            $providers[] = [
                'provider' => $adapter->name(),
                'is_default' => $key === $default,
                // Honest, and the whole point of this endpoint: an interface that offers a gateway
                // with no credentials is offering a button that cannot work.
                'status' => $adapter->isConfigured() ? 'live' : 'awaiting_credentials',
                'available' => $adapter->isConfigured(),
            ];
        }

        return ApiResponse::success(['providers' => $providers], 'Payment providers.');
    }

    /**
     * POST /api/v1/auth/registration/{registration}/checkout
     *
     * Opens (or returns) the charge this application owes. Idempotent by construction: the same
     * application and plan always resolve to the same payment, so a double-submitted form cannot
     * bill twice.
     */
    public function checkout(Request $request, RegistrationRequest $registration): JsonResponse
    {
        if (! $this->checkout->owesPayment($registration)) {
            return ApiResponse::error(__('billing.no_payment_due'), status: 422);
        }

        $data = $request->validate(['provider' => ['sometimes', 'string', 'max:32']]);

        $result = $this->checkout->startTrial($registration, $data['provider'] ?? null);
        $payment = $result['payment'];

        return ApiResponse::success([
            'payment' => [
                'id' => (string) $payment->getKey(),
                'status' => $payment->status,
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'provider' => $payment->provider,
            ],
            'checkout_url' => $result['checkout_url'],
            // `awaiting_credentials` is reported as itself. It is not a failure and not a pending
            // payment: no gateway is configured, so nobody is paying anything.
            'status' => $result['status'],
            'refused' => $result['refused'],
        ], 'Checkout.');
    }

    /**
     * POST /api/v1/payments/webhook/{provider}
     *
     * The gateway's own voice. No auth, because a gateway has no session — the signature IS the
     * authentication, and an unverified body reaches nothing.
     *
     * Always answers 200. A gateway that gets an error retries, and retrying a payload we have
     * already rejected achieves nothing except noise; what matters is recorded either way.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        /** @var array<string,string> $headers */
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        $event = $this->events->handle($provider, $request->getContent(), $headers);

        return ApiResponse::success(
            ['event_id' => $event->event_id, 'verified' => (bool) $event->verified],
            'Webhook received.',
        );
    }
}
