<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    /*
     * SNAP-STRUCTURE-RETRY-001 — `retry_after` is a delivery guarantee, not a knob.
     *
     * ## The live failure
     *
     * `retry_after` shipped at Laravel's published default of 90 seconds while
     * `SyncAccountStructureJob` declares a timeout of 900. Redis therefore released the job back onto
     * the queue every ninety seconds while it was STILL RUNNING. Production showed it exactly:
     * 18:55:02 → 18:56:33 → 18:58:04, ninety-one seconds apart, three attempts spent on one piece of
     * work that had never stopped, ending in `MaxAttemptsExceeded`. No structure sweep on the live
     * Snapchat account had ever been allowed to finish, so Campaigns, Ad Sets, Ads and Creatives
     * stayed empty beside metrics that were arriving perfectly well.
     *
     * ## The invariant
     *
     *     max job timeout  <=  worker/supervisor timeout  <  retry_after
     *
     * A worker must be allowed to finish (or be killed) before the broker is allowed to hand the job
     * to somebody else. Violate it and the queue silently duplicates long work; nothing throws, and
     * the only symptom is a job that never completes. `QueueRetryContractTest` now fails the build if
     * this ordering is ever broken again.
     *
     * ## Where 1200 comes from — the request budget, not a round number
     *
     * A Snapchat structure sweep is four paged endpoint walks (campaigns, ad squads, ads, creatives).
     * `PlatformHttp` allows 4 attempts per request at a 60s HTTP timeout, and honours `Retry-After`
     * up to 120s. The realistic bad day for this account is ~60 requests at a couple of seconds each
     * (~120s) plus one full throttle wait on each of the four walks (~480s) — around ten minutes,
     * which is what the job's 900s ceiling was sized for. `retry_after` is that ceiling plus a 300s
     * margin: the time between the timeout alarm firing and the worker actually releasing the job,
     * during which `failed()` writes the run row to Postgres and Horizon records the failure.
     *
     * The margin is deliberately 1.33× the job timeout rather than an enormous value: a broker that
     * waits an hour to re-deliver genuinely lost work is its own outage.
     */
    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', env('QUEUE_RETRY_AFTER', 1200)),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', env('QUEUE_RETRY_AFTER', 1200)),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', env('QUEUE_RETRY_AFTER', 1200)),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
