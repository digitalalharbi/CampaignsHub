<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
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
 * DELIVERY-STATE-001 / DELIVERY-QUIET-001 / DELIVERY-RULE-7D-001 — Owner 2026-11-21: running = spend in the last 7 days; with no
 * spend the reason is the data (stopped coming in) or the silence; a budget that ran out stops the unit first. A project that
 * neither spent nor showed one impression in the window is not watched, reported or alerted on until it moves again.
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

    public function test_a_project_moves_on_one_riyal_or_one_impression_and_runs_on_spend_in_seven_days(): void
    {
        $running = $this->project('Running');
        $shownOnly = $this->project('Shown');
        $quiet = $this->project('Ettifaq');
        $fresh = $this->project('Fresh');                          // nothing on record yet: still being set up, still watched

        $this->metric($running, 'snapchat', 'spend', 1, 120);
        $this->metric($running, 'snapchat', 'impressions', 1, 9_000);
        $this->metric($running, 'tiktok', 'spend', 6, 40);         // six days ago is inside the 7-day window
        $this->metric($shownOnly, 'meta', 'impressions', 0, 1);
        $this->metric($quiet, 'meta', 'spend', 14, 300);          // stopped two weeks ago
        $this->metric($quiet, 'meta', 'impressions', 14, 20_000);
        $this->metric($quiet, 'meta', 'spend', 1, 0);             // a zero row is not movement

        $state = app(DeliveryState::class);
        $ids = [(string) $running->id, (string) $shownOnly->id, (string) $quiet->id, (string) $fresh->id];

        $this->assertSame([(string) $running->id, (string) $shownOnly->id, (string) $fresh->id], $state->watched($ids),
            'one impression is enough to be watched; a project that delivered and went silent for 7 days is not');

        $by = $state->byProject($ids);
        $this->assertArrayNotHasKey((string) $fresh->id, $by);
        $this->assertSame(['snapchat' => true, 'tiktok' => true], $by[(string) $running->id]);
        $this->assertSame(['meta' => false], $by[(string) $shownOnly->id], 'shown without a riyal spent is not running');
        // The platform the quiet project selects is listed as stopped, not left out.
        $this->assertSame(['meta' => false], $by[(string) $quiet->id]);
        $this->assertSame(DeliveryState::NO_SPEND, $state->detail([(string) $quiet->id])[(string) $quiet->id]['meta']['state']);

        $this->assertContains((string) $quiet->id, $state->watched([(string) $quiet->id], Carbon::now()->subDays(20)->toDateString()),
            'a report over its own period still covers a project that spent inside it');
    }

    public function test_with_no_spend_the_reason_is_the_data_when_it_stopped_coming_in(): void
    {
        $p = $this->project('Stale');
        $this->metric($p, 'snapchat', 'spend', 12, 50);           // binds the account (SeededAccountBinder)
        $account = DailyMetric::query()->where('project_id', $p->id)->value('external_account_id');

        $d = fn () => app(DeliveryState::class)->detail([(string) $p->id])[(string) $p->id]['snapchat'];
        $this->assertSame(DeliveryState::NO_SPEND, $d()['state'], 'never tried: nothing says its data stopped');

        ExternalAccount::withoutGlobalScopes()->whereKey($account)->update(['last_synced_at' => now()->subDays(10), 'last_sync_attempt_at' => now()]);
        $this->assertSame(DeliveryState::NO_SYNC, $d()['state'], 'last refreshed before the window: the data stopped coming in');

        ExternalAccount::withoutGlobalScopes()->whereKey($account)->update(['last_synced_at' => now()->subHours(3)]);
        $this->assertSame(DeliveryState::NO_SPEND, $d()['state'], 'refreshed today and nothing spent: it is silent');

        $this->metric($p, 'snapchat', 'spend', 0, 5);
        $this->assertSame(DeliveryState::RUNNING, $d()['state']);
    }

    public function test_the_reason_of_a_unit_puts_the_budget_first(): void
    {
        $this->assertSame(DeliveryState::BUDGET_SPENT, DeliveryState::reason([DeliveryState::RUNNING], 0.0));
        $this->assertSame(DeliveryState::RUNNING, DeliveryState::reason([DeliveryState::NO_SPEND, DeliveryState::RUNNING], 120.0));
        $this->assertSame(DeliveryState::NO_SYNC, DeliveryState::reason([DeliveryState::NO_SYNC, DeliveryState::NO_SYNC], null));
        $this->assertSame(DeliveryState::NO_SPEND, DeliveryState::reason([DeliveryState::NO_SYNC, DeliveryState::NO_SPEND], null));
        $this->assertSame(DeliveryState::NO_SPEND, DeliveryState::reason([], 500.0));
    }

    public function test_running_campaigns_need_spend_in_the_window(): void
    {
        $p = $this->project('P');
        $on = (string) Str::uuid();
        $shownOnly = (string) Str::uuid();
        $old = (string) Str::uuid();
        $this->metric($p, 'snapchat', 'spend', 5, 10, $on);
        $this->metric($p, 'snapchat', 'impressions', 1, 100, $shownOnly);
        $this->metric($p, 'snapchat', 'spend', 9, 10, $old);
        $this->metric($p, 'snapchat', 'impressions', 9, 100, $old);

        $this->assertSame([$on], app(DeliveryState::class)->runningCampaigns([(string) $p->id]));
    }
}
