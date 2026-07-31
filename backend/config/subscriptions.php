<?php

declare(strict_types=1);

use App\Domains\Billing\Providers\MoyasarPaymentProvider;
use App\Domains\Billing\Providers\NullPaymentProvider;
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
        'null' => NullPaymentProvider::class,
    ],

    'currency' => env('SUBSCRIPTION_CURRENCY', 'SAR'),

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
