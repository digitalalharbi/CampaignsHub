<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Tenancy\Enums\Portal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * ONE-BUDGET-001 (consistency pass, builder H) — «ميزانية المشروع» has one source: the approved budget on /agency/budgets.
 *
 * Fixture: approved 5,069 SAR, real spend 4,404 SAR inside the budget's period → remaining 665 SAR, and that same 665 is
 * what the home dashboard, the client page, the campaigns page, the budgets page, the role dashboard and the export say —
 * whatever window the dashboard is set to. A campaign budget set inside a platform (here 12,000) is never read as it.
 */
final class OneProjectBudgetTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFunding();
    }

    /** The client page lives in the agency portal. */
    private function agencyOwner(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'AO', 'email' => 'ao-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant, Portal::Agency);
        $user->assignRole($role);

        return $user;
    }

    public function test_every_reader_says_the_same_remaining_as_the_budgets_page(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('University', 'Campus');
        $account = (string) Str::uuid();
        $this->spendRow($project, $account, 4, 2_000);
        $this->spendRow($project, (string) Str::uuid(), 2, 2_404);
        $this->addBudget($project, 5_069, 6);
        // A platform campaign budget that is NOT the project budget.
        UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'C1', 'objective' => 'traffic',
            'status' => 'active', 'total_budget' => 12_000, 'budget_currency' => 'SAR']);

        $budgetsPage = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/funding/projects/'.$project->id)->assertOk()->json('data');
        $this->assertEqualsWithDelta(5_069, $budgetsPage['approved'], 0.001);
        $this->assertEqualsWithDelta(4_404, $budgetsPage['spent'], 0.001);
        $this->assertEqualsWithDelta(665, $budgetsPage['remaining'], 0.001);

        // Home dashboard («كل العملاء» cards).
        $row = collect($this->getJson('/api/v1/agency/client-budgets')->assertOk()->json('data'))->firstWhere('client_id', $project->client_workspace_id);
        $this->assertEqualsWithDelta(665, $row['project_budget']['remaining'], 0.001);
        $this->assertEqualsWithDelta(5_069, $row['project_budget']['approved'], 0.001);
        $this->assertEqualsWithDelta(665, $row['project_budgets'][0]['budget']['remaining'], 0.001);
        // The platform campaign budget rides along separately, informational only.
        $this->assertArrayHasKey('platform_campaign_budgets', $row);

        // Client page.
        $client = $this->actingAs($this->agencyOwner(), 'sanctum')->getJson('/api/v1/app/clients/'.$project->client_workspace_id)->assertOk()->json('data.budget');
        $this->actingAs($owner, 'sanctum');
        $this->assertEqualsWithDelta(665, $client['total']['remaining'], 0.001);
        $this->assertEqualsWithDelta(665, $client['projects'][0]['budget']['remaining'], 0.001);

        // Campaigns page (project-scoped).
        $campaigns = $this->getJson('/api/v1/projects/'.$project->id.'/approved-budget')->assertOk()->json('data');
        $this->assertTrue($campaigns['has_budget']);
        $this->assertEqualsWithDelta(665, $campaigns['remaining'], 0.001);
        $this->assertEqualsWithDelta(5_069, $campaigns['approved'], 0.001);

        // Role dashboard (manager / finance / project manager share the same money block).
        $dash = $this->getJson('/api/v1/me/dashboard?view=finance')->assertOk()->json('data');
        $this->assertEqualsWithDelta(665, $dash['money']['remaining'], 0.001);
        $this->assertEqualsWithDelta(5_069, $dash['money']['budget'], 0.001);
        $this->assertSame('approved_budget', $dash['money']['source']);

        // Export.
        $csv = $this->get('/api/v1/funding/export?format=csv&sheet=summary')->assertOk()->getContent();
        $this->assertStringContainsString('665', $csv);
        $this->assertStringContainsString('5069', str_replace([',', '.00'], '', $csv));
    }

    public function test_changing_the_dashboard_period_leaves_the_budget_numbers_unchanged(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('University', 'Campus');
        $this->spendRow($project, (string) Str::uuid(), 40, 1_000);
        $this->spendRow($project, (string) Str::uuid(), 2, 3_404);
        $this->addBudget($project, 5_069, 45);

        $read = function (string $query) use ($owner, $project): array {
            $rows = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/agency/client-budgets'.$query)->assertOk()->json('data');

            return collect($rows)->firstWhere('client_id', $project->client_workspace_id)['project_budget'];
        };

        $default = $read('');
        $week = $read('?from='.$this->day(6).'&to='.$this->day(0));
        $quarter = $read('?from='.$this->day(89).'&to='.$this->day(0));

        $this->assertEqualsWithDelta(665, $default['remaining'], 0.001);
        $this->assertSame($default, $week);
        $this->assertSame($default, $quarter);
    }

    public function test_a_project_without_a_budget_reads_no_budget_never_a_platform_number(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('Store', 'Shop');
        $this->spendRow($project, (string) Str::uuid(), 2, 300);
        UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'C1', 'objective' => 'sales',
            'status' => 'active', 'total_budget' => 3_410, 'budget_currency' => 'USD']);

        $campaigns = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/projects/'.$project->id.'/approved-budget')->assertOk()->json('data');
        $this->assertSame(['has_budget' => false], $campaigns);

        $row = collect($this->getJson('/api/v1/agency/client-budgets')->assertOk()->json('data'))->firstWhere('client_id', $project->client_workspace_id);
        $this->assertFalse($row['project_budgets'][0]['budget']['has_budget']);
        $this->assertNull($row['project_budget']['approved']);
        $this->assertNull($row['project_budget']['remaining']);
        $this->assertSame(1, $row['project_budget']['unbudgeted']);

        $client = $this->actingAs($this->agencyOwner(), 'sanctum')->getJson('/api/v1/app/clients/'.$project->client_workspace_id)->assertOk()->json('data.budget');
        $this->assertFalse($client['projects'][0]['budget']['has_budget']);
    }

    /**
     * DASH-CLIENT-FILTER-001 — Owner 2026-11-18: choosing a client narrows every figure on the home page to it, the budget
     * included; a client outside the member's reach is ignored, never widened to.
     */
    public function test_the_role_dashboard_budget_follows_the_chosen_client(): void
    {
        $owner = $this->owner();
        $a = $this->newProject('University', 'Campus');
        $b = $this->newProject('Store', 'Shop');
        $this->spendRow($a, (string) Str::uuid(), 2, 4_404);
        $this->addBudget($a, 5_069, 6);
        $this->spendRow($b, (string) Str::uuid(), 2, 1_000);

        $all = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/me/dashboard?view=finance')->assertOk()->json('data');
        $this->assertSame(2, $all['scope']['projects']);

        $onlyA = $this->getJson('/api/v1/me/dashboard?view=finance&client='.$a->client_workspace_id)->assertOk()->json('data');
        $this->assertSame(1, $onlyA['scope']['clients']);
        $this->assertSame(1, $onlyA['scope']['projects']);
        $this->assertEqualsWithDelta(5_069, $onlyA['money']['budget'], 0.001);

        // The other client has no budget: its page states none, never the first client's 5,069.
        $onlyB = $this->getJson('/api/v1/me/dashboard?view=finance&client='.$b->client_workspace_id)->assertOk()->json('data');
        $this->assertSame(1, $onlyB['scope']['projects']);
        $this->assertNotEquals(5_069, $onlyB['money']['budget'] ?? null);

        // An id the member cannot reach changes nothing.
        $unknown = $this->getJson('/api/v1/me/dashboard?view=finance&client='.Str::uuid())->assertOk()->json('data');
        $this->assertSame($all['scope'], $unknown['scope']);
    }
}
