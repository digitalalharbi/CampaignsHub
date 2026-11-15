<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Services\ClientPortfolioStats;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ScheduledReportDispatcher;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LifecycleFixtures;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 §2 / §10 — archived and deleted projects never appear in an aggregation, alert routing, email,
 * digest, picker or sync; an «onboarding» client's active spending project shows its spend. Agency, direct-business and
 * client-portal fixtures.
 */
final class LiveProjectsScopeAppliedTest extends TestCase
{
    use LifecycleFixtures;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function workspaceTypes(): array
    {
        return ['agency' => ['agency'], 'direct business' => ['business'], 'client portal' => ['portal']];
    }

    /**
     * @return array{ws: array<string,mixed>, live: Project, paused: Project, archived: Project, deleted: Project, accounts: array<string, ExternalAccount>}
     */
    private function world(string $type): array
    {
        $ws = $this->lifecycleWorkspace($type);
        $live = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Live');
        $paused = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Paused', 'paused');
        $archived = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Archived');
        $deleted = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Deleted');

        $accounts = [];
        foreach (['live' => $live, 'paused' => $paused, 'archived' => $archived, 'deleted' => $deleted] as $key => $project) {
            $accounts[$key] = $this->lifecycleAccount($ws['tenant'], 'snapchat', 'USD');
            $this->lifecycleBind($accounts[$key], $project);
            $this->spend($project, $accounts[$key], 100.0);
            $this->campaign($project);
        }

        // An archive that predates the effects (status only — the operational rows stay «on»): the scope alone must exclude it.
        app(ProjectLifecycle::class)->transition($archived, ProjectStatus::Archived, $ws['owner']);
        app(ProjectLifecycle::class)->transition($deleted, ProjectStatus::Deleted, $ws['owner']);

        return compact('ws', 'live', 'paused', 'archived', 'deleted', 'accounts');
    }

    #[DataProvider('workspaceTypes')]
    public function test_aggregation_counts_live_projects_only_and_ignores_client_state(string $type): void
    {
        $w = $this->world($type);
        app(TenantContext::class)->setTenantId((string) $w['ws']['tenant']->id);

        $stats = app(ClientPortfolioStats::class)->forClients([(string) $w['ws']['client']->id]);
        $row = $stats[(string) $w['ws']['client']->id];

        // live (100) + paused (100); the archived and deleted projects' 100 each are not there.
        $this->assertEqualsWithDelta(200.0, (float) $row['spend'], 0.01, 'spend folds live projects only — the client is «'.$w['ws']['client']->client_status.'»');
        $this->assertSame(2, (int) $row['projects']);
    }

    public function test_an_onboarding_client_with_an_active_spending_project_shows_its_spend(): void
    {
        $ws = $this->lifecycleWorkspace('agency'); // client_status = onboarding
        $this->assertSame('onboarding', $ws['client']->client_status);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Spending');
        $account = $this->lifecycleAccount($ws['tenant']);
        $this->lifecycleBind($account, $project);
        $this->spend($project, $account, 321.5);

        app(TenantContext::class)->setTenantId((string) $ws['tenant']->id);
        $row = app(ClientPortfolioStats::class)->forClients([(string) $ws['client']->id])[(string) $ws['client']->id];
        $this->assertEqualsWithDelta(321.5, (float) $row['spend'], 0.01);

        $this->actingAs($ws['owner'], 'sanctum')->getJson('/api/v1/agency/dashboard')
            ->assertOk()->assertJsonPath('data.projects.total', 1);
    }

    public function test_agency_dashboard_counts_campaigns_of_live_projects_only(): void
    {
        $w = $this->world('agency');

        $this->actingAs($w['ws']['owner'], 'sanctum')->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 2)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.campaigns.total', 2);
    }

    #[DataProvider('workspaceTypes')]
    public function test_sync_runs_on_running_projects_only_and_a_shared_account_keeps_syncing(string $type): void
    {
        $w = $this->world($type);
        $assign = app(AccountAssignment::class);

        $this->assertTrue($assign->isActivelyAssigned($w['accounts']['live']->fresh()));
        $this->assertFalse($assign->isActivelyAssigned($w['accounts']['paused']->fresh()), 'a paused project does not sync (unchanged)');
        $this->assertFalse($assign->isActivelyAssigned($w['accounts']['archived']->fresh()));
        $this->assertFalse($assign->isActivelyAssigned($w['accounts']['deleted']->fresh()));

        $assigned = $assign->scopeToAssigned(ExternalAccount::withoutGlobalScopes()->where('tenant_id', $w['ws']['tenant']->id))->pluck('id')->map(fn ($i) => (string) $i)->all();
        $this->assertSame([(string) $w['accounts']['live']->id], $assigned);

        // The archived project's account is ALSO linked to the live one («مشترك»): it syncs for the live project.
        $this->lifecycleBind($w['accounts']['archived'], $w['live']);
        $this->assertTrue($assign->isActivelyAssigned($w['accounts']['archived']->fresh()));
    }

    #[DataProvider('workspaceTypes')]
    public function test_digests_alert_mail_and_notification_audiences_never_reach_an_archived_project(string $type): void
    {
        $w = $this->world($type);
        app(TenantContext::class)->setTenantId((string) $w['ws']['tenant']->id); // a scheduler sets the tenant per run
        $ids = app(DigestScope::class)->projectIdsFor($w['ws']['owner'], (string) $w['ws']['tenant']->id);

        sort($ids);
        $expected = [(string) $w['live']->id, (string) $w['paused']->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains((string) $w['archived']->id, app(DigestScope::class)->visibleProjectIds($w['ws']['owner'], (string) $w['ws']['tenant']->id));
    }

    #[DataProvider('workspaceTypes')]
    public function test_pickers_list_archived_only_on_request_and_never_deleted(string $type): void
    {
        $w = $this->world($type);
        $draft = $this->lifecycleProject($w['ws']['tenant'], $w['ws']['client'], 'Draft', 'draft');

        $names = fn (string $q) => collect($this->actingAs($w['ws']['owner'], 'sanctum')->getJson('/api/v1/projects'.$q)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Draft', 'Live', 'Paused'], $names(''));
        $this->assertSame(['Archived', 'Draft', 'Live', 'Paused'], $names('?include_archived=1'));
        $this->assertSame(['Archived'], $names('?status=archived'));
        $this->assertNotNull($draft->id);
    }

    public function test_scheduled_reports_and_automation_skip_archived_projects_even_if_left_on(): void
    {
        Queue::fake();
        $w = $this->world('agency');
        $live = $this->lifecycleOperational($w['live']);
        $archived = $this->lifecycleOperational($w['archived']);
        DB::table('report_schedules')->update(['next_run_at' => Carbon::now()->subMinute()]);

        app(ScheduledReportDispatcher::class)->dispatchDue();

        $this->assertNotNull(DB::table('report_schedules')->where('id', $live['schedule'])->value('last_run_at') ?? DB::table('report_schedules')->where('id', $live['schedule'])->value('next_run_at'));
        $this->assertSame(
            Carbon::parse((string) DB::table('report_schedules')->where('id', $archived['schedule'])->value('next_run_at'))->lessThan(Carbon::now()),
            true,
            'the archived project\'s schedule was not picked up (its run was not moved on)'
        );

        $this->artisan('automation:evaluate')->assertSuccessful();
        $this->assertNull(DB::table('automation_rules')->where('id', $archived['automation'])->value('next_run_at'), 'the archived project\'s rule was not evaluated');
        $this->assertNotNull(DB::table('automation_rules')->where('id', $live['automation'])->value('next_run_at'));
    }

    public function test_the_live_view_matches_the_scope(): void
    {
        $w = $this->world('agency');
        $view = DB::table(LiveProjects::VIEW)->where('tenant_id', $w['ws']['tenant']->id)->pluck('id')->map(fn ($i) => (string) $i)->sort()->values()->all();
        $this->assertSame(collect(LiveProjects::ids((string) $w['ws']['tenant']->id))->sort()->values()->all(), $view);
    }

    private function spend(Project $project, ExternalAccount $account, float $value): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => (string) Str::uuid(), 'provider' => $account->provider,
            'metric_key' => 'spend', 'metric_date' => Carbon::yesterday()->toDateString(), 'value' => $value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function campaign(Project $project): void
    {
        DB::table('unified_campaigns')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'project_id' => $project->id,
            'client_workspace_id' => $project->client_workspace_id, 'name' => 'C '.$project->name, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
