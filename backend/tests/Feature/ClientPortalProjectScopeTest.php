<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CP-USERS-001, CP-ISOLATION-001 — a client user limited to one project reads that project and nothing else
 * of the client: not its other projects' campaigns, reports or figures, and not the client-wide paperwork.
 */
final class ClientPortalProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $a;

    private Project $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Alpha Brand', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Bravo Brand', 'status' => 'active']);
        foreach ([[$this->a, 'Alpha campaign'], [$this->b, 'Bravo campaign']] as [$project, $name]) {
            UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $this->workspace->id, 'name' => $name, 'objective' => 'conversions', 'status' => 'active']);
            $report = Report::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $name.' report', 'type' => 'executive', 'audience' => 'client', 'status' => 'completed']);
            ReportShare::create(['tenant_id' => $this->tenant->id, 'report_id' => $report->id, 'token_hash' => hash('sha256', Str::random(48)), 'allow_download' => true]);
        }
        Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'INV-S-1', 'currency' => 'SAR', 'subtotal' => 1, 'tax' => 0, 'total' => 1, 'line_items' => [], 'amount_paid' => 0, 'status' => 'issued']);
    }

    private function user(string $email, ?array $projects): User
    {
        $user = User::create(['name' => 'Client', 'email' => $email, 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_viewer',
            clientScopeIds: [(string) $this->workspace->id], projectScopeIds: $projects,
        ));

        return $user;
    }

    public function test_a_project_restricted_user_reads_only_their_projects_campaigns_and_reports(): void
    {
        $this->actingAs($this->user('a@x.test', [(string) $this->a->id]));

        $campaigns = $this->getJson('/api/v1/client/campaigns', $this->spa)->assertOk();
        $this->assertSame(['Alpha campaign'], array_column($campaigns->json('data.campaigns'), 'name'));
        $this->assertStringNotContainsString('Bravo', $campaigns->getContent());

        $reports = $this->getJson('/api/v1/client/reports', $this->spa)->assertOk();
        $this->assertCount(1, $reports->json('data.reports'));
        $this->assertStringNotContainsString('Bravo', $reports->getContent());
    }

    public function test_an_unrestricted_user_of_the_same_client_still_reads_everything(): void
    {
        $this->actingAs($this->user('all@x.test', null));

        $this->getJson('/api/v1/client/campaigns', $this->spa)->assertOk()->assertJsonCount(2, 'data.campaigns');
        $this->getJson('/api/v1/client/reports', $this->spa)->assertOk()->assertJsonCount(2, 'data.reports');
    }

    public function test_client_wide_paperwork_is_not_a_project_restricted_users(): void
    {
        $this->actingAs($this->user('a@x.test', [(string) $this->a->id]));
        $invoiceId = Invoice::withoutGlobalScopes()->value('id');

        $this->getJson('/api/v1/client/invoices', $this->spa)->assertOk()->assertJsonCount(0, 'data.invoices');
        $this->getJson("/api/v1/client/invoices/{$invoiceId}", $this->spa)->assertNotFound();
        $this->getJson('/api/v1/client/quotes', $this->spa)->assertOk()->assertJsonCount(0, 'data.quotes');
        $this->getJson('/api/v1/client/messages', $this->spa)->assertOk()->assertJsonCount(0, 'data.threads');
    }

    public function test_the_overview_of_a_restricted_user_counts_only_their_projects(): void
    {
        $this->actingAs($this->user('a@x.test', [(string) $this->a->id]));

        $res = $this->getJson('/api/v1/client/overview', $this->spa)->assertOk();

        $this->assertSame(['Alpha Brand'], array_column($res->json('data.projects'), 'name'));
        $this->assertSame(1, $res->json('data.campaigns.total'));
        $this->assertStringNotContainsString('Bravo', $res->getContent());
    }
}
