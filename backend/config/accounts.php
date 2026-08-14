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
            /*
             * PHONE-VERIFY-001 — a verified mobile number, for every account, before activation.
             *
             * Not a nicety and not an anti-abuse tweak: it is how this product reaches a customer when
             * something is wrong with their campaigns, and it is the only identity in the system that
             * is expensive to fake in bulk. An account with an unverified number is one nobody can be
             * called about, and one that a script can mint by the hundred from disposable addresses.
             *
             * It applies to every route in — including registering and signing in with an email
             * address. The email proves the address; it says nothing about the phone.
             */
            'requires_mobile' => true,
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

    /*
     * Re-issuing a verification challenge, in PRODUCTION (SIGNUP-THROTTLE-001).
     *
     * Two limits, because the risk has two shapes. The first protects the APPLICANT — their phone,
     * and the SMS credit spent on it — and is the one that matters; three a minute is more than
     * anybody legitimately needs. The second is an abuse ceiling on the address, so opening
     * applications in a loop cannot be used to walk around the first.
     *
     * Off-production both are far larger: the acceptance suite answers the mobile gate for several
     * applications a minute from one address. See `AppServiceProvider`.
     */
    'resend_throttle_per_application' => (int) env('REGISTRATION_RESEND_PER_APPLICATION', 3),
    'resend_throttle_per_address' => (int) env('REGISTRATION_RESEND_PER_ADDRESS', 12),

    /*
     * The off-production allowance, config-driven for the same reason `auth.login_throttle_local` is:
     * the limiter has to stay REACHABLE by a test. `RegistrationResendThrottleTest` sets these to the
     * production figures and drives the real endpoint, so what it measures is the live limiter and
     * its live keying — which is the part the defect was in.
     */
    'resend_throttle_per_application_local' => (int) env('REGISTRATION_RESEND_PER_APPLICATION_LOCAL', 60),
    'resend_throttle_per_address_local' => (int) env('REGISTRATION_RESEND_PER_ADDRESS_LOCAL', 600),
];
