<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\PeriodResolver;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ROLE-DASHBOARD-001 — GET /api/v1/me/dashboard: one landing page per role, filtered on the server.
 * Fixture names only; no real client or figure.
 */
final class RoleDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $alpha;

    private ClientWorkspace $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Fixture Agency', 'slug' => 'fx-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->alpha = $this->client('Alpha');
        $this->beta = $this->client('Beta');
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    /** @param list<string>|null $keys null = every key */
    private function member(string $slug, ?array $keys, array $clientIds = []): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $slug, 'slug' => $slug, 'is_system' => true]);
        $role->givePermissionTo(...($keys ?? Permission::pluck('key')->all()));
        $user = User::create(['name' => $slug, 'email' => $slug.'@fixture.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant, Portal::Agency, 'member', $clientIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /** A project with one campaign: budget 1000, `$spent` spread as $daily on each of the last three complete days plus the rest earlier. */
    private function campaign(ClientWorkspace $client, float $budget, float $earlier, float $daily, ?User $owner = null, string $objective = 'sales'): UnifiedCampaign
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P-'.$client->name, 'status' => 'active']);
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'C-'.$client->name.'-'.uniqid(), 'objective' => $objective,
            'status' => 'active', 'total_budget' => $budget, 'budget_currency' => 'SAR', 'owner_id' => $owner?->getKey(),
        ]);
        $this->spend($project, $campaign, 12, $earlier);
        foreach ([1, 2, 3] as $d) {
            $this->spend($project, $campaign, $d, $daily);
        }
        // ONE-BUDGET-001 — the dashboard's budget is the project's APPROVED budget; the fixture approves the same amount.
        $this->approve($project, $budget);

        return $campaign;
    }

    private function approve(Project $project, float $amount): void
    {
        $funding = ProjectFunding::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'payment_method' => 'prepaid', 'starts_on' => now('Asia/Riyadh')->subDays(20)->toDateString()]);
        FundingPayment::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'kind' => 'initial', 'amount' => $amount, 'currency' => 'SAR', 'amount_sar' => $amount, 'rate' => 1, 'rate_date' => now()->toDateString(),
            'rate_source' => 'identity', 'paid_on' => now('Asia/Riyadh')->subDays(20)->toDateString(), 'idempotency_key' => (string) Str::uuid()]);
        app(FundingSpendRefresher::class)->refresh($funding);
    }

    private function spend(Project $project, UnifiedCampaign $campaign, int $daysAgo, float $value): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => 'meta',
            'metric_key' => 'spend', 'metric_date' => PeriodResolver::today()->subDays($daysAgo)->toDateString(), 'value' => $value, 'project_currency' => 'SAR',
        ]);
    }

    /** @return array<string, mixed> */
    private function dash(User $user, string $query = '', string $part = ''): array
    {
        Sanctum::actingAs($user);

        return (array) $this->getJson('/api/v1/me/dashboard'.$part.$query)->assertOk()->json('data');
    }

    /** DASH-DATE-RANGE-001 — the page reads the from–to chosen in the shared date picker; a bad range falls back to the days. */
    public function test_the_dashboard_reads_the_chosen_range_and_ignores_a_bad_one(): void
    {
        $owner = $this->member(WorkspaceRolePresets::OWNER, null);
        $today = PeriodResolver::today();
        $from = $today->copy()->startOfMonth()->toDateString();

        $d = $this->dash($owner, '?from='.$from.'&to='.$today->toDateString());
        $this->assertSame($from, $d['window']['from']);
        $this->assertSame($today->toDateString(), $d['window']['to']);

        $future = $this->dash($owner, '?from='.$from.'&to='.$today->copy()->addDays(9)->toDateString());
        $this->assertSame($today->toDateString(), $future['window']['to'], 'a range never ends after today');

        $reversed = $this->dash($owner, '?from='.$today->toDateString().'&to='.$from.'&days=7');
        if ($from !== $today->toDateString()) {
            $this->assertSame(7, $reversed['window']['days']);
        }
    }

    public function test_the_manager_sees_every_client_with_budget_reading_and_a_run_out_date_from_the_last_three_days(): void
    {
        // Alpha: 1000 budget, 700 spent in total (400 earlier + 3 x 100); remaining 300 at 100 a day = 3 days left.
        $this->campaign($this->alpha, 1000, 400, 100);
        $this->campaign($this->beta, 5000, 200, 50);

        $d = $this->dash($this->member(WorkspaceRolePresets::OWNER, null));

        $this->assertSame('manager', $d['view']);
        $this->assertSame(2, $d['scope']['clients']);
        $units = collect($d['units'])->keyBy('name');
        $this->assertEqualsWithDelta(0.7, $units['Alpha']['ratio'], 0.001);
        $this->assertEqualsWithDelta(300, $units['Alpha']['remaining'], 0.01);
        $this->assertSame(3, $units['Alpha']['days_left']);
        // RIYADH-DAY-001 — the days are Riyadh days (the platforms' and the product's), so the date is counted from Riyadh's today.
        $this->assertSame(PeriodResolver::today()->addDays(3)->toDateString(), $units['Alpha']['run_out_on']);
        $this->assertEqualsWithDelta(100, $units['Alpha']['daily_rate_3d'], 0.01);
        // Closest to running out leads.
        $this->assertSame('Alpha', $d['units'][0]['name']);
        $this->assertSame(['manager', 'pm', 'finance', 'marketer'], $d['available_views']);
    }

    /**
     * RIYADH-DAY-001 — the same reading either side of Riyadh's midnight, while the server's UTC clock is still on the
     * previous day (00:08 Riyadh = 21:08 UTC; 23:57 Riyadh = 20:57 UTC). CI caught the 00:08 case: the rate was counted on
     * the Riyadh day and the date added to the UTC one.
     *
     * @return array<string, array{string}>
     */
    public static function aroundRiyadhMidnight(): array
    {
        return ['00:08 Riyadh' => ['2026-11-15 21:08:00'], '23:57 Riyadh' => ['2026-11-15 20:57:00'], 'midday Riyadh' => ['2026-11-15 09:00:00']];
    }

    #[DataProvider('aroundRiyadhMidnight')]
    public function test_the_run_out_reading_is_the_same_at_any_hour_of_the_riyadh_day(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        try {
            $this->campaign($this->alpha, 1000, 400, 100);

            $units = collect($this->dash($this->member(WorkspaceRolePresets::OWNER, null))['units'])->keyBy('name');

            $this->assertSame(3, $units['Alpha']['days_left']);
            $this->assertEqualsWithDelta(100, $units['Alpha']['daily_rate_3d'], 0.01);
            $this->assertSame(Carbon::now('Asia/Riyadh')->startOfDay()->addDays(3)->toDateString(), $units['Alpha']['run_out_on']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_project_manager_scoped_to_one_client_never_reads_the_other(): void
    {
        $this->campaign($this->alpha, 1000, 400, 100);
        $this->campaign($this->beta, 5000, 200, 50);
        $pm = $this->member(WorkspaceRolePresets::PROJECT_MANAGER, WorkspaceRolePresets::all()[WorkspaceRolePresets::PROJECT_MANAGER]['permissions'], [(string) $this->alpha->id]);

        $d = $this->dash($pm);

        $this->assertSame('pm', $d['view']);
        $this->assertSame(1, $d['scope']['clients']);
        $this->assertSame(['Alpha'], array_column($d['units'], 'name'));
        $this->assertEqualsWithDelta(1000, $d['money']['budget'], 0.01);
        $this->assertStringNotContainsString('Beta', json_encode($d));
        // The page of another role is not his to request.
        $this->assertSame('pm', $this->dash($pm, '?view=finance')['view']);
    }

    public function test_finance_gets_receivables_and_no_campaign_sections(): void
    {
        $this->campaign($this->alpha, 1000, 400, 100);
        Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->alpha->id, 'number' => 'FX-1', 'currency' => 'SAR', 'subtotal' => 1000, 'tax' => 150, 'total' => 1150, 'amount_paid' => 150, 'status' => 'sent', 'due_date' => now()->subDays(5), 'issued_at' => now()->subDays(20)]);
        $finance = $this->member(WorkspaceRolePresets::FINANCE, WorkspaceRolePresets::all()[WorkspaceRolePresets::FINANCE]['permissions']);

        $d = $this->dash($finance);

        $this->assertSame('finance', $d['view']);
        $this->assertEqualsWithDelta(1000, $d['invoices']['owed'], 0.01);
        $this->assertSame(1, $d['invoices']['overdue_count']);
        $this->assertArrayNotHasKey('campaign_rows', $d);
        $this->assertArrayNotHasKey('tasks', $d);
        // FINANCE-DASH-001 — the spend split across the platforms and the spend limits ride with the finance view.
        $this->assertIsArray($d['platforms']);
        $this->assertIsArray($d['spend_limits']);
        if ($d['platforms'] !== []) {
            $this->assertEqualsWithDelta(1.0, array_sum(array_map(static fn (array $p): float => (float) $p['share'], $d['platforms'])), 0.01);
        }
    }

    public function test_the_marketer_reads_his_own_campaigns_by_objective_and_no_money_without_the_key(): void
    {
        $both = [(string) $this->alpha->id, (string) $this->beta->id];
        $buyer = $this->member(WorkspaceRolePresets::MEDIA_BUYER, WorkspaceRolePresets::all()[WorkspaceRolePresets::MEDIA_BUYER]['permissions'], $both);
        $mine = $this->campaign($this->alpha, 1000, 400, 100, $buyer);
        $this->campaign($this->beta, 5000, 200, 50);

        $d = $this->dash($buyer);

        $this->assertSame('marketer', $d['view']);
        $this->assertTrue($d['mine']);
        $this->assertSame([(string) $mine->id], array_column($d['campaign_rows'], 'id'));

        // The same campaigns without the money key: spend is absent, never zero.
        $blind = $this->member('blind', array_values(array_diff(WorkspaceRolePresets::all()[WorkspaceRolePresets::MEDIA_BUYER]['permissions'], ['budget.view'])), $both);
        $b = $this->dash($blind);
        $this->assertFalse($b['can_money']);
        $this->assertNull($b['money']);
        $this->assertSame([], $b['units']);
        foreach ($b['campaign_rows'] as $row) {
            $this->assertNull($row['spend']);
        }
    }

    /** DELIVERY-ALL-ROLES-001 — running or stopped reaches every role; the money beside it only with the money key. */
    public function test_running_or_stopped_reaches_every_member_and_money_only_with_the_key(): void
    {
        $both = [(string) $this->alpha->id, (string) $this->beta->id];
        $this->campaign($this->alpha, 1000, 400, 100);
        $quiet = $this->campaign($this->beta, 5000, 200, 50);
        // DELIVERY-RULE-7D-001 — Beta spent 12 days ago and nothing in the last 7: stopped, with budget left.
        DailyMetric::query()->where('project_id', $quiet->project_id)
            ->where('metric_date', '>=', PeriodResolver::today()->subDays(6)->toDateString())->delete();

        $blind = $this->member('blind', array_values(array_diff(WorkspaceRolePresets::all()[WorkspaceRolePresets::MEDIA_BUYER]['permissions'], ['budget.view', 'billing.view'])), $both);
        $b = collect($this->dash($blind)['delivery'])->keyBy('name');
        $this->assertSame(['Alpha', 'Beta'], $b->keys()->sort()->values()->all());
        $this->assertTrue($b['Alpha']['delivering']);
        $this->assertSame(['meta' => true], $b['Alpha']['delivering_platforms']);
        $this->assertFalse($b['Beta']['delivering']);
        $this->assertSame('running', $b['Alpha']['reason']);
        $this->assertSame('no_spend', $b['Beta']['reason']);
        foreach ($b as $row) {
            $this->assertNull($row['budget']);
            $this->assertNull($row['remaining']);
        }

        $finance = $this->member(WorkspaceRolePresets::FINANCE, WorkspaceRolePresets::all()[WorkspaceRolePresets::FINANCE]['permissions']);
        $f = collect($this->dash($finance)['delivery'])->keyBy('name');
        $this->assertEqualsWithDelta(1000.0, (float) $f['Alpha']['budget'], 0.01);
        $this->assertNotNull($f['Beta']['remaining']);

        // DELIVERY-TODAY-001 — today's spend so far, from the budget's own ledger: refreshed today with nothing spent is a real 0.
        $this->assertSame(0.0, (float) $f['Alpha']['today_spend']);
        $this->assertNotNull($f['Alpha']['today_as_of']);
        $alphaProject = Project::query()->where('client_workspace_id', $this->alpha->id)->firstOrFail();
        $alphaCampaign = UnifiedCampaign::query()->where('project_id', $alphaProject->id)->firstOrFail();
        $this->spend($alphaProject, $alphaCampaign, 0, 75);
        app(FundingSpendRefresher::class)->refresh(ProjectFunding::query()->where('project_id', $alphaProject->id)->firstOrFail());
        $f = collect($this->dash($finance)['delivery'])->keyBy('name');
        $this->assertEqualsWithDelta(75.0, (float) $f['Alpha']['today_spend'], 0.01);
        // Without the money key there is no figure at all.
        $this->assertNull($b['Alpha']['today_spend']);
    }

    /** DASH-MANAGER-PERFORMANCE-001 — the manager reads every reachable campaign's performance, not only his own. */
    public function test_the_manager_reads_the_performance_of_every_campaign_he_can_reach(): void
    {
        $a = $this->campaign($this->alpha, 1000, 400, 100);
        $b = $this->campaign($this->beta, 5000, 200, 50);

        $owner = $this->member(WorkspaceRolePresets::OWNER, null);
        $page = $this->dash($owner);
        $d = $this->dash($owner, '', '/performance');

        $this->assertSame('manager', $page['view']);
        // DASH-PERF-SPLIT-001 — the page is not held for the performance: it is read on its own.
        $this->assertTrue($page['performance_deferred']);
        $this->assertArrayNotHasKey('campaign_rows', $page);
        $this->assertArrayNotHasKey('mine', $d);
        $ids = array_column($d['campaign_rows'], 'id');
        sort($ids);
        $expected = [(string) $a->id, (string) $b->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertArrayHasKey('platforms', $d);
        $this->assertArrayHasKey('best', $d);
        // The counts stay the manager's own (every campaign), on the page.
        $this->assertSame(2, $page['campaigns']['total']);
    }

    /** DASH-PERIOD-001 — a chosen window, its spend against the window just before it, over every campaign. */
    public function test_the_window_is_chosen_and_its_spend_is_read_against_the_window_before_it(): void
    {
        // Each fixture spends `earlier` 12 days ago and `daily` on each of the last three days.
        $this->campaign($this->alpha, 1000, 400, 100);
        $this->campaign($this->beta, 5000, 200, 50);
        $owner = $this->member(WorkspaceRolePresets::OWNER, null);

        $this->assertSame(7, $this->dash($owner, '?days=7')['window']['days']);
        $d = $this->dash($owner, '?days=7', '/performance');

        $this->assertSame(7, $d['window']['days']);
        $this->assertEqualsWithDelta(450, $d['performance']['spend'], 0.01);
        $this->assertEqualsWithDelta(600, $d['performance']['previous_spend'], 0.01);
        $this->assertSame(2, $d['performance']['campaigns']);

        // A window the page does not offer falls back to thirty days.
        $this->assertSame(30, $this->dash($owner, '?days=11')['window']['days']);
    }

    /** WATCH-BOARD-001 — the follow-up board reads every reachable client, or one, and never one out of reach. */
    public function test_the_watch_board_reads_one_client_and_never_one_out_of_reach(): void
    {
        $a = $this->campaign($this->alpha, 1000, 400, 100);
        $b = $this->campaign($this->beta, 5000, 200, 50);
        $owner = $this->member(WorkspaceRolePresets::OWNER, null);

        $all = $this->dash($owner, '', '/watch');
        $this->assertNull($all['unit']);
        $this->assertSame(['Alpha', 'Beta'], array_column($all['units'], 'name'));
        $this->assertCount(2, $all['campaign_rows']);
        $this->assertNotEmpty($all['trend']);
        $this->assertArrayHasKey('content', $all);

        $one = $this->dash($owner, '?unit='.$this->alpha->id, '/watch');
        $this->assertSame((string) $this->alpha->id, $one['unit']);
        $this->assertSame([(string) $a->id], array_column($one['campaign_rows'], 'id'));
        // Alpha alone: 400 twelve days ago and 3 × 100 since.
        $this->assertEqualsWithDelta(700, $one['performance']['spend'] ?? 0, 0.01);

        // A PM confined to Alpha asking for Beta reads his own reach, not Beta.
        $pm = $this->member(WorkspaceRolePresets::PROJECT_MANAGER, WorkspaceRolePresets::all()[WorkspaceRolePresets::PROJECT_MANAGER]['permissions'], [(string) $this->alpha->id]);
        $p = $this->dash($pm, '?unit='.$this->beta->id, '/watch');
        $this->assertNull($p['unit']);
        $this->assertSame(['Alpha'], array_column($p['units'], 'name'));
        $this->assertNotContains((string) $b->id, array_column($p['campaign_rows'], 'id'));
        $this->assertStringNotContainsString('Beta', json_encode($p));

        // Finance opens no campaigns, so the board is not offered and not served.
        $finance = $this->member(WorkspaceRolePresets::FINANCE, WorkspaceRolePresets::all()[WorkspaceRolePresets::FINANCE]['permissions']);
        $this->assertFalse($this->dash($finance)['can_watch']);
        $this->getJson('/api/v1/me/dashboard/watch')->assertForbidden();
    }

    /**
     * HOME-SCOPE-ISOLATION-001 — Owner 2026-11-20: with one client and project chosen, Home printed $23.5K of spend, 1.83K
     * orders and other clients' leading campaigns. The deferred performance panels read the whole reach; they follow the
     * chosen client and project now, as the page and the watch board do — and a project of another client is ignored.
     */
    public function test_the_performance_panels_follow_the_chosen_client_and_project(): void
    {
        $a = $this->campaign($this->alpha, 1000, 400, 100);
        $b = $this->campaign($this->beta, 5000, 200, 50);
        $owner = $this->member(WorkspaceRolePresets::OWNER, null);

        $all = $this->dash($owner, '', '/performance');
        $this->assertEqualsWithDelta(700 + 350, $all['performance']['spend'] ?? 0, 0.01);

        $alpha = $this->dash($owner, '?client='.$this->alpha->id, '/performance');
        $this->assertEqualsWithDelta(700, $alpha['performance']['spend'] ?? 0, 0.01, 'another client\'s spend reached the chosen client');
        $ids = array_merge(array_column($alpha['best'] ?? [], 'id'), array_column($alpha['worst'] ?? [], 'id'), array_column($alpha['campaign_rows'] ?? [], 'id'));
        $this->assertNotContains((string) $b->id, $ids);
        $this->assertContains((string) $a->id, $ids);

        $project = $this->dash($owner, '?client='.$this->alpha->id.'&project='.$a->project_id, '/performance');
        $this->assertEqualsWithDelta(700, $project['performance']['spend'] ?? 0, 0.01);

        // A project of another client than the one chosen widens nothing.
        $crossed = $this->dash($owner, '?client='.$this->alpha->id.'&project='.$b->project_id, '/performance');
        $this->assertEqualsWithDelta(700, $crossed['performance']['spend'] ?? 0, 0.01);

        // The page itself follows the project too.
        $page = $this->dash($owner, '?client='.$this->alpha->id.'&project='.$a->project_id);
        $this->assertSame(1, $page['scope']['projects']);

        // And the watch board.
        $watch = $this->dash($owner, '?unit='.$this->alpha->id.'&project='.$a->project_id, '/watch');
        $this->assertSame([(string) $a->id], array_column($watch['campaign_rows'], 'id'));
    }

    public function test_another_tenants_figures_never_appear(): void
    {
        $this->campaign($this->alpha, 1000, 400, 100);
        $other = Tenant::create(['name' => 'Other Agency', 'slug' => 'ot-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $other->id);
        $foreign = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'slug' => 'fo-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['tenant_id' => $other->id, 'client_workspace_id' => $foreign->id, 'name' => 'FP', 'status' => 'active']);
        UnifiedCampaign::create(['tenant_id' => $other->id, 'project_id' => $project->id, 'name' => 'FC', 'objective' => 'sales', 'status' => 'active', 'total_budget' => 9999, 'budget_currency' => 'SAR']);
        $this->holdingTenant((string) $this->tenant->id);

        $d = $this->dash($this->member(WorkspaceRolePresets::OWNER, null));

        $this->assertSame(['Alpha'], collect($d['units'])->pluck('name')->all());
        $this->assertSame(2, $d['scope']['clients']);
        $this->assertStringNotContainsString('Foreign', json_encode($d));
        $this->assertEqualsWithDelta(1000, $d['money']['budget'], 0.01);
    }

    public function test_it_needs_a_session(): void
    {
        $this->getJson('/api/v1/me/dashboard')->assertUnauthorized();
    }
}
