<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Queue\QueueContract;
use Tests\TestCase;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — every queued job takes a RETRY STANCE, and the policy is checked.
 *
 * `QueueRetryContractTest` already pins how the three timeouts relate. It says nothing about retry,
 * which the requirement names separately and for a different failure: «no automatic retry that hides
 * a deterministic product defect».
 *
 * Retry and backoff were a per-job habit. Five of the eight jobs happened to have both; the habit is
 * not a policy, and the failure it fails to prevent is a NEW job — written next month, under
 * deadline, by whoever — shipping with no stance at all and nothing objecting.
 *
 * Two rules, both about the same thing: an unbounded or instant retry is not a decision, it is the
 * absence of one.
 *
 *   1. **Bounded.** Laravel reads an absent or zero `$tries` as «retry forever» unless `retryUntil()`
 *      says otherwise. Forever is how a deterministic defect becomes a job failing every ninety
 *      seconds until somebody happens to look at the queue — the retry hiding the defect, exactly as
 *      the requirement puts it.
 *   2. **Spaced, when it retries at all.** Three attempts in one second is the same failure three
 *      times, and against a rate limiter it is how a 429 becomes a longer one. A job that does not
 *      retry needs no backoff and is not asked for one.
 *
 * Jobs are DISCOVERED, never listed — a list is what nobody updates, and an unlisted new job is the
 * whole hazard. Exemptions ARE listed, deliberately: an exemption is a decision, and a decision that
 * is not written down is indistinguishable from an oversight.
 */
final class QueueRetryPolicyTest extends TestCase
{
    /**
     * Jobs that may retry without a backoff, and the reason each one may.
     *
     * Empty on purpose. It exists so that the next exemption has to be argued in writing rather than
     * taken by adding `$tries` and forgetting the rest.
     *
     * @var array<class-string, string>
     */
    private const BACKOFF_EXEMPT = [];

    public function test_every_queued_job_bounds_its_retries(): void
    {
        $unbounded = [];

        foreach (QueueContract::jobRetryStances() as $class => $stance) {
            $bounded = (is_int($stance['tries']) && $stance['tries'] > 0) || $stance['retryUntil'];

            if (! $bounded) {
                $unbounded[] = $class;
            }
        }

        $this->assertSame(
            [],
            $unbounded,
            "These jobs retry forever. Declare `public int \$tries` or `retryUntil()`:\n  ".implode("\n  ", $unbounded),
        );
    }

    public function test_a_job_that_retries_waits_between_attempts(): void
    {
        $instant = [];

        foreach (QueueContract::jobRetryStances() as $class => $stance) {
            $retries = ! is_int($stance['tries']) || $stance['tries'] > 1 || $stance['retryUntil'];

            if ($retries && ! $stance['backoff'] && ! array_key_exists($class, self::BACKOFF_EXEMPT)) {
                $instant[] = $class;
            }
        }

        $this->assertSame(
            [],
            $instant,
            "These jobs retry immediately. Declare `backoff()`, or add them to BACKOFF_EXEMPT with a reason:\n  ".implode("\n  ", $instant),
        );
    }

    /**
     * A backoff must actually WAIT, and must not shrink.
     *
     * `[0, 0, 0]` satisfies "declares a backoff" and is three attempts in a second wearing a policy's
     * clothes; a ladder that gets shorter spends its last attempt soonest, when the cause has had the
     * least time to clear.
     */
    public function test_a_declared_backoff_is_an_increasing_wait(): void
    {
        foreach (QueueContract::jobRetryStances() as $class => $stance) {
            if (! $stance['backoff'] || ! method_exists($class, 'backoff')) {
                continue;
            }

            /** @var array<int, int>|int $delays */
            $delays = (new \ReflectionMethod($class, 'backoff'))->invoke(
                (new \ReflectionClass($class))->newInstanceWithoutConstructor(),
            );

            $delays = is_array($delays) ? $delays : [$delays];

            $this->assertNotEmpty($delays, "{$class} declares an empty backoff.");

            foreach ($delays as $delay) {
                $this->assertGreaterThan(0, $delay, "{$class} backs off for zero seconds.");
            }

            $sorted = $delays;
            sort($sorted);
            $this->assertSame($sorted, $delays, "{$class} backs off by a shrinking ladder.");
        }
    }

    /**
     * The exemption list names only real jobs.
     *
     * A stale exemption is worse than none: it reads as a decision that still applies, and it silently
     * covers whatever class later takes that name.
     */
    public function test_the_exemption_list_has_no_ghosts(): void
    {
        $known = array_keys(QueueContract::jobRetryStances());
        $ghosts = array_values(array_diff(array_keys(self::BACKOFF_EXEMPT), $known));

        /*
         * Asserted as a set rather than in a loop, so that an EMPTY exemption list still runs an
         * assertion. A loop over nothing is a test that passes without checking anything, and this
         * list is empty today — the shape most likely to rot into a permanent green.
         */
        $this->assertSame([], $ghosts, 'Exempted from a policy they are not subject to: '.implode(', ', $ghosts));
    }

    /** The policy is over every discovered job, so an empty discovery would pass it vacuously. */
    public function test_the_policy_is_checking_something(): void
    {
        $this->assertGreaterThanOrEqual(5, count(QueueContract::jobRetryStances()));
    }
}
