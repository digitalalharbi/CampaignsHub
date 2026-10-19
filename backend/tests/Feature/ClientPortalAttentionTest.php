<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CP-DASH-001 — «what is waiting on me» on the client's dashboard: counted from the records that say so,
 * present only where the client may see that section, and never a zero standing in for «not yours».
 */
final class ClientPortalAttentionTest extends TestCase
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
        Carbon::setTestNow('2026-10-17 09:00:00');
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Bravo', 'status' => 'active']);

        foreach ([[$this->a, 'waiting_client'], [$this->a, 'waiting_client'], [$this->b, 'waiting_client'], [$this->a, 'new']] as $i => [$project, $status]) {
            ExternalRequest::create([
                'tenant_id' => $this->tenant->id, 'reference' => 'REQ-2026-A'.$i.'ZZZZ',
                'type_id' => RequestType::query()->firstOrFail()->id, 'status_id' => RequestStatus::where('key', $status)->firstOrFail()->id,
                'contact_name' => 'C', 'contact_email' => 'c@x.test', 'client_id' => $this->workspace->id, 'project_id' => $project->id, 'submitted_at' => now(),
            ]);
        }
        foreach ([['INV-1', 'issued', '2026-10-01'], ['INV-2', 'sent', '2026-10-30'], ['INV-3', 'paid', '2026-09-01'], ['INV-4', 'draft', '2026-09-01']] as [$number, $status, $due]) {
            Invoice::create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => $number, 'currency' => 'SAR',
                'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'amount_paid' => $status === 'paid' ? 115 : 0, 'status' => $status, 'due_date' => $due,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(?array $projects = null): User
    {
        $user = User::create(['name' => 'Client', 'email' => 'c'.uniqid().'@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->workspace->id], projectScopeIds: $projects,
        ));

        return $user;
    }

    public function test_the_overview_counts_what_is_waiting_on_the_client(): void
    {
        $this->actingAs($this->user());

        $attention = $this->getJson('/api/v1/client/overview', $this->spa)->assertOk()->json('data.attention');

        $this->assertSame(3, $attention['requests_waiting']);
        $this->assertSame(1, $attention['invoices_overdue'], 'issued and past due; a draft or paid invoice is not waiting');
        $this->assertSame(1, $attention['invoices_due'], 'sent and not yet due');
    }

    public function test_a_project_restricted_user_counts_their_projects_requests_and_has_no_invoice_figures(): void
    {
        $this->actingAs($this->user([(string) $this->a->id]));

        $attention = $this->getJson('/api/v1/client/overview', $this->spa)->assertOk()->json('data.attention');

        $this->assertSame(2, $attention['requests_waiting']);
        $this->assertArrayNotHasKey('invoices_overdue', $attention, 'client-wide paperwork is not a project-confined user\'s');
    }

    public function test_a_section_the_agency_switched_off_has_no_count(): void
    {
        $this->workspace->forceFill(['settings' => ['portal' => ['visibility' => ['invoices' => false, 'requests' => false]]]])->save();
        $this->actingAs($this->user());

        $this->getJson('/api/v1/client/overview', $this->spa)->assertOk()->assertJsonPath('data.attention', []);
    }
}
