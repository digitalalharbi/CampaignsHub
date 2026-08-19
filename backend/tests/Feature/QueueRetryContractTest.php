<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Support\Queue\QueueContract;
use Tests\TestCase;

/**
 * SNAP-STRUCTURE-RETRY-001 — the test that would have caught it.
 *
 * The live defect was not a bug in any function. `config/queue.php` said 90 seconds, the structure
 * job said 900, and the two files never met: Redis re-delivered a running job every ninety seconds
 * until its attempts were spent, and the Snapchat account's campaigns, ad squads, ads and creatives
 * were never once written. Two thousand backend tests passed throughout, because every one of them
 * tested behaviour inside a job and none of them tested the conditions under which a job is allowed
 * to finish.
 *
 * So this suite asserts a relationship between configuration files rather than an outcome of code,
 * and it reads jobs by discovery — a long job added next year is covered without anybody remembering
 * this file exists.
 */
final class QueueRetryContractTest extends TestCase
{
    public function test_the_shipped_configuration_satisfies_the_queue_timeout_contract(): void
    {
        $this->assertSame(
            [],
            QueueContract::violations(),
            "The queue's timeout contract is broken. Long jobs will be re-delivered while still running.",
        );
    }

    /**
     * The specific ordering, spelled out, so a failure names the number to change.
     */
    public function test_retry_after_is_above_every_worker_and_job_timeout(): void
    {
        $longestJob = QueueContract::longestJobTimeout();
        $slowestWorker = max(QueueContract::workerTimeouts());

        $this->assertGreaterThanOrEqual(
            $longestJob,
            $slowestWorker,
            "A worker timeout below the longest job's timeout kills work that was allowed to take that long.",
        );

        foreach (QueueContract::retryAfterByConnection() as $connection => $retryAfter) {
            $this->assertGreaterThan(
                max($longestJob, $slowestWorker),
                $retryAfter,
                "Connection '{$connection}': retry_after must be above every timeout, or the broker "
                .'hands a running job to a second worker.',
            );
        }
    }

    /**
     * The exact assertion the brief asked for, against the exact job that failed — and against the
     * connection PRODUCTION uses, not the one the test suite runs on.
     *
     * The suite runs on `sync`, which executes inline and can never re-deliver anything. Asserting
     * against `config('queue.default')` here would therefore have asserted nothing at all, which is
     * a fair description of how the original defect survived a full green build. The deployed
     * environment file names the real connection, so the test reads it.
     */
    public function test_the_structure_job_cannot_be_redelivered_while_it_is_still_running(): void
    {
        $timeout = (new SyncAccountStructureJob('any-account'))->timeout;
        $connection = $this->productionQueueConnection();
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        $this->assertNotNull(
            $retryAfter,
            "Production runs QUEUE_CONNECTION={$connection}, which declares no retry_after.",
        );

        $this->assertGreaterThan(
            $timeout,
            $retryAfter,
            "SyncAccountStructureJob may run for {$timeout}s while '{$connection}' re-delivers after {$retryAfter}s. "
            .'This is the production failure of 2026-08: three attempts ninety seconds apart on one sweep that '
            .'had never stopped, ending in MaxAttemptsExceeded with the structure run row left at «running».',
        );
    }

    /**
     * The connection production is deployed with, read from the deployment's own environment file.
     */
    private function productionQueueConnection(): string
    {
        $path = base_path('../deploy/backend.production.env.example');

        $this->assertFileExists($path, 'The production environment example is what tells this test which connection is real.');

        preg_match('/^QUEUE_CONNECTION=(.+)$/m', (string) file_get_contents($path), $matches);

        $this->assertNotEmpty($matches, 'The production environment example does not set QUEUE_CONNECTION.');

        return trim($matches[1]);
    }

    /**
     * Fail-first, kept: with the values production actually held, the contract reports the defect.
     *
     * Without this, a green suite proves only that today's numbers happen to be fine — not that the
     * check is capable of failing.
     */
    public function test_the_contract_rejects_the_configuration_that_was_live(): void
    {
        config(['queue.connections.redis.retry_after' => 90]);

        $problems = QueueContract::violations();

        $this->assertNotEmpty($problems, 'retry_after = 90 is the broken production value and must be rejected.');
        $this->assertStringContainsString('redis', implode(' ', $problems));
        $this->assertStringContainsString('retry_after 90s', implode(' ', $problems));
    }

    /**
     * The other half of the ordering: a worker that cannot outlast the job it is given.
     */
    public function test_the_contract_rejects_a_worker_timeout_below_the_longest_job(): void
    {
        config(['horizon.defaults.supervisor-1.timeout' => 120]);

        $problems = implode(' ', QueueContract::violations());

        $this->assertStringContainsString('defaults/supervisor-1', $problems);
        $this->assertStringContainsString('below the longest job timeout', $problems);
    }
}
