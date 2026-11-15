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
    private function dash(User $user, string $query = ''): array
    {
        Sanctum::actingAs($user);

        return (array) $this->getJson('/api/v1/me/dashboard'.$query)->assertOk()->json('data');
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
