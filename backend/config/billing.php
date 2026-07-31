<?php

declare(strict_types=1);

use App\Domains\Billing\Providers\MoyasarPaymentProvider;
use App\Domains\Billing\Providers\NullPaymentProvider;
use App\Domains\Billing\Providers\StripePaymentProvider;

return [
    /*
    |---------------------------------------------------------------------------
    | Default payment gateway
    |---------------------------------------------------------------------------
    | The provider key used when startPayment() is called without an explicit
    | provider. Ships as the honest Null adapter — no gateway, no money moves.
    */
    'default' => env('BILLING_PROVIDER', 'null'),

    /*
    |---------------------------------------------------------------------------
    | Gateway adapters
    |---------------------------------------------------------------------------
    | Each provider key maps to a PaymentProvider implementation. The Null adapter
    | is a placeholder that never opens a real session and never verifies a
    | webhook — payments stay `pending` and nothing is ever marked paid. Wire a
    | real gateway by binding a configured class here; nothing else changes.
    */
    'providers' => [
        /*
         * The SAME adapters the platform's own revenue uses (PAY-001).
         *
         * One port, two money streams: an agency collecting from its clients and CampaignsHub
         * collecting from the agency go through identical code with separate configuration. Adding a
         * gateway is a line here and nothing else — no call site changes, which is what "unified
         * payment adapter" has to mean to be worth anything.
         */
        'moyasar' => MoyasarPaymentProvider::class,
        'stripe' => StripePaymentProvider::class,
        'null' => NullPaymentProvider::class,
    ],

    // Kept explicit so a static scan proves the shipped default is the honest Null adapter.
    'defaults' => [
        'null' => NullPaymentProvider::class,
    ],

    // Default settlement currency for quotes/invoices when none is supplied.
    'currency' => env('BILLING_CURRENCY', 'SAR'),
];
