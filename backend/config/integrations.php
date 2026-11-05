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

    'checkpoints' => [
        /*
         * INTEG-SYNC-ENGINE-001 — how far a scheduled sweep may reach back to its checkpoint after a gap.
         *
         * The first-sync backfill plus a margin: a resume is at worst a first sync. An outage longer than this is capped, the days it
         * skips are written on the checkpoint, and `integrations:resync-window` reads them on a person's say-so.
         */
        'resume_max_days' => (int) env('SYNC_RESUME_MAX_DAYS', 35),

        /*
         * A grain can be stuck for a reason a re-read does not cure (a campaign not discovered, a currency unknown). A wide read that
         * got an answer is not repeated for this many hours: once a day, against 48 half-hourly sweeps.
         */
        'resume_cooldown_hours' => (int) env('SYNC_RESUME_COOLDOWN_HOURS', 24),
    ],

    'rate_budget' => [
        /*
         * INTEG-SYNC-ENGINE-001 — requests per window, enforced on sync jobs by `ProviderRateBudget`.
         *
         * The defaults are a brake on a runaway, chosen so today's traffic cannot reach them. `supervisor-sync` runs ONE process, so
         * requests are sequential: at a 250 ms round trip one worker makes at most 14,400 an hour, whoever it is working for. The
         * largest account in the code's own record is 89 campaigns, about 180 ad-set and ad requests plus a few for the campaign and
         * creative grains: 2 sweeps an hour would need 40 such accounts on ONE connection to reach 15,000. They are NOT a fit to any
         * provider's quota. Read `meta.provider_requests` on the run ledger, then lower them per provider below.
         */
        'enabled' => (bool) env('SYNC_BUDGET_ENABLED', true),
        'window_seconds' => (int) env('SYNC_BUDGET_WINDOW_SECONDS', 3600),
        'provider_requests' => (int) env('SYNC_BUDGET_PROVIDER_REQUESTS', 30000),
        'connection_requests' => (int) env('SYNC_BUDGET_CONNECTION_REQUESTS', 15000),

        // No connection may spend more than this share of its provider's pool, whatever `connection_requests` says.
        'connection_share' => (float) env('SYNC_BUDGET_CONNECTION_SHARE', 0.5),

        // A provider's own limit, where it differs from `provider_requests`. Null falls through to the default.
        'providers' => [
            'meta' => env('SYNC_BUDGET_META_REQUESTS'),
            'snapchat' => env('SYNC_BUDGET_SNAPCHAT_REQUESTS'),
            'tiktok' => env('SYNC_BUDGET_TIKTOK_REQUESTS'),
            'google' => env('SYNC_BUDGET_GOOGLE_REQUESTS'),
            'linkedin' => env('SYNC_BUDGET_LINKEDIN_REQUESTS'),
            'x' => env('SYNC_BUDGET_X_REQUESTS'),
            'openai_ads' => env('SYNC_BUDGET_OPENAI_ADS_REQUESTS'),
        ],

        // The longest a provider's `Retry-After` or usage header may hold a connection: an hour, the same as a spent window.
        'max_hold_seconds' => (int) env('SYNC_BUDGET_MAX_HOLD_SECONDS', 3600),

        // Meta throttles at 100% of `X-Business-Use-Case-Usage`. Lower this to back off before it does.
        'meta_usage_hold_percent' => (int) env('SYNC_BUDGET_META_USAGE_HOLD_PERCENT', 100),

        // Null is the application's default store. The counters are per hour and may be lost without harm.
        'cache_store' => env('SYNC_BUDGET_CACHE_STORE'),
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
    'webhooks' => [
        /*
         * INTEG-WEBHOOK-ENGINE-001 — how old a SIGNED delivery may be before it is refused as a replay.
         *
         * Applied only where the provider's signed material carries a timestamp (Salla's body, Meta's
         * entry time); Zid signs nothing, so a timestamp in its body proves nothing and is not used.
         * Forty-eight hours, because Meta redelivers for 36 hours and a genuine retry must still land.
         */
        'replay_tolerance_seconds' => (int) env('WEBHOOK_REPLAY_TOLERANCE_SECONDS', 172800),

        /** A delivery stamped further in the future than this is refused: a clock that wrong is not the provider's. */
        'future_skew_seconds' => 900,

        /** Rejected deliveries recorded per provider per hour; past it the flood is counted in the log, not stored. */
        'rejected_record_cap_per_hour' => 500,

        'reconcile' => [
            /** Off unless the Owner turns it on: the scheduler still lists the command, the `when()` decides. */
            'enabled' => (bool) env('WEBHOOK_RECONCILE_ENABLED', false),
            'days' => 3,
            'max_stores' => 20,
            /** An order placed (or a row synced) more recently than this is not yet a gap: the hourly sweep owns it. */
            'grace_minutes' => 90,
        ],
    ],

    /*
     * INTEG-SOURCE-REMOVAL-001 — whether the daily sweep may HIDE connections that were started and never finished.
     * Off unless an operator sets INTEGRATIONS_ABANDONED_SWEEP_ENABLED=true after reading the command's preview.
     */
    'abandoned_sweep_enabled' => (bool) env('INTEGRATIONS_ABANDONED_SWEEP_ENABLED', false),
];
