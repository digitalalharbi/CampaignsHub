<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/** Nov overhaul, task 6 — a budget with nothing spent is «ضمن الميزانية», whatever its size against the alert floor. */
final class NovBudgetZeroSpendTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding();
    }

    public function test_zero_spend_is_on_track_even_when_the_budget_is_under_the_alert_floor(): void
    {
        $project = $this->newProject();
        $this->addBudget($project, 1_000); // under the default 2,000 floor

        $f = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data');

        $this->assertSame(0.0, (float) $f['spent']);
        $this->assertSame('ok', $f['status']);
    }

    public function test_real_spend_under_the_floor_is_still_running_low(): void
    {
        $project = $this->newProject();
        $this->spendRow($project, (string) Str::uuid(), 2, 100);
        $this->addBudget($project, 1_000);

        $f = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data');

        $this->assertSame('low', $f['status']);
    }
}
