<?php

declare(strict_types=1);

/*
 * PLATFORM-PAYMENT-ALERTS-001 — the windows the payment and sign-up sweep waits before telling the platform owner.
 *
 * Every value has a working default, so none of these needs to be set in production.
 */
return [
    // Off switch for the whole sweep (the command still runs and says it is off).
    'enabled' => (bool) env('PLATFORM_ALERTS_ENABLED', true),

    // A payment page opened this long ago with no confirmed payment is an abandoned checkout.
    'abandoned_after_minutes' => (int) env('PLATFORM_ALERTS_ABANDONED_MINUTES', 30),

    // A charge the gateway reports, still not confirmed here after this long, is «paid but not verified».
    'unverified_after_minutes' => (int) env('PLATFORM_ALERTS_UNVERIFIED_MINUTES', 30),

    // A confirmed payment that has not activated its subscription or invoice after this long is a mismatch.
    'activation_grace_minutes' => (int) env('PLATFORM_ALERTS_ACTIVATION_GRACE_MINUTES', 15),

    // A verification code still unused this long after it expired, with the customer still stuck, is reported.
    'otp_stuck_after_minutes' => (int) env('PLATFORM_ALERTS_OTP_STUCK_MINUTES', 30),

    // A confirmed payment that has not appeared in a settlement after this many days (only with a settlement source).
    'settlement_after_days' => (int) env('PLATFORM_ALERTS_SETTLEMENT_DAYS', 4),

    // A grouped technical error that grows is mailed again, at most this often.
    'group_reannounce_hours' => (int) env('PLATFORM_ALERTS_GROUP_REANNOUNCE_HOURS', 3),

    /*
     * How far back a sweep looks. The first run after a deploy must not page the owner about every pending row in
     * the table's history; anything older than this is history, not an alert.
     */
    'lookback_hours' => (int) env('PLATFORM_ALERTS_LOOKBACK_HOURS', 72),

    // The settlement check looks further back, because a settlement is expected days after the payment.
    'settlement_lookback_days' => (int) env('PLATFORM_ALERTS_SETTLEMENT_LOOKBACK_DAYS', 30),

    // The owner's clock for every time an alert states.
    'timezone' => 'Asia/Riyadh',
];
