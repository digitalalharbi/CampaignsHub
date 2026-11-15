<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingNotifier;
use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\SpendLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * ONE-BUDGET-001 — the spend limits become part of the approved budget: linked as its thresholds (project / platform /
 * account / campaign), automatically and idempotently, deleting nothing; the old row is kept with its read-only snapshot.
 */
final class SpendLimitsBecomeBudgetTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding();
    }

    public function test_the_conversion_is_counted_first_then_applied_once_and_deletes_nothing(): void
    {
        // A project with a budget and a platform limit → linked.
        $funded = $this->newProject('Funded', 'One');
        $this->addBudget($funded, 5_000);
        $platform = $this->makeLimit($funded, 1_000, 'USD', scope: SpendLimitScope::Platform, scopeId: 'meta');
        // A project with no budget and an active project limit → becomes its budget, then linked.
        $bare = $this->newProject('Bare', 'Two');
        $project = $this->makeLimit($bare, 1_000, 'USD', thresholds: [60]);
        // A project with no budget and only a campaign limit → left as it is.
        $loose = $this->newProject('Loose', 'Three');
        $campaign = $this->makeLimit($loose, 300, 'SAR', scope: SpendLimitScope::Campaign, scopeId: (string) Str::uuid());

        $limitsBefore = SpendLimit::withoutGlobalScopes()->count();

        $this->artisan('funding:convert-spend-limits')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, SpendLimit::withoutGlobalScopes()->whereNotNull('project_funding_id')->count(), 'a dry run writes nothing');
        $this->assertNull(ProjectFunding::query()->where('project_id', $bare->id)->first());

        $this->artisan('funding:convert-spend-limits --apply')->assertSuccessful();

        $platform->refresh();
        $this->assertSame(ProjectFunding::query()->where('project_id', $funded->id)->value('id'), $platform->project_funding_id);
        $this->assertNotNull($platform->converted_at);
        $this->assertEqualsWithDelta(1_000, (float) $platform->legacy_snapshot['amount'], 0.001);
        $this->assertSame('platform', $platform->legacy_snapshot['scope']);

        $budget = ProjectFunding::query()->where('project_id', $bare->id)->firstOrFail();
        $this->assertSame($budget->id, $project->refresh()->project_funding_id);
        // 1,000 USD at the SAR peg; the limit's own step (60) joins the default steps.
        $this->assertEqualsWithDelta(3_750, (float) DB::table('funding_payments')->where('project_id', $bare->id)->sum('amount_sar'), 0.01);
        $this->assertSame([50, 60, 75, 90, 100], $budget->thresholdPercents());

        $this->assertNull($campaign->refresh()->project_funding_id, 'a campaign limit is never turned into a project budget');

        // Twice is once.
        $entries = DB::table('funding_payments')->count();
        $this->artisan('funding:convert-spend-limits --apply')->assertSuccessful();
        $this->assertSame($entries, DB::table('funding_payments')->count());
        $this->assertSame($limitsBefore, SpendLimit::withoutGlobalScopes()->count(), 'nothing deleted');
        $this->assertSame(1, ProjectFunding::query()->where('project_id', $bare->id)->count());
    }

    public function test_the_limits_page_links_automatically_and_shows_the_budget(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('University', 'Campus');
        $this->spendRow($project, (string) Str::uuid(), 2, 4_404);
        $this->addBudget($project, 5_069, 6);
        $limit = $this->makeLimit($project, 1_000, 'SAR', scope: SpendLimitScope::Platform, scopeId: 'snapchat');

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/projects/'.$project->id.'/spend-limits')->assertOk();

        $this->assertEqualsWithDelta(665, $res->json('meta.budget.remaining'), 0.001);
        $this->assertSame([50, 75, 90, 100], $res->json('meta.budget.thresholds'));
        $row = collect($res->json('data'))->firstWhere('id', $limit->id);
        $this->assertNotNull($row['project_funding_id']);
        $this->assertSame('platform', $row['legacy']['scope']);

        // A new limit is born linked.
        $created = $this->postJson('/api/v1/projects/'.$project->id.'/spend-limits', [
            'scope' => 'project', 'amount' => 4_000, 'currency' => 'SAR', 'starts_on' => $this->day(5), 'ends_on' => $this->day(-5),
        ])->assertCreated()->json('data');
        $this->assertNotNull($created['project_funding_id']);

        // Removing a limit deactivates it; the row stays.
        $this->deleteJson('/api/v1/projects/'.$project->id.'/spend-limits/'.$limit->id)->assertOk();
        $this->assertFalse(SpendLimit::query()->findOrFail($limit->id)->active);
    }

    public function test_the_budget_announces_its_highest_newly_reached_step_once(): void
    {
        $project = $this->newProject('Steps', 'Club');
        // 80% of 1,000, spent before the last week (no runway reading) and no alert floor: not «running low», only a step.
        $this->spendRow($project, (string) Str::uuid(), 10, 800);
        $this->addBudget($project, 1_000, 12);
        $funding = ProjectFunding::query()->where('project_id', $project->id)->firstOrFail();
        $funding->update(['alert_floor' => 0]);

        app(FundingNotifier::class)->evaluate($funding);
        app(FundingNotifier::class)->evaluate($funding);

        $states = DB::table('funding_notices')->where('project_id', $project->id)->pluck('state')->all();
        $this->assertContains('pct:75', $states);
        $this->assertNotContains('pct:50', $states, 'only the highest step reached is announced');
        $this->assertSame(1, count(array_filter($states, static fn (string $s): bool => $s === 'pct:75')));
    }
}
