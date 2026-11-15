<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Models\SpendLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/** Nov overhaul, task 6 — «جدّد كل الميزانيات للشهر القادم»: a preview first, nothing created until the amounts are confirmed. */
final class NovRenewNextMonthTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding();
    }

    private function preview(): array
    {
        return (array) $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/renew-next-month')->assertOk()->json('data');
    }

    public function test_the_preview_lists_budgets_and_limits_with_next_months_dates_and_creates_nothing(): void
    {
        $project = $this->newProject('Palm');
        $this->addBudget($project, 5_000);
        $this->makeLimit($project, 3_000, 'SAR', fromAgo: 5, toAhead: 0, thresholds: [50]);
        $before = [SpendLimit::query()->count(), DB::table('funding_payments')->count()];

        $p = $this->preview();

        $next = Carbon::today('Asia/Riyadh')->startOfMonth()->addMonth();
        $this->assertSame($next->format('Y-m'), $p['month']);
        $this->assertSame($next->toDateString(), $p['from']);
        $this->assertSame($next->copy()->endOfMonth()->toDateString(), $p['to']);
        $this->assertSame(5_000.0, (float) $p['budgets'][0]['amount']);
        $this->assertSame(3_000.0, (float) $p['limits'][0]['amount']);
        $this->assertSame($next->toDateString(), $p['limits'][0]['starts_on']);
        $this->assertSame($before, [SpendLimit::query()->count(), DB::table('funding_payments')->count()], 'a preview never writes');
    }

    public function test_nothing_is_created_without_confirmation(): void
    {
        $project = $this->newProject('Palm');
        $this->addBudget($project, 5_000);
        $p = $this->preview();

        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/renew-next-month', [
            'budgets' => [['project_id' => $p['budgets'][0]['project_id'], 'amount' => 5_000]], 'limits' => [],
        ])->assertStatus(422);
        $this->assertSame(1, DB::table('funding_payments')->count());
    }

    public function test_confirmed_items_are_created_with_the_confirmed_amounts_and_once(): void
    {
        $project = $this->newProject('Palm');
        $this->addBudget($project, 5_000);
        $limit = $this->makeLimit($project, 3_000, 'SAR', fromAgo: 5, toAhead: 0, thresholds: [50]);
        $p = $this->preview();
        $body = ['confirmed' => true,
            'budgets' => [['project_id' => $p['budgets'][0]['project_id'], 'amount' => 6_000]],
            'limits' => [['id' => $p['limits'][0]['id'], 'amount' => 3_500]]];

        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/renew-next-month', $body)->assertCreated()
            ->assertJsonPath('data.budgets', 1)->assertJsonPath('data.limits', 1);
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/renew-next-month', $body)->assertCreated()
            ->assertJsonPath('data.budgets', 0)->assertJsonPath('data.limits', 0)->assertJsonPath('data.skipped', 2);

        $this->assertSame(2, DB::table('funding_payments')->count());
        $copy = SpendLimit::query()->where('id', '!=', $limit->id)->firstOrFail();
        $this->assertSame(3_500.0, $copy->amount);
        $this->assertSame($p['from'], $copy->starts_on->toDateString());
        $this->assertSame([50, 100], $copy->thresholdPercents());
        $this->assertSame(3_000.0, $limit->fresh()->amount, 'the old limit is untouched');
    }

    public function test_an_item_that_was_not_in_the_preview_is_refused_and_a_viewer_cannot_renew(): void
    {
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/renew-next-month', [
            'confirmed' => true, 'budgets' => [['project_id' => (string) Str::uuid(), 'amount' => 10]], 'limits' => [],
        ])->assertStatus(422);

        $viewer = $this->userWith(['funding.view']);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/funding/renew-next-month')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/funding/renew-next-month', ['confirmed' => true, 'budgets' => [], 'limits' => []])->assertForbidden();
    }
}
