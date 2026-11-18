<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingBalance;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Metrics\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * PERF-BOOTSTRAP-001 — `FundingBalance::summaries()` (every budget of the agency dashboard in a handful of statements) must say
 * exactly what `FundingBalance::of()` (the full per-budget reading, left untouched) says for every figure the dashboard draws.
 *
 * `of()` stays as the definition. This test is the equality the batched path is held to: prepaid and credit, a stated start and an
 * open one, an ended period, spend in another currency, spend in a currency with no rate, a budget with no spend, a voided one.
 */
final class FundingBalanceSummariesTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    /** Every key `ProjectBudgetReader::summarise()` reads from `of()`, plus the ones a status needs. */
    private const KEYS = ['project_id', 'client_workspace_id', 'payment_method', 'payment_mode', 'period_start', 'period_end', 'approved', 'spent',
        'remaining', 'used_ratio', 'pace_per_day', 'pace_3d', 'run_out_date', 'spend_without_rate', 'complete', 'last_spend_day', 'status', 'fx'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00', 'Asia/Riyadh'));
        $this->bootFunding();
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-01-01', 'source' => 'peg']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> the ids of the budgets built */
    private function world(): array
    {
        $ids = [];

        // 1. Prepaid, stated start, spend in SAR and USD, a week of recent spend (so a pace and a run-out day exist).
        $a = $this->newProject('A', 'Client A');
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $ago) {
            $this->spendRow($a, $this->uuid('acc-a'), $ago, 100);
        }
        $this->spendRow($a, $this->uuid('acc-a2'), 3, 40, 'USD', 'meta');
        $this->addBudget($a, 20_000, 12);
        $ids[] = (string) $a->id;

        // 2. Prepaid, NO stated start: the period begins at the first real spend (or first payment).
        $b = $this->newProject('B', 'Client B');
        $this->spendRow($b, $this->uuid('acc-b'), 20, 50);
        $this->spendRow($b, $this->uuid('acc-b'), 2, 70);
        $this->addBudget($b, 3_000, 5);
        DB::table('project_funding')->where('project_id', $b->id)->update(['starts_on' => null]);
        $ids[] = (string) $b->id;

        // 3. Credit with a period that has ended, and a ceiling.
        $c = $this->newProject('C', 'Client C');
        $this->spendRow($c, $this->uuid('acc-c'), 25, 100);
        $this->spendRow($c, $this->uuid('acc-c'), 10, 200);
        $this->spendRow($c, $this->uuid('acc-c'), 1, 300);
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', [
            'client_workspace_id' => $c->client_workspace_id, 'project_id' => $c->id, 'amount' => 1_000, 'paid_on' => $this->day(0),
            'idempotency_key' => (string) Str::uuid(), 'payment_mode' => 'credit', 'period_start' => $this->day(25), 'period_end' => $this->day(2),
        ])->assertCreated();
        $ids[] = (string) $c->id;

        // 4. Spend in a currency with no rate: counted, named, never read as zero (status `incomplete`).
        $d = $this->newProject('D', 'Client D');
        $this->spendRow($d, $this->uuid('acc-d'), 4, 500);
        $this->spendRow($d, $this->uuid('acc-d2'), 3, 900, 'JPY', 'tiktok');
        $this->addBudget($d, 5_000, 8);
        $ids[] = (string) $d->id;

        // 5. A budget with no spend at all.
        $e = $this->newProject('E', 'Client E');
        $this->addBudget($e, 1_000, 3);
        $ids[] = (string) $e->id;

        // 6. Overspent prepaid.
        $f = $this->newProject('F', 'Client F');
        $this->spendRow($f, $this->uuid('acc-f'), 3, 1_500);
        $this->addBudget($f, 1_000, 6);
        $ids[] = (string) $f->id;

        return $ids;
    }

    /** @param array<string, mixed> $row */
    private function pick(array $row): array
    {
        return array_intersect_key($row, array_flip(self::KEYS));
    }

    public function test_summaries_equal_the_full_reading_for_every_budget(): void
    {
        $ids = $this->world();
        $balance = app(FundingBalance::class);
        $fundings = ProjectFunding::query()->whereIn('project_id', $ids)->get();
        $this->assertCount(6, $fundings);

        $batched = $balance->summaries($fundings);

        foreach ($fundings as $funding) {
            $full = $balance->of($funding);
            $this->assertEquals(
                $this->normalise($this->pick($full)),
                $this->normalise($this->pick($batched[(string) $funding->project_id])),
                'budget of '.$funding->project_id.' (status '.$full['status'].')',
            );
        }

        // The fixture really does cover the interesting statuses, so the equality above is not vacuous.
        $statuses = array_unique(array_map(static fn (array $r): string => (string) $r['status'], $batched));
        $this->assertContains('incomplete', $statuses);
        $this->assertContains('over', $statuses);
        $this->assertContains('credit', $statuses);
        $this->assertContains('ok', $statuses);
    }

    public function test_a_voided_budget_summarises_like_the_full_reading(): void
    {
        $ids = $this->world();
        DB::table('project_funding')->where('project_id', $ids[0])->update(['voided_at' => now(), 'void_reason' => 'test']);
        $balance = app(FundingBalance::class);
        $funding = ProjectFunding::query()->where('project_id', $ids[0])->firstOrFail();

        $this->assertSame('void', $balance->summaries([$funding])[$ids[0]]['status']);
        $this->assertEquals($this->normalise($this->pick($balance->of($funding))), $this->normalise($this->pick($balance->summaries([$funding])[$ids[0]])));
    }

    public function test_the_reader_answers_what_it_answered_before_it_was_batched(): void
    {
        $ids = $this->world();
        $reader = app(ProjectBudgetReader::class);
        $balance = app(FundingBalance::class);

        $deposits = DB::table('funding_payments')->whereIn('project_id', $ids)->whereIn('kind', ['initial', 'top_up'])
            ->selectRaw('project_id, SUM(amount_sar) AS d')->groupBy('project_id')->pluck('d', 'project_id');
        $now = $reader->forProjects($ids);

        foreach (ProjectFunding::query()->whereIn('project_id', $ids)->whereNull('voided_at')->get() as $funding) {
            $id = (string) $funding->project_id;
            $before = $reader->summarise($funding, $balance->of($funding), (float) ($deposits[$id] ?? 0));
            $this->assertEquals($this->normalise($before), $this->normalise($now[$id]), 'summary of '.$id);
        }
    }

    /** JSON round-trip, so a float 0.0 and an int 0 (the same figure on the wire) do not read as different. */
    private function normalise(array $row): array
    {
        return json_decode((string) json_encode($row), true);
    }
}
