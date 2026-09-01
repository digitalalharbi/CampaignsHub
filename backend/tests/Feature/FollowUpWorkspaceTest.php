<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * LEAD-SLA-NOTIFICATION-001 — the manager's screen, and the four places it could lie.
 *
 * A rate with no denominator printed as «0%» tells a manager their team stopped working on a day
 * nothing came in. An overdue list that includes leads which were never people can never be cleared,
 * and a list nobody can clear is one nobody reads. A mean response time is destroyed by one lead
 * found in a spam folder three weeks later. And an agent shown their colleagues' contact rates has
 * been handed a performance ranking nobody asked this product to publish.
 */
final class FollowUpWorkspaceTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'fw-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'آساس الثبات', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'Lead generation', 'status' => 'active',
        ]);
    }

    /** The six counts a manager reads first, over one window and one scope. */
    public function test_it_counts_the_pipeline_over_one_window(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);

        $this->lead(LeadStage::New);
        $this->lead(LeadStage::Assigned, owner: $agent->id);
        $this->lead(LeadStage::Contacted, owner: $agent->id, contactedAfterMinutes: 30);
        $this->lead(LeadStage::Qualified, owner: $agent->id, contactedAfterMinutes: 10);
        $this->lead(LeadStage::Invalid);
        // Outside the window: present in the table, absent from every figure below.
        $this->lead(LeadStage::Contacted, receivedAt: Carbon::parse('2026-01-01 09:00'));

        $body = $this->workspace($this->member(ProjectRole::SALES_MANAGER))['summary'];

        $this->assertSame(5, $body['received']);
        $this->assertSame(2, $body['unassigned'], 'the NEW lead and the invalid one belong to nobody');
        $this->assertSame(2, $body['contacted']);
        $this->assertSame(1, $body['qualified']);
        $this->assertSame(1, $body['invalid']);
        // Not «received minus contacted»: the invalid lead was never a follow-up failure.
        $this->assertSame(2, $body['not_contacted']);
    }

    /**
     * A rate with no denominator is «—», never 0%.
     *
     * On a day nothing came in, 0% contact rate is a statement about the team and it is false.
     */
    public function test_a_rate_with_nothing_to_divide_is_absent_rather_than_zero(): void
    {
        $body = $this->workspace($this->member(ProjectRole::SALES_MANAGER))['summary'];

        $this->assertSame(0, $body['received']);
        $this->assertNull($body['contact_rate']);
        $this->assertNull($body['qualification_rate']);
        $this->assertNull($body['first_response']['median_minutes']);
    }

    /** Junk is out of the denominator: a team is not judged on leads that were never people. */
    public function test_an_invalid_lead_does_not_count_against_the_contact_rate(): void
    {
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 5);
        $this->lead(LeadStage::Invalid);

        $body = $this->workspace($this->member(ProjectRole::SALES_MANAGER))['summary'];

        // One contactable lead, contacted: 100%, not 50%. Compared numerically — JSON renders 1.0 as 1.
        $this->assertEqualsWithDelta(1.0, $body['contact_rate'], 0.0001);
    }

    /**
     * The response time is a median, and it says how many leads it came from.
     *
     * One lead found three weeks late moves a mean past usefulness. «2 minutes» from two leads is
     * not a fact about the team, and a reader given only the number cannot tell the difference.
     */
    public function test_the_response_time_is_a_median_and_carries_its_own_sample(): void
    {
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 5);
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 15);
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 30_240); // three weeks
        $this->lead(LeadStage::New);

        $r = $this->workspace($this->member(ProjectRole::SALES_MANAGER))['summary']['first_response'];

        $this->assertSame(15, $r['median_minutes'], 'a mean would have reported about seven days');
        $this->assertSame(3, $r['measured']);
        $this->assertSame(4, $r['of'], 'the lead nobody has answered is in the denominator, not the median');
    }

    /**
     * Overdue is asked of the whole open pipeline, not of the window.
     *
     * A promise made three weeks ago is overdue today, and a manager filtering to «this week» must
     * not thereby stop seeing it.
     */
    public function test_overdue_reaches_outside_the_window_and_ignores_finished_leads(): void
    {
        $old = $this->lead(LeadStage::Contacted, receivedAt: Carbon::parse('2026-01-01 09:00'), contactedAfterMinutes: 10);
        $old->forceFill(['next_follow_up_at' => Carbon::now()->subDays(3)])->save();

        $done = $this->lead(LeadStage::Won, contactedAfterMinutes: 10);
        $done->forceFill(['next_follow_up_at' => Carbon::now()->subDays(3)])->save();

        $body = $this->workspace($this->member(ProjectRole::SALES_MANAGER))['summary'];

        $this->assertSame(1, $body['overdue']);
        $this->assertSame('all_open', $body['overdue_scope']);
    }

    /**
     * An agent's dashboard describes the leads an agent can see.
     *
     * Computing it from the whole table would show them a contact rate they cannot act on and a
     * count they cannot reconcile with the list beside it.
     */
    public function test_an_agents_figures_describe_their_own_leads(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $this->lead(LeadStage::Contacted, owner: $agent->id, contactedAfterMinutes: 5);
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 5);
        $this->lead(LeadStage::New);

        $this->assertSame(1, $this->workspace($agent)['summary']['received']);
        $this->assertSame(3, $this->workspace($this->member(ProjectRole::SALES_MANAGER))['summary']['received']);
    }

    /**
     * And an agent is not shown their colleagues.
     *
     * A per-person table in front of somebody who cannot assign work is a performance ranking nobody
     * asked this product to publish, and it is not information they can act on.
     */
    public function test_only_somebody_who_runs_the_pipeline_sees_the_per_person_table(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $this->lead(LeadStage::Contacted, owner: $agent->id, contactedAfterMinutes: 5);

        $this->assertNull($this->workspace($agent)['by_owner']);
        $this->assertNotNull($this->workspace($this->member(ProjectRole::SALES_MANAGER))['by_owner']);
    }

    /** Unowned leads are their own row: the most urgent thing on the screen must not be dropped. */
    public function test_the_per_person_table_keeps_the_leads_nobody_owns(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $this->lead(LeadStage::Contacted, owner: $agent->id, contactedAfterMinutes: 5);
        $this->lead(LeadStage::New);

        $rows = $this->workspace($this->member(ProjectRole::SALES_MANAGER))['by_owner'];
        $owners = array_column($rows, 'owner_id');

        $this->assertContains(null, $owners, 'the unassigned pile is outside every column');
        $this->assertContains($agent->id, $owners);
    }

    /**
     * EXECUTIVE-OPS-DASHBOARD-001 — the money, the people and the work, through the route.
     *
     * The unit cases hold the join; this holds the wiring, which is where every previous «backbone
     * nobody calls» defect in this product actually lived: the service existed, was tested, and no
     * route reached it.
     */
    public function test_the_executive_view_joins_the_spend_to_the_leads(): void
    {
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 20);
        $this->lead(LeadStage::Invalid);

        $body = $this->actingAs($this->member(ProjectRole::SALES_MANAGER), 'sanctum')
            ->getJson('/api/v1/leads/executive?project_id='.$this->project->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $body['leads']['received']);
        $this->assertSame(1, $body['leads']['invalid']);
        $this->assertArrayHasKey('cost_per_lead', $body);
        $this->assertArrayHasKey('attention', $body);
        /*
         * No spend ran in this window, so there is no cost to report. «0 per lead» would read as
         * «these leads were free», which is a claim about the advertising rather than about the
         * absence of it.
         */
        $this->assertNull($body['cost_per_lead']['amount']);
        $this->assertSame('no_spend', $body['cost_per_lead']['reason']);
    }

    /** And it is scoped like everything else: an agent sees their own leads here too. */
    public function test_the_executive_view_reads_the_callers_own_scope(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $this->lead(LeadStage::Contacted, owner: $agent->id, contactedAfterMinutes: 5);
        $this->lead(LeadStage::Contacted, contactedAfterMinutes: 5);

        $body = $this->actingAs($agent, 'sanctum')
            ->getJson('/api/v1/leads/executive?project_id='.$this->project->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $body['leads']['received']);
    }

    /** @return array<string,mixed> */
    private function workspace(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/leads/workspace?project_id='.$this->project->id)
            ->assertOk()
            ->json('data');
    }

    private function lead(
        LeadStage $stage,
        ?int $owner = null,
        ?Carbon $receivedAt = null,
        ?int $contactedAfterMinutes = null,
    ): Lead {
        $receivedAt ??= Carbon::now()->subDay();

        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'نورة',
            'email' => 'n-'.uniqid().'@example.com',
            'source' => 'provider',
            'provider' => 'meta',
            'status' => $stage->value,
            'owner_id' => $owner,
            'received_at' => $receivedAt,
            'first_contact_at' => $contactedAfterMinutes === null ? null : (clone $receivedAt)->addMinutes($contactedAfterMinutes),
            'provider_lead_id' => (string) Str::uuid(),
        ])->save();

        return $lead;
    }

    private function member(string $role): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.test', 'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);

        $tenantRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $tenantRole->givePermissionTo(...Permission::whereIn('key', ['projects.view', 'leads.view'])->pluck('key')->all());
        $user->assignRole($tenantRole);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => Carbon::now(),
        ]);

        return $user;
    }
}
