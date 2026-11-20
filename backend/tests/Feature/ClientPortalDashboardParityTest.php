<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\PortalMetricsFilter;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLIENT-DASHBOARD-PARITY-001 — the client dashboard reads the agency dashboard's own answers, for the client's own
 * projects only, under the agency's switches for the space.
 */
final class ClientPortalDashboardParityTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $a;

    private Project $b;

    private Project $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $other = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Henka', 'slug' => 'henka', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Bravo', 'status' => 'active']);
        $this->foreign = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $other->id, 'name' => 'Other', 'status' => 'active']);
    }

    private function client(?array $projects = null): User
    {
        $user = User::create(['name' => 'Client', 'email' => 'c'.uniqid().'@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_viewer',
            clientScopeIds: [(string) $this->workspace->id], projectScopeIds: $projects,
        ));

        return $user;
    }

    private function show(array $visibility): void
    {
        $this->workspace->forceFill(['settings' => ['portal' => ['visibility' => $visibility]]])->save();
    }

    private function url(Project $p, string $name): string
    {
        return "/api/v1/client/projects/{$p->id}/metrics/{$name}?from=2026-11-01&to=2026-11-20";
    }

    public function test_a_client_reads_its_own_projects_summary_without_money_by_default(): void
    {
        $this->actingAs($this->client());

        $r = $this->getJson($this->url($this->a, 'summary'), $this->spa)->assertOk();
        $current = (array) $r->json('data.current');
        $this->assertArrayHasKey('impressions', $current);
        $this->assertArrayNotHasKey('spend', $current);
        $this->assertArrayNotHasKey('revenue', $current);
        $this->assertArrayNotHasKey('roas', $current);
        $this->assertArrayNotHasKey('cpa', $current);
    }

    public function test_money_reaches_the_client_only_where_the_agency_switched_it_on(): void
    {
        $this->show(['spend' => true]);
        $this->actingAs($this->client());

        $current = (array) $this->getJson($this->url($this->a, 'summary'), $this->spa)->assertOk()->json('data.current');
        $this->assertArrayHasKey('spend', $current);
        $this->assertArrayNotHasKey('revenue', $current);
        $this->assertArrayNotHasKey('roas', $current);
    }

    public function test_another_clients_project_a_restricted_users_other_project_and_unknown_answers_are_refused(): void
    {
        $this->actingAs($this->client([(string) $this->a->id]));

        $this->getJson($this->url($this->a, 'timeseries'), $this->spa)->assertOk();
        $this->getJson($this->url($this->b, 'summary'), $this->spa)->assertNotFound();
        $this->getJson($this->url($this->foreign, 'summary'), $this->spa)->assertNotFound();
        $this->getJson($this->url($this->a, 'budget'), $this->spa)->assertNotFound();
        $this->getJson($this->url($this->a, 'accounts'), $this->spa)->assertNotFound();
    }

    public function test_campaign_names_and_a_switched_off_dashboard_follow_the_agencys_switches(): void
    {
        $this->show(['campaign_names' => false]);
        $this->actingAs($this->client());
        $this->getJson($this->url($this->a, 'campaigns'), $this->spa)->assertForbidden();

        $this->show(['dashboard' => false]);
        $this->getJson($this->url($this->a, 'summary'), $this->spa)->assertNotFound();
    }

    public function test_a_signed_out_visitor_reads_nothing(): void
    {
        $this->getJson($this->url($this->a, 'summary'), $this->spa)->assertUnauthorized();
    }

    public function test_the_filter_removes_money_at_every_depth_and_never_sends_technical_ids(): void
    {
        $out = PortalMetricsFilter::apply([
            'current' => ['spend' => 5, 'impressions' => 9, 'cost_per_result' => 1, 'revenue' => 3, 'roas' => 2],
            'rows' => [['name' => 'x', 'external_id' => '123', 'spend_usd' => 4, 'clicks' => 1]],
        ], ['spend' => false, 'revenue' => false]);

        $this->assertSame(['current' => ['impressions' => 9], 'rows' => [['name' => 'x', 'clicks' => 1]]], $out);
    }
}
