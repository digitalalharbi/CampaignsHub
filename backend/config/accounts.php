<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Registration policy (SIGNUP-002)
    |--------------------------------------------------------------------------
    |
    | What an applicant must clear before a workspace is created for them. Read by
    | `RegistrationPolicy`, which merges default → account type → plan, so the plan is the most
    | specific statement and wins.
    |
    | The default is EMAIL VERIFICATION **AND PAYMENT** (PLAN-PAID-001).
    |
    | It used to be email verification alone, which was defensible while «البداية» was free: an
    | application that owes nothing has no payment to verify. With the free tier withdrawn there is no
    | such application left, and leaving the gate off would mean the plan a customer picked decided
    | whether the platform bothered to be paid — the very thing the brief forbids («يمنع إنشاء مساحة
    | عمل مفعلة أو منح صلاحيات تشغيلية قبل تحقق الدفع فعليًا»).
    |
    | Turning this on grants nothing by itself. `requires_payment` only decides where an application
    | WAITS; what lets it through is `AdvanceRegistration::paymentConfirmed()`, and the sole caller of
    | that is a webhook the gateway signed. A misconfiguration here can strand an account, never
    | activate one.
    |
    | A named exception for one account is not made here. It is granted per application from /admin
    | and stored on the row (`review_concessions.policy`), where it carries who granted it and why.
    |
    */
    'registration' => [
        'default' => [
            'requires_mobile' => false,
            'requires_approval' => false,
            'requires_payment' => true,
        ],

        // Coarser than a plan and finer than the default: "every agency is reviewed" without
        // deciding anything about which plan they picked.
        'account_types' => [
            // 'agency' => ['requires_approval' => true],
        ],

        // A plan is the most specific statement and overrides both.
        'plans' => [
            // 'growth' => ['requires_payment' => true],
        ],
    ],

    /*
     * Public registration attempts per minute per IP, in PRODUCTION (APP-100).
     *
     * Off-production the limiter grants headroom instead — the acceptance suite legitimately opens
     * more accounts a minute from one address than any human would. See `AppServiceProvider`.
     */
    'registration_throttle' => (int) env('REGISTRATION_THROTTLE_PER_MINUTE', 6),
];
