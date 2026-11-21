<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * PERF-BOOTSTRAP-001 — GET /api/v1/agency/client-budgets costs a bounded number of statements however many clients and projects
 * the agency has. Each project's approved budget was read with a dozen statements of its own (120 projects = 1,440 statements,
 * measured on a seeded dashboard); it is now read once for all of them.
 */
final class ClientBudgetsQueryBudgetTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding();
        $this->reader = $this->owner();
    }

    /** @return array{0: int, 1: list<array<string, mixed>>} statements issued by the request, and its rows */
    private function read(): array
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });
        $rows = $this->actingAs($this->reader, 'sanctum')->getJson('/api/v1/agency/client-budgets')->assertOk()->json('data');

        return [$count, $rows];
    }

    private function addClients(int $n, int $from = 0): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            $project = $this->newProject('P'.$i, 'Client '.$i);
            $account = $this->uuid('acc-'.$i);
            foreach ([1, 2, 3] as $ago) {
                $this->spendRow($project, $account, $ago, 100 + $i);
            }
            $this->addBudget($project, 10_000 + $i, 6);
        }
    }

    public function test_the_statement_count_does_not_grow_with_the_number_of_clients(): void
    {
        $this->addClients(3);
        [$small, $smallRows] = $this->read();

        $this->addClients(9, 3);
        [$large, $largeRows] = $this->read();

        $this->assertCount(3, $smallRows);
        $this->assertCount(12, $largeRows);
        // Four times the clients may not cost four times the statements: only the owner's own permission lookups
        // (done per request, not per client) and a constant number of batched reads are allowed to differ.
        $this->assertLessThanOrEqual($small + 5, $large, "3 clients: {$small} statements, 12 clients: {$large}");
        $this->assertLessThan(80, $large, 'an absolute ceiling, so a regression to per-client reads cannot hide behind a high baseline');
    }

    public function test_the_figures_are_those_of_the_budgets_page(): void
    {
        $this->addClients(2);
        [, $rows] = $this->read();
        $byName = collect($rows)->keyBy('client_name');

        // Client 1: approved 10,001 SAR, three days of 101 SAR spent -> 303 spent, 9,698 left.
        $budget = $byName['Client 1']['project_budget'];
        $this->assertEqualsWithDelta(10_001, $budget['approved'], 0.001);
        $this->assertEqualsWithDelta(303, $budget['spent'], 0.001);
        $this->assertEqualsWithDelta(9_698, $budget['remaining'], 0.001);

        $page = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/v1/funding/projects/'.$byName['Client 1']['project_budgets'][0]['project_id'])->assertOk()->json('data');
        $this->assertEqualsWithDelta($page['remaining'], $budget['remaining'], 0.001);
        $this->assertEqualsWithDelta($page['spent'], $budget['spent'], 0.001);
    }
}
