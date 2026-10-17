<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INV-PROFILE-001, INV-LIFECYCLE-001 — the agency's HTTP surface for the client fee invoice: profile, draft,
 * review, issue, send, payment, correction — permission-gated and scoped to clients the operator can reach.
 */
final class ClientInvoicesApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $readonly;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $viewRole->givePermissionTo('clients.view', 'clients.view_all', 'billing.view');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->readonly = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->readonly, $this->tenant, Portal::Agency);
        $this->readonly->assignRole($viewRole);

        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Store', 'status' => 'active']);
    }

    private function profileBody(array $over = []): array
    {
        return $over + [
            'fee_model' => 'fixed', 'fixed_amount' => 1000, 'cadence' => 'weekly', 'currency' => 'sar',
            'tax_treatment' => 'basic_15', 'due_days' => 7,
            'bill_to' => ['legal_name' => 'Razzah Co.'], 'payment_details' => ['iban' => 'SA03'],
        ];
    }

    public function test_the_whole_lifecycle_runs_through_the_api_and_review_comes_before_issue(): void
    {
        $base = "/api/v1/billing/clients/{$this->workspace->id}";
        $this->actingAs($this->owner, 'sanctum');

        $this->putJson("{$base}/profile", $this->profileBody())->assertOk()->assertJsonPath('data.currency', 'SAR');
        $draft = $this->postJson("{$base}/invoices/draft", ['from' => '2026-10-11', 'to' => '2026-10-17'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '1150.00');
        $id = $draft->json('data.id');

        $this->postJson("/api/v1/billing/invoices/{$id}/issue")->assertStatus(409);           // not reviewed
        $this->postJson("/api/v1/billing/invoices/{$id}/review")->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->postJson("/api/v1/billing/invoices/{$id}/issue")->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.display_status', 'due');
        $this->postJson("/api/v1/billing/invoices/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/v1/billing/invoices/{$id}/payment", ['amount' => 1150])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/billing/invoices/{$id}/correct", ['reason' => 'x'])->assertStatus(409);   // a paid invoice is not corrected here
    }

    public function test_a_correction_voids_the_original_and_returns_a_superseding_draft(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->putJson("/api/v1/billing/clients/{$this->workspace->id}/profile", $this->profileBody())->assertOk();
        $id = $this->postJson("/api/v1/billing/clients/{$this->workspace->id}/invoices/draft", ['from' => '2026-10-11', 'to' => '2026-10-17'])->json('data.id');
        $this->postJson("/api/v1/billing/invoices/{$id}/review");
        $this->postJson("/api/v1/billing/invoices/{$id}/issue");

        $copy = $this->postJson("/api/v1/billing/invoices/{$id}/correct", ['reason' => 'Wrong amount'])->assertOk()->assertJsonPath('data.status', 'draft');

        $this->assertSame('void', Invoice::withoutGlobalScopes()->find($id)->status);
        $this->assertSame($id, $copy->json('data.supersedes_id'));
    }

    public function test_a_draft_needs_a_profile_and_a_read_only_member_cannot_manage_billing(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/billing/clients/{$this->workspace->id}/invoices/draft", ['from' => '2026-10-11', 'to' => '2026-10-17'])->assertStatus(422);

        $this->actingAs($this->readonly, 'sanctum');
        $this->putJson("/api/v1/billing/clients/{$this->workspace->id}/profile", $this->profileBody())->assertForbidden();
        $this->postJson("/api/v1/billing/clients/{$this->workspace->id}/invoices/draft", ['from' => '2026-10-11', 'to' => '2026-10-17'])->assertForbidden();
    }

    public function test_the_historical_five_percent_rate_is_refused_for_a_new_profile_and_a_percentage_needs_its_number(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $url = "/api/v1/billing/clients/{$this->workspace->id}/profile";

        $this->putJson($url, $this->profileBody(['tax_treatment' => 'historical_5']))->assertStatus(422);
        $this->putJson($url, $this->profileBody(['fee_model' => 'percentage', 'fixed_amount' => null]))->assertStatus(422);
    }

    public function test_the_pdf_is_a_download_for_an_operator_who_may_read_billing(): void
    {
        if (! config('reports.chromium.enabled') || ! is_dir(base_path('../frontend/node_modules/playwright-core'))) {
            $this->markTestSkipped('Chromium PDF rendering is not available in this environment (not enabled, or playwright-core is not installed).');
        }

        $this->actingAs($this->owner, 'sanctum');
        $this->putJson("/api/v1/billing/clients/{$this->workspace->id}/profile", $this->profileBody())->assertOk();
        $id = $this->postJson("/api/v1/billing/clients/{$this->workspace->id}/invoices/draft", ['from' => '2026-10-11', 'to' => '2026-10-17'])->json('data.id');

        $res = $this->actingAs($this->readonly, 'sanctum')->get("/api/v1/billing/invoices/{$id}/pdf?lang=en")->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
