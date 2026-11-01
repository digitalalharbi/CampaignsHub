<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * SEC-3 — a client team member confined to some of a client's projects (client_workspace_user.project_ids)
 * reads only those on the internal analytics, the agency overview and the client budgets.
 */
final class ClientTeamProjectCeilingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private ClientWorkspace $client;

    private Project $p1;

    private Project $p2;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Ag', 'slug' => 'ag-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);
        $this->client = ClientWorkspace::create(['tenant_id' => $this->agency->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        [$this->p1, $c1] = $this->projectWithCampaign('P1');
        [$this->p2, $c2] = $this->projectWithCampaign('P2');
        $this->spend($c1, 100);
        $this->spend($c2, 900);

        $this->member = User::create(['name' => 'M', 'email' => 'm@ag.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo('clients.view', 'clients.view_analytics', 'budget.view');
        $this->member->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $this->member, tenant: $this->agency, portal: Portal::Agency, role: 'member'));
        DB::table('client_workspace_user')->insert([
            'client_workspace_id' => $this->client->id, 'user_id' => $this->member->id, 'access_role' => 'viewer',
            'project_ids' => json_encode([(string) $this->p1->id]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_client_analytics_covers_only_the_projects_the_member_is_confined_to(): void
    {
        $json = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/v1/app/clients/{$this->client->id}/analytics?from=2026-06-01&to=2026-06-30")
            ->assertOk()->json('data');

        $this->assertStringNotContainsString('P2', (string) json_encode($json));
        $this->assertStringContainsString('P1', (string) json_encode($json));
    }

    public function test_the_agency_overview_counts_only_the_confined_projects(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/agency/overview?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.scope.project_count', 1)
            ->assertJsonPath('data.current.spend', 100);
    }

    public function test_the_client_budgets_name_only_the_confined_projects(): void
    {
        $json = $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/agency/client-budgets?from=2026-06-01&to=2026-06-30')
            ->assertOk()->json();

        $this->assertStringNotContainsString('P2', (string) json_encode($json));
    }

    public function test_a_member_without_a_restriction_still_reads_every_project(): void
    {
        DB::table('client_workspace_user')->where('user_id', $this->member->id)->update(['project_ids' => null]);

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/agency/overview?from=2026-06-01&to=2026-06-30')
            ->assertOk()->assertJsonPath('data.scope.project_count', 2)->assertJsonPath('data.current.spend', 1000);
    }

    public function test_a_malformed_restriction_reads_nothing_rather_than_everything(): void
    {
        DB::table('client_workspace_user')->where('user_id', $this->member->id)->update(['project_ids' => '"not-a-list"']);

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/agency/overview?from=2026-06-01&to=2026-06-30')
            ->assertOk()->assertJsonPath('data.scope.project_count', 0);
    }

    /** @return array{0: Project, 1: UnifiedCampaign} */
    private function projectWithCampaign(string $name): array
    {
        $p = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->client->id, 'name' => $name, 'status' => 'active']);
        $c = UnifiedCampaign::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->client->id, 'project_id' => $p->id, 'name' => 'Camp '.$name, 'objective' => 'sales', 'status' => 'active', 'total_budget' => 500, 'budget_currency' => 'SAR']);

        return [$p, $c];
    }

    private function spend(UnifiedCampaign $campaign, float $spend): void
    {
        $uid = fn (string $s) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $s.$campaign->id);
        $m = fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->agency->id, projectId: $campaign->project_id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: 'meta', metricKey: $k, metricDate: Carbon::parse('2026-06-10'),
            value: $v, unifiedCampaignId: $campaign->id, projectCurrency: 'SAR',
        );
        app(UpsertDailyMetrics::class)->handle([$m('impressions', 1000), $m('clicks', 50), $m('conversions', 5), $m('spend', $spend), $m('revenue', $spend * 3)]);
    }
}
