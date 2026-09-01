<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Actions\AdvanceLead;
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
 * LEAD-OPERATIONS-001 — the pipeline, and the timestamps that come with each move.
 *
 * ## What existed, and what it could not say
 *
 * `LeadStatus` is a SALES lifecycle — new, contacted, qualified, proposal sent, won, lost. It has no
 * answer for the three questions a follow-up team asks all day: has anybody been GIVEN this lead,
 * has anybody TRIED to reach them, and is this a real person at all. So «assigned» was invisible, a
 * call that rang out was indistinguishable from no call, and a junk submission had to be marked
 * `lost` — in the same bucket as a real customer who chose a competitor, quietly poisoning every
 * conversion rate computed from that column.
 *
 * `assigned_at`, `first_attempt_at`, `first_contact_at` and `qualified_at` already existed, and
 * **nothing had ever written one**. Four columns, four empty reports, and it would have read as a
 * data problem rather than a missing write.
 */
final class LeadPipelineTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'lp-'.uniqid(), 'status' => 'active']);
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

    /** The four columns that existed and were never written now travel with the move. */
    public function test_moving_a_lead_stamps_the_moment_it_moved(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);

        $action->assign($lead, $this->member(ProjectRole::LEAD_AGENT)->id);
        $this->assertNotNull($lead->refresh()->assigned_at);
        $this->assertSame(LeadStage::Assigned->value, $lead->status, 'assigning a NEW lead is itself a stage');

        $action->execute($lead, LeadStage::ContactAttempted);
        $lead->refresh();
        $this->assertNotNull($lead->first_attempt_at);
        $this->assertSame(1, $lead->contact_attempts);
        // An attempt is work done and is not a conversation.
        $this->assertNull($lead->first_contact_at);

        $action->execute($lead, LeadStage::Contacted);
        $lead->refresh();
        $this->assertNotNull($lead->first_contact_at);
        $this->assertNotNull($lead->last_contact_at);
        $this->assertSame(2, $lead->contact_attempts, 'reaching somebody on the second try is two calls');
    }

    /**
     * First is first.
     *
     * Response time is measured from the FIRST conversation. A team that calls a lead again next
     * month must not thereby improve their recorded response time by a month — which is exactly what
     * one column would have done.
     */
    public function test_the_first_conversation_is_not_overwritten_by_the_next(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);

        $action->execute($lead, LeadStage::ContactAttempted, at: Carbon::parse('2026-08-01 09:00'));
        $action->execute($lead, LeadStage::Contacted, at: Carbon::parse('2026-08-01 10:00'));
        $first = $lead->refresh()->first_contact_at;

        $action->execute($lead, LeadStage::Qualified, at: Carbon::parse('2026-08-02 10:00'));
        $action->execute($lead, LeadStage::Contacted, at: Carbon::parse('2026-09-01 10:00'));

        $lead->refresh();
        $this->assertTrue($first->equalTo($lead->first_contact_at), 'the first conversation moved');
        $this->assertSame('2026-09-01 10:00', $lead->last_contact_at->format('Y-m-d H:i'));
    }

    /**
     * Falling back PAST recorded work is refused.
     *
     * Forward is unrestricted on purpose — a team that reaches somebody on the first call goes
     * straight from `assigned` to `contacted`, and demanding one step at a time only teaches an
     * agent to click through stages they did not do, which puts a fabricated attempt into the very
     * figures the stages exist to measure.
     *
     * What cannot happen is a rewrite: a lead carrying a `first_contact_at` cannot sit at `new`,
     * because its stage and its history would then contradict each other and every report reading
     * one of them would be wrong about the other.
     */
    public function test_a_lead_cannot_fall_back_past_the_work_it_records(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);
        $action->execute($lead, LeadStage::Contacted);
        $action->execute($lead, LeadStage::Qualified);

        $this->expectException(\InvalidArgumentException::class);
        $action->execute($lead, LeadStage::New);
    }

    /** And forward, however far, is allowed — the first call succeeded. */
    public function test_a_first_call_that_succeeds_goes_straight_to_contacted(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);

        $action->execute($lead, LeadStage::Assigned);
        $action->execute($lead, LeadStage::Contacted);

        $this->assertSame(LeadStage::Contacted->value, $lead->refresh()->status);
        $this->assertNotNull($lead->first_contact_at);
    }

    /** But a real pipeline goes backwards: a mis-click has to be correctable. */
    public function test_a_lead_can_be_moved_back_one_step(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);

        $action->execute($lead, LeadStage::Assigned);
        $action->execute($lead, LeadStage::ContactAttempted);
        $action->execute($lead, LeadStage::Assigned);

        $this->assertSame(LeadStage::Assigned->value, $lead->refresh()->status);
    }

    /** Junk is junk from anywhere, and it is never a lost sale. */
    public function test_a_lead_can_be_marked_invalid_from_any_stage_and_is_not_lost(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);

        $action->execute($lead, LeadStage::Assigned);
        $action->execute($lead, LeadStage::ContactAttempted);
        $action->execute($lead, LeadStage::Invalid);

        $this->assertSame(LeadStage::Invalid->value, $lead->refresh()->status);
        $this->assertNotSame(LeadStage::Lost->value, $lead->status);
        $this->assertFalse(LeadStage::Invalid->isContacted());
    }

    /**
     * A finished lead has nothing to follow up.
     *
     * Leaving the promise behind keeps it on the overdue list forever, which is the single most
     * reliable way to make a team stop trusting one.
     */
    public function test_finishing_a_lead_clears_its_follow_up(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);

        $action->execute($lead, LeadStage::Assigned);
        $action->scheduleFollowUp($lead, Carbon::parse('2026-09-05 09:00'));
        $this->assertNotNull($lead->refresh()->next_follow_up_at);

        $action->execute($lead, LeadStage::Lost);
        $this->assertNull($lead->refresh()->next_follow_up_at);
    }

    /**
     * Reassigning a worked lead does not send it back to the start.
     *
     * Assignment is its own act rather than a stage change with an owner attached — otherwise
     * handing a `contacted` lead to a colleague would lose the fact that somebody has spoken to
     * this person.
     */
    public function test_reassigning_a_contacted_lead_keeps_its_stage(): void
    {
        $lead = $this->lead();
        $action = app(AdvanceLead::class);
        $action->execute($lead, LeadStage::Assigned);
        $action->execute($lead, LeadStage::Contacted);

        $action->assign($lead, $this->member(ProjectRole::LEAD_AGENT)->id);

        $this->assertSame(LeadStage::Contacted->value, $lead->refresh()->status);
    }

    // ── The routes, where the permission actually has to hold ────────────────────────────────────

    /** An agent works the lead they were given. */
    public function test_an_agent_moves_their_own_lead_through_the_route(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $lead = $this->lead($agent->id);

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->getKey()}/stage", ['stage' => 'contact_attempted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contact_attempted');
    }

    /** And not one they were not, however they reached its id. */
    public function test_an_agent_cannot_move_a_lead_that_is_not_theirs(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $other = $this->lead();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/leads/{$other->getKey()}/stage", ['stage' => 'contact_attempted'])
            ->assertForbidden();
    }

    /**
     * Working a lead and deciding who works it are different jobs.
     *
     * An agent who can quietly pass their difficult leads to a colleague is a pipeline nobody can
     * manage, so `leads.assign` is a separate capability and the agent preset does not carry it.
     */
    public function test_an_agent_cannot_reassign_and_a_manager_can(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $manager = $this->member(ProjectRole::SALES_MANAGER);
        $lead = $this->lead($agent->id);

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->getKey()}/assign", ['owner_id' => $manager->id])
            ->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->getKey()}/assign", ['owner_id' => $manager->id])
            ->assertOk();

        $this->assertSame($manager->id, $lead->refresh()->owner_id);
    }

    /**
     * An impossible move is 422, not 403 — the mover is entitled, the MOVE is what is wrong.
     *
     * A 403 here would send somebody to ask for a permission they already hold, and they would be
     * given it, and the move would still fail.
     */
    public function test_an_impossible_move_is_reported_as_the_move_and_not_the_permission(): void
    {
        $manager = $this->member(ProjectRole::SALES_MANAGER);
        $lead = $this->lead();
        app(AdvanceLead::class)->execute($lead, LeadStage::Qualified);

        // Back past the work it records: the stage would then contradict `first_contact_at`.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->getKey()}/stage", ['stage' => 'new'])
            ->assertStatus(422);
    }

    // ── Every lead route, not one of them ────────────────────────────────────────────────────────

    /**
     * TEAM-PROJECT-RBAC-001 — the capability holds on the whole surface, not on the endpoints that
     * happened to be written last.
     *
     * All four checked only the TENANT permission, which is the same permission whichever project
     * the lead belongs to and therefore cannot say «not this client». Every subject below holds the
     * tenant permissions in full, so the tenant layer refuses nothing and the project layer is the
     * only thing left doing the work — without that, this test would pass on a permission the
     * subject simply lacks and would prove nothing at all.
     *
     * The agent half: a lead they were not given.
     */
    public function test_an_agent_cannot_reach_a_lead_they_were_not_given(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT, self::EVERY_LEAD_PERMISSION);
        $theirs = $this->lead();

        foreach ($this->everyLeadRoute($theirs) as [$method, $url, $body]) {
            $this->actingAs($agent, 'sanctum')->json($method, $url, $body)->assertForbidden();
        }

        $this->assertSame('نورة', $theirs->refresh()->name, 'the lead was edited by somebody with no claim to it');
    }

    /**
     * The capability half: somebody who legitimately READS this client's leads and may not change
     * one.
     *
     * A management viewer holds `leads.view` on this project and every lead permission the tenant
     * has to give. What they do not hold is `leads.update` HERE, and that is what must stop them —
     * the executive question is «how many, how fast, what did each cost», and none of it involves
     * editing somebody's record.
     */
    public function test_a_reader_of_this_project_still_cannot_change_its_leads(): void
    {
        $viewer = $this->member(ProjectRole::MANAGEMENT_VIEWER, self::EVERY_LEAD_PERMISSION);
        $lead = $this->lead();

        foreach ($this->everyLeadRoute($lead) as [$method, $url, $body]) {
            if ($method === 'GET') {
                // Reading is exactly what this role is for.
                $this->actingAs($viewer, 'sanctum')->json($method, $url, $body)->assertOk();

                continue;
            }

            $this->actingAs($viewer, 'sanctum')->json($method, $url, $body)->assertForbidden();
        }

        $this->assertSame('نورة', $lead->refresh()->name);
    }

    /** @return list<array{0: string, 1: string, 2: array<string,mixed>}> */
    private function everyLeadRoute(Lead $lead): array
    {
        $id = $lead->getKey();

        return [
            ['GET', "/api/v1/leads/{$id}", []],
            ['PATCH', "/api/v1/leads/{$id}", ['name' => 'changed']],
            ['POST', "/api/v1/leads/{$id}/stage", ['stage' => 'contact_attempted']],
            ['POST', "/api/v1/leads/{$id}/follow-up", ['next_follow_up_at' => null]],
            ['POST', "/api/v1/leads/{$id}/convert", []],
            ['DELETE', "/api/v1/leads/{$id}", []],
        ];
    }

    private function lead(?int $ownerId = null): Lead
    {
        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'نورة',
            'email' => 'noura-'.uniqid().'@example.com',
            'phone' => '0500000000',
            'source' => 'provider',
            'provider' => 'meta',
            'status' => LeadStage::New->value,
            'owner_id' => $ownerId,
            'provider_lead_id' => (string) Str::uuid(),
        ])->save();

        return $lead;
    }

    /** Every lead permission the tenant layer has to give, so it refuses nothing and the project decides. */
    private const EVERY_LEAD_PERMISSION = [
        'projects.view', 'leads.view', 'leads.pii.view', 'leads.update', 'leads.delete',
        'leads.convert', 'leads.assign', 'leads.export',
    ];

    /** @param  list<string>|null  $tenantPermissions */
    private function member(string $role, ?array $tenantPermissions = null): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.test', 'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);

        $tenantRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $tenantRole->givePermissionTo(
            ...Permission::whereIn('key', $tenantPermissions ?? ['projects.view', 'leads.view'])->pluck('key')->all(),
        );
        $user->assignRole($tenantRole);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => Carbon::now(),
        ]);

        return $user;
    }
}
