<?php

declare(strict_types=1);

use App\Domains\Billing\Providers\MoyasarPaymentProvider;
use App\Domains\Billing\Providers\NullPaymentProvider;
use App\Domains\Billing\Providers\SandboxPaymentProvider;
use App\Domains\Billing\Providers\StripePaymentProvider;

return [

    /*
    |---------------------------------------------------------------------------
    | Subscription billing — the platform's own revenue (PAY-001)
    |---------------------------------------------------------------------------
    |
    | Kept separate from `config/billing.php`, which governs what a TENANT invoices
    | its own clients. Same adapters, different money: mixing the two would make
    | "revenue" a number that answers neither question.
    |
    | Moyasar is the official and primary gateway. Stripe is the alternative. The
    | shipped default is whichever is configured, resolved at runtime — see
    | PaymentProviderRegistry::defaultKey() — so an install with no credentials
    | reports Awaiting Credentials rather than pretending to have a gateway.
    */
    'default' => env('SUBSCRIPTION_PROVIDER', 'moyasar'),

    'providers' => [
        'moyasar' => MoyasarPaymentProvider::class,
        'stripe' => StripePaymentProvider::class,
        /*
         * A gateway that is honestly not one (PAY-SANDBOX-001).
         *
         * Since PLAN-PAID-001 nothing is activated without a verified payment, which leaves an
         * installation with no credentials unable to walk its own registration journey — including
         * the acceptance suite. The sandbox adapter closes that by being a REAL adapter with a real
         * signature check, over a secret this installation generated. It refuses to configure itself
         * in production whatever this file says, so reaching for it there yields Awaiting Credentials
         * — a stranded signup, which is the safe failure.
         */
        'sandbox' => SandboxPaymentProvider::class,
        'null' => NullPaymentProvider::class,
    ],

    /*
     * The sandbox's signing secret. Empty switches the sandbox off entirely.
     *
     * Defaulted off-production only, so the shipped configuration of a production deploy has no
     * sandbox regardless of the `default` above.
     */
    'sandbox_secret' => env('SUBSCRIPTION_SANDBOX_SECRET', env('APP_ENV') === 'production' ? '' : 'local-sandbox-secret'),

    /*
     * What CampaignsHub charges a customer IN — USD (PAY-AUDIT-002, SUB-USD-001).
     *
     * This is the fallback every subscription charge lands on when the subscription row itself
     * carries no currency: renewals, reactivations, plan changes, proration and the notifications
     * that quote them all read it. It defaulted to `SAR`, so a subscription that had somehow lost its
     * own currency was quietly re-denominated on its next charge.
     *
     * NOT to be confused with `billing.currency`, which stays SAR. That one governs an AGENCY
     * invoicing its own CLIENT — a different party, a different transaction, and deliberately not
     * tied to what the agency pays CampaignsHub. Nor with the advertising side, which reports in SAR
     * and keeps each platform's original amount and currency untouched.
     */
    'currency' => env('SUBSCRIPTION_CURRENCY', 'USD'),

    /*
    |---------------------------------------------------------------------------
    | Lifecycle
    |---------------------------------------------------------------------------
    |
    | How long a failed renewal keeps working before the account is suspended. The
    | value here is the DEFAULT; each subscription carries its own `grace_ends_at`,
    | so a customer given longer keeps it even if this changes afterwards.
    */
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),

    /*
    |---------------------------------------------------------------------------
    | Trial-abuse prevention (PAY-004)
    |---------------------------------------------------------------------------
    |
    | Which identities a trial is counted against. Every one is stored HASHED — the
    | question we answer is "has this been seen before?", which needs no plaintext.
    |
    | `payment_method` depends on what the provider exposes: Stripe publishes a card
    | fingerprint that is stable across customers, Moyasar does not, so the adapter
    | reports what it can and the check is honest about the difference.
    */
    'trial' => [
        'one_per' => [
            'email' => true,
            'phone' => true,
            'company' => true,
            'payment_method' => true,
        ],
    ],

];
