<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ops\Listeners\RecordScheduledRun;
use App\Domains\Ops\Models\ScheduledRun;
use App\Domains\Ops\Services\ScheduledWorkStatus;
use App\Models\User;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — «it is scheduled» and «it ran» are different claims.
 *
 * `ScheduledWorkInventoryTest` proves thirteen commands are still registered with the scheduler. Not
 * one of them recorded that it EXECUTED, and the per-domain ledgers cannot stand in for that: when
 * `digest_sends` is empty, «the sweep ran and nobody was subscribed» and «the sweep has not run since
 * the deploy» are the same absence. The second is an outage nobody is watching.
 *
 * The trap this file is really guarding is the tempting collapse: rendering «we have never seen this
 * run» as «fine». An absence of evidence would then be published as evidence of health, on the one
 * screen whose entire job is to say whether the automation is alive.
 */
final class ScheduledWorkObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner-sched@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    /** A scheduler event standing in for one real scheduled command. */
    private function task(string $signature): ScheduledEvent
    {
        return new ScheduledEvent(
            new CacheEventMutex($this->app->make(Factory::class)),
            "'/usr/bin/php' 'artisan' {$signature}",
        );
    }

    private function listener(): RecordScheduledRun
    {
        return new RecordScheduledRun;
    }

    /** A completed run is recorded with its duration, from the scheduler's own events. */
    public function test_a_completed_run_is_recorded(): void
    {
        $task = $this->task('alerts:evaluate');

        $this->listener()->starting(new ScheduledTaskStarting($task));
        $this->listener()->finished(new ScheduledTaskFinished($task, 1.5));

        $run = ScheduledRun::query()->where('command', 'alerts:evaluate')->firstOrFail();

        $this->assertSame(ScheduledRun::COMPLETED, $run->outcome);
        $this->assertSame(1500, $run->duration_ms);
    }

    /**
     * The one a command could never record about itself.
     *
     * A sweep that throws leaves no trace in its own domain ledger, because the failure happens before
     * anything is written there. Recording from the scheduler is what makes a nightly crash visible.
     */
    public function test_a_failed_run_keeps_the_exception_that_caused_it(): void
    {
        $task = $this->task('notifications:send-digests');

        $this->listener()->starting(new ScheduledTaskStarting($task));
        $this->listener()->failed(new ScheduledTaskFailed($task, new RuntimeException('smtp exploded')));

        $run = ScheduledRun::query()->where('command', 'notifications:send-digests')->firstOrFail();

        $this->assertSame(ScheduledRun::FAILED, $run->outcome);
        $this->assertSame(RuntimeException::class, $run->failure_class);
        $this->assertStringContainsString('smtp exploded', (string) $run->failure_message);
    }

    /** Skipped is the overlap guard working, and must not be filed as a failure. */
    public function test_a_skipped_run_is_not_a_failure(): void
    {
        $task = $this->task('integrations:sync');

        $this->listener()->starting(new ScheduledTaskStarting($task));
        $this->listener()->skipped(new ScheduledTaskSkipped($task));

        $this->assertSame(
            ScheduledRun::SKIPPED,
            ScheduledRun::query()->where('command', 'integrations:sync')->firstOrFail()->outcome,
        );
    }

    /**
     * THE test. A command with no rows is «never observed», never «fine».
     *
     * The ledger can be younger than the deploy, and its writes are deliberately allowed to fail
     * without breaking the run they watch — so a missing row means «we cannot say», which is a
     * different sentence from «it did not run» and a very different one from «it is healthy».
     */
    public function test_a_command_with_no_history_is_never_observed_not_healthy(): void
    {
        $rows = app(ScheduledWorkStatus::class)->all();

        $this->assertNotSame([], $rows, 'the scheduler reported no commands at all');

        foreach ($rows as $row) {
            $this->assertSame('never_observed', $row['state'], "«{$row['command']}» claimed a history it does not have");
            $this->assertNull($row['last_outcome']);
            // «Overdue» is a claim about a command with a history. It cannot be said here at all.
            $this->assertNull($row['overdue'], "«{$row['command']}» was called overdue with nothing to compare against");
        }
    }

    /**
     * The list comes from the SCHEDULER, not from the ledger.
     *
     * Built from the ledger, a command that has never run once — precisely the failure worth catching —
     * would simply not appear on the screen that exists to catch it.
     */
    public function test_the_list_includes_commands_that_have_never_run(): void
    {
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => now(), 'finished_at' => now(),
            'outcome' => ScheduledRun::COMPLETED, 'duration_ms' => 10,
        ]);

        $commands = array_column(app(ScheduledWorkStatus::class)->all(), 'command');

        $this->assertContains('alerts:evaluate', $commands);
        $this->assertContains('notifications:send-digests', $commands, 'a command with no runs vanished from the list');
        $this->assertGreaterThan(1, count($commands));
    }

    /** A run long past its cadence is overdue — but only because there is a history to compare to. */
    public function test_a_stale_run_is_reported_overdue(): void
    {
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => Carbon::now()->subDays(9),
            'finished_at' => Carbon::now()->subDays(9), 'outcome' => ScheduledRun::COMPLETED, 'duration_ms' => 5,
        ]);

        $row = collect(app(ScheduledWorkStatus::class)->all())->firstWhere('command', 'alerts:evaluate');

        $this->assertSame('observed', $row['state']);
        $this->assertTrue($row['overdue'], 'a command last seen nine days ago was not called overdue');
    }

    /** The console counts «cannot see» apart from «failing» — they call for opposite actions. */
    public function test_the_summary_counts_unseen_apart_from_failing(): void
    {
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => now(), 'finished_at' => now(),
            'outcome' => ScheduledRun::FAILED, 'failure_class' => RuntimeException::class,
        ]);

        $body = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/scheduled-work')->assertOk()->json('data');

        $this->assertSame(1, $body['summary']['failing']);
        $this->assertGreaterThan(0, $body['summary']['never_observed']);
        $this->assertSame(count($body['scheduled']), $body['summary']['total']);
    }

    /**
     * «Failed once» and «failing every night» are different problems.
     *
     * The console showed the same thing for both: a last run with outcome `failed`. One failure
     * beside an otherwise healthy scheduler is a transient the next run may clear; the same failure
     * four nights running is a broken command nobody has looked at, and an operator triages those in
     * opposite orders.
     */
    public function test_it_counts_how_many_times_in_a_row_a_command_has_failed(): void
    {
        foreach ([3, 2, 1] as $daysAgo) {
            ScheduledRun::create([
                'command' => 'alerts:evaluate',
                'started_at' => Carbon::now()->subDays($daysAgo),
                'finished_at' => Carbon::now()->subDays($daysAgo),
                'outcome' => ScheduledRun::FAILED,
                'failure_class' => RuntimeException::class,
            ]);
        }

        $row = collect(app(ScheduledWorkStatus::class)->all())->firstWhere('command', 'alerts:evaluate');

        $this->assertSame(3, $row['consecutive_failures']);
    }

    /**
     * A success ends the streak. A command that failed last week and has run cleanly since is not
     * failing, and carrying its history forever would keep it red long after somebody fixed it.
     */
    public function test_a_later_success_ends_the_streak(): void
    {
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => Carbon::now()->subDays(3),
            'finished_at' => Carbon::now()->subDays(3), 'outcome' => ScheduledRun::FAILED,
        ]);
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => Carbon::now()->subDay(),
            'finished_at' => Carbon::now()->subDay(), 'outcome' => ScheduledRun::COMPLETED, 'duration_ms' => 10,
        ]);

        $row = collect(app(ScheduledWorkStatus::class)->all())->firstWhere('command', 'alerts:evaluate');

        $this->assertSame(0, $row['consecutive_failures']);
    }

    /**
     * A skipped run says nothing about whether the command works — the overlap guard refused a second
     * copy — so it neither breaks a streak nor extends one.
     */
    public function test_a_skipped_run_neither_breaks_nor_extends_a_streak(): void
    {
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => Carbon::now()->subDays(2),
            'finished_at' => Carbon::now()->subDays(2), 'outcome' => ScheduledRun::FAILED,
        ]);
        ScheduledRun::create([
            'command' => 'alerts:evaluate', 'started_at' => Carbon::now()->subDay(),
            'finished_at' => Carbon::now()->subDay(), 'outcome' => ScheduledRun::SKIPPED, 'duration_ms' => 0,
        ]);

        $row = collect(app(ScheduledWorkStatus::class)->all())->firstWhere('command', 'alerts:evaluate');

        $this->assertSame(1, $row['consecutive_failures'], 'a skip was read as a success or a failure');
    }

    /** The scheduler is the whole installation's; a tenant operator may not read it. */
    public function test_a_tenant_operator_cannot_read_the_installations_scheduler(): void
    {
        $stranger = User::create([
            'name' => 'Op', 'email' => 'op-sched@tenant.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/admin/scheduled-work')
            ->assertForbidden();
    }
}
