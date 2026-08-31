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

        /*
         * How long a day's figures stay PROVISIONAL — SNAP-WINDOW-001 §10.
         *
         * Snapchat states it: «Metrics are finalized 48 hours after the end of the day in the
         * timezone set by the Ad Account.» Before that boundary a figure may still move, and its
         * responses carry `finalized_data_end_time` saying where the line currently is.
         *
         * The restate window above is what re-asks for those days. This number is here so the reason
         * for it is written down rather than inferred from «7 felt safe»: seven days comfortably
         * covers a 48-hour finalisation plus a weekend of missed sweeps, and days older than that are
         * final and are not re-fetched on every run.
         */
        'provisional_hours' => (int) env('INTEGRATIONS_PROVISIONAL_HOURS', 48),
    ],

    'chunking' => [
        /*
         * How many local days one provider request may cover.
         *
         * A first sync asks for a month at once. A provider that caps a DAY range refuses the WHOLE
         * request rather than truncating it, so the customer's very first sync is the one that fails
         * — and Snapchat's measurement reference states no hard cap for DAY granularity, which means
         * an assumption either way would be a guess.
         *
         * So this is deliberately conservative rather than derived: each chunk is upserted
         * idempotently on `(account, campaign, date, metric)`, so splitting costs round trips and
         * nothing else, and a cap we have not been told about cannot break the first impression.
         */
        'max_days_per_request' => (int) env('INTEGRATIONS_MAX_DAYS_PER_REQUEST', 7),
    ],

    'health' => [
        /*
         * How old an account's last SUCCESS may be before it is reported as delayed.
         *
         * The metrics sweep runs every thirty minutes, so yesterday's success means dozens of sweeps
         * produced nothing. A worker restart, a deploy, or a provider's quiet hour can each eat a few
         * of those legitimately, though — and this flag has to mean «something is wrong». A threshold
         * that fires on ordinary operational noise is one people learn to ignore, which costs more
         * than not having it.
         */
        'stale_after_hours' => (int) env('INTEGRATIONS_STALE_AFTER_HOURS', 48),
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
