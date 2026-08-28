<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — the inventory, pinned so it cannot shrink in silence.
 *
 * The row's next step was «inventory existing schedulers/queues before adding anything», and the
 * inventory turned out to be substantial: sync, structure sync, token refresh, digests, alerts,
 * commerce, FX rates, scheduled reports, pruning, SLA and subscription lifecycle all run on the
 * scheduler already. So the work here is not to build automation — it is to stop it disappearing.
 *
 * The failure this exists to catch is specific and quiet: a command dropped from `routes/console.php`
 * during a refactor. Nothing breaks, no test fails, no page changes — the product simply stops
 * syncing, or stops emailing, and the first person to notice is a customer whose figures went stale
 * a week ago. «No silent background magic» cuts both ways: work that vanishes silently is the same
 * defect as work that appears silently.
 *
 * Written OUT rather than derived from the schedule, deliberately. A test that reads the schedule
 * and asserts the schedule agrees with itself would pass on the day somebody deletes half of it.
 */
final class ScheduledWorkInventoryTest extends TestCase
{
    /**
     * Every command this product depends on running without anybody pressing a button.
     *
     * Adding one here is how a new piece of automation announces itself; removing one is a decision
     * somebody has to make deliberately, in a diff a reviewer can see.
     */
    private const EXPECTED = [
        'alerts:evaluate',
        'commerce:sync',
        'fx:rates',
        'integrations:prune-raw',
        'integrations:refresh-tokens',
        'integrations:sync',
        'integrations:sync-structure',
        'notifications:send-alerts',
        'notifications:send-digests',
        'reports:dispatch-scheduled',
        'requests:evaluate-sla',
        'requests:prune-uploads',
        'subscriptions:lifecycle',
    ];

    /** @return list<string> the artisan commands the scheduler will actually run */
    private function scheduled(): array
    {
        $commands = [];

        foreach (app(Schedule::class)->events() as $event) {
            // `Schedule::call()` closures have no command string; they are covered by their own tests.
            if (! property_exists($event, 'command') || $event->command === null) {
                continue;
            }

            /*
             * The command line is `'php' 'artisan' 'name' ...` — the artisan name is what a person
             * looking for «is the sync scheduled?» actually searches for.
             */
            if (preg_match("/artisan'?\s+'?([a-z0-9:_-]+)/i", (string) $event->command, $m) === 1) {
                $commands[] = $m[1];
            }
        }

        return array_values(array_unique($commands));
    }

    /** Nothing in the inventory has quietly stopped being scheduled. */
    public function test_every_expected_job_is_still_scheduled(): void
    {
        $scheduled = $this->scheduled();

        foreach (self::EXPECTED as $command) {
            $this->assertContains(
                $command,
                $scheduled,
                "«{$command}» is no longer scheduled — automation that disappears is as silent as automation that appears",
            );
        }
    }

    /**
     * …and nothing is scheduled that this inventory has never heard of.
     *
     * The other direction matters too: a job added without a line here is a job nobody reviewed as
     * automation, which is exactly what «no silent background magic» forbids.
     */
    public function test_nothing_runs_that_the_inventory_does_not_name(): void
    {
        $unknown = array_diff($this->scheduled(), self::EXPECTED);

        $this->assertSame(
            [],
            array_values($unknown),
            'a scheduled command is not named in the inventory: '.implode(', ', $unknown),
        );
    }

    /**
     * The jobs that can overlap say so.
     *
     * A sync, a structure sweep or a token refresh that starts while the last one is still running
     * does not merely waste time — it double-writes, and a duplicated row is the one failure this
     * product's whole money contract is built to prevent. `withoutOverlapping()` is what stops it,
     * and it is invisible unless something asserts it.
     */
    public function test_the_jobs_that_must_not_overlap_say_so(): void
    {
        $mustNotOverlap = [
            'integrations:sync',
            'integrations:sync-structure',
            'integrations:refresh-tokens',
            'notifications:send-digests',
            'notifications:send-alerts',
            'commerce:sync',
            'fx:rates',
            'subscriptions:lifecycle',
        ];

        foreach (app(Schedule::class)->events() as $event) {
            if (! property_exists($event, 'command') || $event->command === null) {
                continue;
            }
            if (preg_match("/artisan'?\s+'?([a-z0-9:_-]+)/i", (string) $event->command, $m) !== 1) {
                continue;
            }
            if (! in_array($m[1], $mustNotOverlap, true)) {
                continue;
            }

            $this->assertNotEmpty(
                $event->withoutOverlapping,
                "«{$m[1]}» may overlap itself — a second run while the first is writing is how a row gets written twice",
            );
        }
    }
}
