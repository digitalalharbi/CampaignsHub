<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Services\LiveOperatingView;
use PHPUnit\Framework\TestCase;

/**
 * LIVE-OPERATING-VIEW-001 — the cadence the live view states is the cadence the scheduler runs.
 *
 * The view declares each command's cron because an HTTP request never loads `routes/console.php`.
 * This guard reads that file and refuses a declaration that drifted from the schedule.
 */
final class LiveViewCadenceGuardTest extends TestCase
{
    public function test_every_declared_cadence_matches_routes_console(): void
    {
        $console = (string) file_get_contents(dirname(__DIR__, 2).'/routes/console.php');

        foreach (LiveOperatingView::DECLARED_CADENCE as $command => $expression) {
            $quoted = preg_quote($command, '/');
            self::assertSame(1, preg_match("/Schedule::command\\('{$quoted}'\\)\\s*->\\s*([a-zA-Z]+)\\(([^)]*)\\)/", $console, $m), "$command is not scheduled in routes/console.php");

            $declared = match ($m[1]) {
                'everyThirtyMinutes' => '*/30 * * * *',
                'everyFifteenMinutes' => '*/15 * * * *',
                'hourly' => '0 * * * *',
                'hourlyAt' => trim($m[2], "'\" ").' * * * *',
                'cron' => trim($m[2], "'\" "),
                default => self::fail("$command uses a schedule shape this guard does not read: {$m[1]}"),
            };

            self::assertSame($declared, $expression, "$command: the live view says «{$expression}», the schedule says «{$declared}»");
        }
    }
}
