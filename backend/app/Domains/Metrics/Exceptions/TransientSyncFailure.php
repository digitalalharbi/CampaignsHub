<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Exceptions;

use RuntimeException;

/**
 * INTEG-SYNC-ENGINE-001 — the provider did not answer in a way a retry could not change (429, 5xx, no connection).
 *
 * The run is already recorded as failed. This exists so the QUEUE counts the attempt: thrown, it spends one of the job's
 * `maxExceptions`, waits out `backoff()`, and on the last one reaches `failed()`, which writes the dead letter. It carries a category,
 * never the provider's words.
 */
final class TransientSyncFailure extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct("The sync attempt failed and will be retried ({$category}).");
    }

    /** An ordinary retry is recorded on the run ledger; it is not an application error for the log. */
    public function report(): void {}
}
