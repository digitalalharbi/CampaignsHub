<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — every scheduled command says how much it did.
 *
 * ## The claim that was not true
 *
 * `ScheduledRunRows` was built with the note «nothing is required of a command that has no
 * meaningful count, which is most of them». It was wired into ONE command out of fifteen, and the
 * sentence was what made that look finished.
 *
 * It is not most of them. Every scheduled command in this product already computed a count and
 * already printed it — «SLA evaluated — 3 warning(s), 1 breach(es)», «Dispatched 12 scheduled
 * report(s)», «Refreshed 40 token(s); 2 need re-authorisation», «Closed 7 abandoned run(s)» — to a
 * terminal nobody is watching at 04:00, while `scheduled_runs.rows_affected` stayed null. The ops
 * page could say a run SUCCEEDED and not whether it had done anything.
 *
 * That is the shape this codebase keeps meeting: a correct answer computed somewhere nothing reads.
 *
 * ## What the sweep asserts, and what it cannot
 *
 * It reads the source of every command the SCHEDULER runs and requires a call to the reporter, or an
 * entry below saying why there is nothing to count. It cannot check that the number is the RIGHT
 * one — that is what each call site's note argues, one by one. What it stops is the silent case: a
 * command added to the schedule next month whose run is recorded with a null count and nobody
 * noticing, which is exactly how this got to one-in-fifteen.
 */
final class ScheduledRunRowsCoverageTest extends TestCase
{
    /**
     * Commands the scheduler runs that genuinely have nothing to count.
     *
     * An entry is a CLAIM and is checked: `test_every_exemption_still_names_a_scheduled_command`
     * fails on one that names nothing, so a command renamed out from under this list cannot leave a
     * dead sentence behind — the same rule `ProjectRouteCapabilityCoverageTest` applies to its own.
     *
     * @var array<string, string>
     */
    private const NOTHING_TO_COUNT = [];

    /** @return list<string> the command names the scheduler is configured to run */
    private function scheduled(): array
    {
        $names = [];

        foreach (app(Schedule::class)->events() as $event) {
            /*
             * The command line, reduced to its artisan name. A scheduled event's `command` is the
             * whole invocation — the PHP binary, the artisan path, the name and its options — so the
             * name is the first argument that is not a path or a flag.
             */
            $parts = preg_split('/\s+/', (string) $event->command) ?: [];

            foreach ($parts as $part) {
                $part = trim($part, "'\"");

                if ($part === '' || str_starts_with($part, '-') || str_contains($part, '/') || str_ends_with($part, 'php')) {
                    continue;
                }

                if (str_contains($part, ':')) {
                    $names[] = $part;
                    break;
                }
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /** @return array<string, string> command name => source file */
    private function commandSources(): array
    {
        $out = [];
        $root = base_path('app');

        if (! is_dir($root)) {
            return $out;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/\$signature\s*=\s*[\'"]([a-z0-9:_-]+)/i', $source, $m) === 1) {
                $out[$m[1]] = $source;
            }
        }

        return $out;
    }

    #[Test]
    public function the_sweep_finds_the_schedule(): void
    {
        /*
         * A sweep that finds no commands proves nothing by finding no offenders. Fourteen is what the
         * schedule holds today; the floor is deliberately below it so adding one is free.
         */
        $this->assertGreaterThanOrEqual(10, count($this->scheduled()), 'the schedule sweep found almost nothing: '.implode(', ', $this->scheduled()));
    }

    #[Test]
    public function every_scheduled_command_reports_how_many_rows_it_touched(): void
    {
        $sources = $this->commandSources();
        $offenders = [];
        $missing = [];

        foreach ($this->scheduled() as $name) {
            if (array_key_exists($name, self::NOTHING_TO_COUNT)) {
                continue;
            }

            if (! array_key_exists($name, $sources)) {
                $missing[] = $name;

                continue;
            }

            if (! str_contains($sources[$name], 'ScheduledRunRows')) {
                $offenders[] = $name;
            }
        }

        sort($offenders);
        sort($missing);

        $this->assertSame([], $missing, 'a scheduled command has no findable source: '.implode(', ', $missing));

        $this->assertSame(
            [],
            $offenders,
            "These scheduled commands record a run with a null `rows_affected`, so the ops page can say\n"
            ."they SUCCEEDED and not whether they did anything. Call\n"
            ."`app(ScheduledRunRows::class)->report(\$n)` with the count the command already computes,\n"
            ."or name it in NOTHING_TO_COUNT with the reason:\n  "
            .implode("\n  ", $offenders),
        );
    }

    #[Test]
    public function every_exemption_still_names_a_scheduled_command(): void
    {
        $scheduled = $this->scheduled();
        $dead = array_values(array_filter(
            array_keys(self::NOTHING_TO_COUNT),
            static fn (string $name): bool => ! in_array($name, $scheduled, true),
        ));

        $this->assertSame([], $dead, 'an exemption names a command the scheduler does not run: '.implode(', ', $dead));
    }
}
