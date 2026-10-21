<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Middleware\WithholdMoneyForMember;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MEMBER-VISIBILITY-001 — a membership may withhold the money from one person on one project, and the
 * server, not the screen, is what withholds it.
 */
final class MemberMoneyVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    /** @param list<string> $denied */
    private function member(string $projectRole, array $denied = [], array $tenantPermissions = ['projects.view', 'campaigns.view', 'analytics.view', 'reports.view', 'leads.view', 'tasks.view']): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.uniqid()]);
        $role->givePermissionTo(...$tenantPermissions);
        $user = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => $projectRole, 'status' => 'active', 'denied' => $denied,
        ]);

        return $user;
    }

    public function test_the_money_is_stripped_from_every_project_answer_for_a_member_whose_membership_withholds_it(): void
    {
        $buyer = $this->member(ProjectRole::MEDIA_BUYER, [ProjectCapability::MONEY_VIEW]);
        $peer = $this->member(ProjectRole::MEDIA_BUYER);

        foreach (['metrics/summary', 'metrics/campaigns', 'metrics/platforms'] as $path) {
            $withheld = $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/{$path}?from=2026-08-01&to=2026-08-31")->assertOk();
            $this->assertTrue($withheld->json('meta.money_withheld'), "{$path} did not say the money was withheld");
            $this->assertDoesNotMatchRegularExpression('/"(spend|revenue|roas|cpa|cpc|cpm|budget)"/', $withheld->getContent(), "{$path} leaked a money key to a member whose membership withholds money");

            $full = $this->actingAs($peer, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/{$path}?from=2026-08-01&to=2026-08-31")->assertOk();
            $this->assertNull($full->json('meta.money_withheld'), 'a peer with the same role but nothing withheld was redacted');
            if ($path === 'metrics/summary') {
                // Non-vacuous: the summary always names its money keys, so the stripping above removed something real.
                $this->assertStringContainsString('"spend"', $full->getContent(), 'the summary serves no spend at all, so the withholding above proved nothing');
            }
        }
    }

    public function test_an_export_is_refused_for_a_member_whose_membership_withholds_money(): void
    {
        $buyer = $this->member(ProjectRole::MARKETING_MANAGER, [ProjectCapability::MONEY_VIEW]);
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports/00000000-0000-0000-0000-000000000000/export", ['format' => 'csv'])
            ->assertForbidden();
    }

    public function test_a_tenant_administrator_cannot_be_narrowed_and_the_deny_list_is_catalogue_names_only(): void
    {
        $admin = $this->member(ProjectRole::VIEWER, [ProjectCapability::MONEY_VIEW], ['settings.manage', 'projects.view', 'analytics.view']);
        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?from=2026-08-01&to=2026-08-31")->assertOk();
        $this->assertNull($res->json('meta.money_withheld'));

        $manager = $this->member(ProjectRole::MARKETING_MANAGER, [], ['projects.view', 'analytics.view', 'users.update', 'users.invite']);
        $target = $this->member(ProjectRole::MEDIA_BUYER);
        $membership = ProjectMembership::withoutGlobalScopes()->where('user_id', $target->id)->firstOrFail();

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/projects/{$this->project->id}/team/{$membership->id}", ['denied' => ['money.view', 'not.a.capability']])
            ->assertStatus(422)->assertJsonValidationErrors(['denied.1']);
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/projects/{$this->project->id}/team/{$membership->id}", ['denied' => ['money.view']])
            ->assertOk();
        $this->assertSame(['money.view'], $membership->refresh()->denied);
        $rows = collect($this->actingAs($manager, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/team")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['money.view'], $rows[(string) $membership->id]['denied'], 'the team list does not say what the membership withholds');
    }

    /** The effective-access preview says what the member may actually do — role, extras and deny list resolved. */
    public function test_the_effective_access_preview_resolves_role_extras_and_the_deny_list(): void
    {
        $manager = $this->member(ProjectRole::MARKETING_MANAGER, [], ['projects.view', 'analytics.view', 'users.update', 'users.invite']);
        $buyer = $this->member(ProjectRole::MEDIA_BUYER, [ProjectCapability::MONEY_VIEW, ProjectCapability::LEADS_PII_VIEW]);
        $membership = ProjectMembership::withoutGlobalScopes()->where('user_id', $buyer->id)->firstOrFail();

        $res = $this->actingAs($manager, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/team/{$membership->id}/effective")->assertOk();
        $this->assertSame('media_buyer', $res->json('data.role'));
        $this->assertSame(['money.view', 'leads.pii.view'], $res->json('data.denied'));
        $this->assertContains('campaigns.manage', $res->json('data.effective'));
        $this->assertNotContains('money.view', $res->json('data.effective'));
        $this->assertContains('money.view', $res->json('data.withheld'));
        $this->assertContains('leads.pii.view', $res->json('data.withheld'));

        $viewer = $this->member(ProjectRole::VIEWER);
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/team/{$membership->id}/effective")->assertForbidden();
    }

    public function test_the_key_rule_strips_money_and_leaves_results_alone(): void
    {
        $stripped = WithholdMoneyForMember::strip(['spend' => 1, 'revenue' => 2, 'roas' => 3, 'cpa' => 4, 'daily_budget' => 5, 'cost_per_result' => 6, 'impressions' => 7, 'conversions' => 8, 'nested' => ['spend_original' => 9, 'clicks' => 10, 'rows' => [['cpm' => 1, 'ctr' => 0.1]]]]);
        $this->assertSame(['impressions' => 7, 'conversions' => 8, 'nested' => ['clicks' => 10, 'rows' => [['ctr' => 0.1]]]], $stripped);
    }
}
