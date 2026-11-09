<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportTemplateEngine;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAnalyticsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * E2E-TABULAR-PROJECT-001 — which objective a seeded project reports under is a property of the
 * project, never of the hour, and the e2e gate has to ask for the project it means.
 *
 * `report-tabular-download.spec.ts` failed intermittently with the platform table's columns
 * `spend, ctr, share` where `spend, revenue, roas, share` were expected, and the first reading was a
 * clock one: both failures landed after 21:00 UTC (past midnight in Riyadh), so the seed's «today»
 * and the report's «today» must disagree between the two zones.
 *
 * They do not. Every date in the seed and every window in the report is the UTC calendar date of
 * the process that builds it, and the figures are keyed by `metric_date`, a date column — there is
 * no instant anywhere in the chain for a timezone to move. What actually differed between a failing
 * run and a passing one on the same clock was WHICH PROJECT the builder walked into: the demo world
 * holds two projects that both offer ad accounts, one all-sales and one (Q3 Launch) spread across
 * awareness, traffic and sales, and a mixed project is measured on operational figures only, so its
 * client file has no revenue and no ROAS by design.
 *
 * This pins both halves, at the hour the flake was blamed on and at midday:
 *   - the all-sales demo store reads as a sales report, so its platform table carries revenue/ROAS;
 *   - the mixed project reads as mixed, so its table carries CTR instead — which is correct, and is
 *     why a spec that wants sales columns must choose the store project instead of «the first one».
 */
final class DemoReportObjectiveByProjectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function hours(): array
    {
        return [
            'past midnight in Riyadh (the failing hour)' => ['2026-11-08 22:30:00 UTC'],
            'midday UTC' => ['2026-11-09 12:00:00 UTC'],
        ];
    }

    #[DataProvider('hours')]
    public function test_the_objective_of_a_demo_project_does_not_depend_on_the_hour(string $now): void
    {
        Carbon::setTestNow($now);

        // Seeded AS A LOCAL INSTALL, as `DemoClientPortalTest` does: the demo chain is gated on it.
        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
        $this->assertingAcrossTenants();

        // The window the builder defaults to (ReportsPage: today − 29 … today, UTC dates).
        $to = Carbon::today();
        $from = Carbon::today()->subDays(29);

        $store = Project::withoutGlobalScopes()->where('name', DemoAnalyticsSeeder::STORE_PROJECT)->firstOrFail();
        $mixed = Project::withoutGlobalScopes()->where('name', 'Q3 Launch — Demo')
            ->whereHas('clientWorkspace', fn ($q) => $q->withoutGlobalScopes()->where('name', 'like', 'Acme%'))
            ->firstOrFail();

        $objectiveOf = function (Project $p) use ($from, $to): string {
            $campaigns = app(MetricsAggregator::class)->forProjects([(string) $p->getKey()])->byCampaign($from, $to);

            return ReportObjectiveLens::infer($campaigns)->value();
        };

        $this->assertSame('sales', $objectiveOf($store), 'the demo store is the all-sales project');
        $this->assertSame('custom', $objectiveOf($mixed), 'Q3 Launch spans awareness, traffic and sales');

        $engine = app(ReportTemplateEngine::class);
        $this->assertContains('revenue', $engine->metricSet('sales', null));
        $this->assertContains('roas', $engine->metricSet('sales', null));
        $this->assertNotContains('revenue', $engine->metricSet('custom', null));
        $this->assertContains('ctr', $engine->metricSet('custom', null));
    }
}
