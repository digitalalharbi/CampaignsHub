<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Integration ORCHESTRATION (RUNTIME-100)
|--------------------------------------------------------------------------
|
| Not the platforms themselves — those live in `ad_platforms.php` and `commerce_platforms.php`. This
| file holds the decisions that are the SAME for every provider: how far back a first sync reaches,
| how often an assigned account is re-asked, and how long one sync may hold its own lock.
|
| These are configuration rather than constants for one reason: each of them is a number that will be
| revised once a real account's history and a real provider's rate limit have been measured, and a
| number that has to be revised in a service is a number nobody revises.
*/

return [

    'first_sync' => [
        /*
         * How much history a newly connected account is asked for.
         *
         * A first sync that fetches only today shows an empty dashboard on the day somebody connects,
         * which is the worst first impression a product whose whole claim is «your data, in one
         * place» can make. A first sync that fetches two years spends the provider's rate limit on
         * data nobody scrolled back to, and delays the numbers that ARE being waited for.
         *
         * Thirty days is the shortest window that covers a full billing month, which is the period
         * every customer compares against first. It is clamped to a year in `FirstSync` — beyond that
         * several of the six providers refuse the request rather than truncating it.
         */
        'backfill_days' => (int) env('INTEGRATIONS_FIRST_SYNC_BACKFILL_DAYS', 30),
    ],

    'incremental' => [
        /*
         * How far back a ROUTINE sweep re-asks, so late attribution can settle.
         *
         * Every platform restates recent days: conversions attribute late, spend is corrected, fraud
         * is refunded. A sweep that only ever asked for today would freeze each day's numbers at
         * their most wrong. This is the same seven days `integrations:sync` has always defaulted to,
         * named here so the first-sync window and the settling window are visibly different
         * decisions rather than two literals that happen to differ.
         */
        'restate_days' => (int) env('INTEGRATIONS_RESTATE_DAYS', 7),
    ],

    'locking' => [
        /*
         * How long one account's sync may hold its lock before another attempt may proceed.
         *
         * The lock exists so two overlapping runs cannot both write the same account's window — a
         * scheduled sweep landing on top of somebody pressing «Sync now» is the ordinary case, not
         * the exotic one. The timeout is a deadlock escape: a worker killed mid-sync must not lock
         * an account out for ever, and fifteen minutes is comfortably longer than any single
         * account's sync and far shorter than the six-hour structure sweep.
         */
        'account_sync_seconds' => (int) env('INTEGRATIONS_ACCOUNT_LOCK_SECONDS', 900),
    ],
];
