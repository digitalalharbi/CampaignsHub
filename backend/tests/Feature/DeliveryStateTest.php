<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\DeliveryState;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DELIVERY-STATE-001 / DELIVERY-QUIET-001 — Owner 2026-11-20: running = spend AND impressions in the last 3 days; a project
 * that neither spent nor showed one impression is not watched, reported or alerted on until it moves again.
 */
final class DeliveryStateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
    }

    private function project(string $name): Project
    {
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active']);
    }

    private function metric(Project $p, string $provider, string $key, int $ago, float $value, ?string $campaign = null): void
    {
        DailyMetric::query()->create(['tenant_id' => $this->tenant->id, 'project_id' => $p->id, 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign, 'provider' => $provider, 'metric_key' => $key,
            'metric_date' => Carbon::now()->subDays($ago)->toDateString(), 'attribution_window' => 'default', 'value' => $value]);
    }

    public function test_a_project_moves_on_one_riyal_or_one_impression_and_runs_only_on_both(): void
    {
        $running = $this->project('Running');
        $shownOnly = $this->project('Shown');
        $quiet = $this->project('Ettifaq');
        $fresh = $this->project('Fresh');                          // nothing on record yet: still being set up, still watched

        $this->metric($running, 'snapchat', 'spend', 1, 120);
        $this->metric($running, 'snapchat', 'impressions', 1, 9_000);
        $this->metric($running, 'tiktok', 'spend', 1, 40);
        $this->metric($shownOnly, 'meta', 'impressions', 0, 1);
        $this->metric($quiet, 'meta', 'spend', 14, 300);          // stopped two weeks ago
        $this->metric($quiet, 'meta', 'impressions', 14, 20_000);
        $this->metric($quiet, 'meta', 'spend', 1, 0);             // a zero row is not movement

        $state = app(DeliveryState::class);
        $ids = [(string) $running->id, (string) $shownOnly->id, (string) $quiet->id, (string) $fresh->id];

        $this->assertSame([(string) $running->id, (string) $shownOnly->id, (string) $fresh->id], $state->watched($ids),
            'one impression is enough to be watched; a project that delivered and went silent for 3 days is not');

        $by = $state->byProject($ids);
        $this->assertArrayNotHasKey((string) $fresh->id, $by);
        $this->assertSame(['snapchat' => true, 'tiktok' => false], $by[(string) $running->id]);
        $this->assertSame(['meta' => false], $by[(string) $shownOnly->id]);
        $this->assertArrayNotHasKey((string) $quiet->id, $by);

        $this->assertContains((string) $quiet->id, $state->watched([(string) $quiet->id], Carbon::now()->subDays(20)->toDateString()),
            'a report over its own period still covers a project that spent inside it');
    }

    public function test_running_campaigns_need_spend_and_impressions_in_the_window(): void
    {
        $p = $this->project('P');
        $on = (string) Str::uuid();
        $spendOnly = (string) Str::uuid();
        $old = (string) Str::uuid();
        $this->metric($p, 'snapchat', 'spend', 2, 10, $on);
        $this->metric($p, 'snapchat', 'impressions', 2, 100, $on);
        $this->metric($p, 'snapchat', 'spend', 1, 10, $spendOnly);
        $this->metric($p, 'snapchat', 'spend', 9, 10, $old);
        $this->metric($p, 'snapchat', 'impressions', 9, 100, $old);

        $this->assertSame([$on], app(DeliveryState::class)->runningCampaigns([(string) $p->id]));
    }
}
