<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\LiveReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — what a public live report asks the database, counted.
 *
 * Production's statement table for one link showed the same statement run eighty times with different
 * bindings (one per campaign) and the same expensive statement run twice with the SAME bindings. Both
 * are caught here without a clock: the number of statements must not grow with the number of campaigns
 * under the link, and no statement may run twice with identical bindings inside one build.
 *
 * The ceilings below were measured on the world in {@see BuildsLiveReportWorld} after the work, and the
 * same tests were run against the code before it (see the requirement's matrix row for the numbers): the
 * old build asked 338 questions of 36 campaigns and 399 of 72, with the reach aggregate 68 times and the
 * delivery list 72 times.
 */
final class LiveReportQueryBudgetTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @return array{count: int, db_ms: float, statements: list<array{sql: string, bindings: array<int, mixed>, at: string}>} */
    private function measure(ReportShare $share): array
    {
        $statements = [];
        $ms = 0.0;
        DB::listen(function ($q) use (&$statements, &$ms): void {
            $at = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
                if (isset($frame['file']) && str_contains($frame['file'], '/app/') && count($at) < 4) {
                    $at[] = basename($frame['file'], '.php').':'.($frame['line'] ?? 0);
                }
            }
            $statements[] = ['sql' => (string) preg_replace('/\s+/', ' ', $q->sql), 'bindings' => $q->bindings, 'at' => implode(' < ', $at)];
            $ms += $q->time;
        });

        app(LiveReportService::class)->build($share, [], 'SAR');

        return ['count' => count($statements), 'db_ms' => $ms, 'statements' => $statements];
    }

    /** @param list<array{sql: string, bindings: array<int, mixed>, at: string}> $statements */
    private static function matching(array $statements, string $needle): int
    {
        return count(array_filter($statements, static fn (array $s): bool => str_contains($s['sql'], $needle)));
    }

    public function test_the_statement_count_does_not_grow_with_the_number_of_campaigns(): void
    {
        $this->buildWorld(campaignsPerAccount: 4);
        [$share] = $this->liveLink();

        // The first build also pays for things a process learns once (the container, the schema); the comparison is between warm builds.
        $this->measure($share);
        $small = $this->measure($share);
        $this->growWorld(extra: 8, alreadyHave: 4);
        $large = $this->measure($share);

        fwrite(STDERR, sprintf("\nBUDGET 24 campaigns: %d statements %.0f ms; 72 campaigns: %d statements %.0f ms\n", $small['count'], $small['db_ms'], $large['count'], $large['db_ms']));

        $this->assertLessThanOrEqual(
            $small['count'],
            $large['count'],
            'The build asked more of the database for 72 campaigns than for 24: a statement runs once per campaign (or per creative) somewhere.',
        );
    }

    public function test_the_per_campaign_statements_named_by_production_run_a_bounded_number_of_times(): void
    {
        $this->buildWorld(campaignsPerAccount: 6);
        [$share] = $this->liveLink();

        $run = $this->measure($share)['statements'];

        // Production fingerprint 2fafebb3: the reach-truth aggregate, once per campaign (80 runs).
        $reach = self::matching($run, 'reach_grains');
        // Production fingerprint b263e40b: the (account, campaign) delivery list, once per campaign (62 runs).
        $delivery = self::matching($run, 'select distinct "daily_metrics"."external_account_id", "daily_metrics"."external_campaign_id" from "daily_metrics"');
        // The account ceiling's campaign list: two runs of 668 ms each on Production.
        $ceiling = self::matching($run, 'select distinct "unified_campaign_id" from "daily_metrics" where');

        $this->assertLessThanOrEqual(9, $reach, "the reach-truth aggregate runs {$reach} times: once per campaign again");
        $this->assertLessThanOrEqual(3, $delivery, "the delivery list is read {$delivery} times: once per campaign or account again");
        $this->assertLessThanOrEqual(1, $ceiling, "the account ceiling resolved its campaign list {$ceiling} times");
        // AUDIENCE-001 — three constant reads (the bound ad accounts, the ad sets' targeting, the paid-delivery rows), never per campaign.
        $this->assertLessThanOrEqual(1, self::matching($run, 'from "audience_breakdown_rows"'), 'the audience delivery rows are read once per build');
        // 126: OBJECTIVE-PATHS-001 adds ONE statement per build (the ad-set read behind the objective split), not one per campaign.
        // Measured with AUDIENCE-001 merged: still 126 — this build adds no audience statement, so the budget is main's, not raised.
        $this->assertLessThanOrEqual(126, count($run), 'a build over 36 campaigns asked '.count($run).' questions (126 allowed; the build before this work asked 338)');
    }

    public function test_no_statement_runs_twice_with_the_same_bindings_inside_one_build(): void
    {
        $this->buildWorld(campaignsPerAccount: 4);
        [$share] = $this->liveLink();

        $seen = [];
        foreach ($this->measure($share)['statements'] as $s) {
            $seen[md5($s['sql'].json_encode($s['bindings']))][] = $s;
        }

        // The one repeat left on purpose: `ReportingTimezone` memoises the client's project row on its own and loads it with a relation
        // the report does not need, so the two reads are the same primary-key lookup from two owners. A lookup by primary key, ~0.3 ms.
        $repeated = array_filter(
            $seen,
            static fn (array $runs): bool => count($runs) > 1 && ! str_starts_with($runs[0]['sql'], 'select * from "projects" where "projects"."id" = ? limit 1'),
        );

        $this->assertSame([], array_values(array_map(
            static fn (array $runs): string => count($runs).' x '.substr($runs[0]['sql'], 0, 100).' at '.implode(' || ', array_column($runs, 'at')),
            $repeated,
        )));
    }
}
