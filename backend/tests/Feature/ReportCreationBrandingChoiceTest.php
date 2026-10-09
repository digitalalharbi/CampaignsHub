<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * REPORT-CREATION-UX-001 — the branding choice made at creation reaches the produced report.
 *
 * The row's acceptance is «a test that each choice reaches the produced report». Mode and form had
 * theirs (`ReportFormTest`); the branding identity — the row's own «last clause», accepted by
 * `ReportController::store` as `branding_identity` and frozen into `config.branding.prefer` — had
 * none. Nothing in the suite mentioned the field. A choice the endpoint accepts and no test follows
 * to the surface is a choice that can stop working without anybody being told, which is the exact
 * failure the Owner reported about this screen: «changing settings does not change the product».
 *
 * So each case here starts at the creation endpoint — not at the model — and ends at the identity
 * the shared document actually resolves, through the one resolver every surface reads
 * ({@see SharedLinkBranding}). The hierarchy's default is asserted too, because «absent means what
 * every report created before the choice existed means» is a promise, and a promise with no test
 * is a comment.
 */
final class ReportCreationBrandingChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private User $owner;

    private ClientWorkspace $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);

        $this->agency = Tenant::create(['name' => 'Razah Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);

        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->agency);
        $this->owner->assignRole($role);

        $this->client = ClientWorkspace::create(['name' => 'Nakheel', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    /** Create through the endpoint, exactly as the builder does, and hand back the stored row. */
    private function create(array $body): Report
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", ['name' => 'R', 'type' => 'executive'] + $body)
            ->assertCreated()
            ->json('data.id');

        Queue::assertPushed(GenerateReportJob::class);

        return Report::withoutGlobalScopes()->findOrFail($id);
    }

    /** What the document would say about itself — the resolver every surface reads. */
    private function identity(Report $report): array
    {
        return app(SharedLinkBranding::class)->forReport($report, (string) $this->agency->id, static fn (?string $role = null): string => '/logo/'.($role ?? 'nearest'));
    }

    public function test_choosing_the_agency_puts_the_agency_first_and_the_client_second(): void
    {
        $report = $this->create(['branding_identity' => 'agency']);

        $this->assertSame('agency', $report->config['branding']['prefer'] ?? null, 'the choice was not frozen into the report');

        $identity = $this->identity($report);
        $this->assertSame('Razah Agency', $identity['name']);
        $this->assertSame('Nakheel', $identity['by'], 'with the agency leading, the secondary line names the client');
    }

    public function test_choosing_the_client_puts_the_client_first_and_the_agency_second(): void
    {
        $report = $this->create(['branding_identity' => 'client']);

        $this->assertSame('client', $report->config['branding']['prefer'] ?? null);

        $identity = $this->identity($report);
        $this->assertSame('Nakheel', $identity['name']);
        $this->assertSame('Razah Agency', $identity['by']);
    }

    /**
     * Absent means what every report created before the choice existed means.
     *
     * The hierarchy decides — a report about a client resolves at the client layer — and nothing is
     * written into the config, so a reader of the stored row can tell «not chosen» from «chose the
     * client», which are different facts.
     */
    public function test_no_choice_leaves_the_hierarchy_to_decide_and_records_no_preference(): void
    {
        $report = $this->create([]);

        $this->assertArrayNotHasKey('prefer', (array) ($report->config['branding'] ?? []), 'an unmade choice was written as if it had been made');
        $this->assertSame('Nakheel', $this->identity($report)['name']);
    }

    /**
     * A layer that does not exist is refused, not silently read as «whatever the hierarchy says».
     *
     * Asserted on the FIELD, not the status. The first version of this case passed while the fixture's
     * `type` was invalid: a 422 for the wrong reason is indistinguishable from the right one by status
     * alone, and a guard that passes for a reason it does not check is not a guard.
     */
    public function test_an_unknown_identity_is_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", ['name' => 'R', 'type' => 'executive', 'branding_identity' => 'platform'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branding_identity']);
    }

    /**
     * The surface, not the resolver: a link shared from an agency-led report says the agency.
     *
     * The resolver cases above would still pass if a controller between the share and the resolver
     * dropped the report, which is precisely the kind of plumbing that breaks when the surface is
     * refactored. The branding endpoint is what the client's browser asks.
     */
    public function test_the_choice_reaches_the_shared_document(): void
    {
        $report = $this->create(['branding_identity' => 'agency']);

        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        [, $token] = app(ShareService::class)->create($report, ['scope' => ['project_id' => (string) $this->project->id], 'mode' => 'live'], null);
        app(TenantContext::class)->forget();

        $body = $this->getJson("/api/v1/reports/shared/{$token}/branding")->assertOk()->json('data');

        $this->assertSame('Razah Agency', $body['name'] ?? null, 'the shared document did not lead with the identity chosen at creation');
        $this->assertSame('Nakheel', $body['client']['name'] ?? null, 'the client still appears beside it');
    }
}
