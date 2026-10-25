<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsReportAccount;
use Tests\TestCase;

/**
 * REPORT-IDENTITY-NAMES-001 — «prepared for» and «prepared by» are the operator's to type, per report.
 *
 * Production defect: the builder printed the stored client's name under «مقدم إلى» and offered no way to change it, nor the company's.
 * Now each report can carry its own two names; blank falls back to the stored ones; every surface that resolves the identity (the
 * shared link's branding, the live link, the print/PDF route and the share card all call one resolver) returns them, and the stored
 * client and company records are never renamed.
 */
final class ReportIdentityNamesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReportAccount;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency Co', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'CampaignsHub Advertiser', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);
    }

    private function create(array $extra = []): string
    {
        $res = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports", [
            'name' => 'Weekly', 'type' => 'executive', 'audience' => 'client', 'scope' => $this->reportScopeFor((string) $this->project->id), 'period_start' => '2026-10-01', 'period_end' => '2026-10-07',
        ] + $extra);
        $this->assertSame(201, $res->status(), $res->getContent());

        return (string) $res->json('data.id');
    }

    /** @return array<string,mixed> what a shared link's header would say for this report */
    private function branding(string $reportId): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::withoutGlobalScopes()->findOrFail($reportId);
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true], null);
        $json = $this->getJson("/api/v1/reports/shared/{$raw}/branding")->assertOk()->json('data');
        $this->assertNotNull($share);

        return $json;
    }

    public function test_a_report_without_names_prints_the_stored_ones_exactly_as_before(): void
    {
        $b = $this->branding($this->create());

        $this->assertSame('Agency Co', $b['agency']['name']);
        $this->assertSame('CampaignsHub Advertiser', $b['client']['name']);
        $this->assertSame('CampaignsHub Advertiser', $b['name']);
        $this->assertSame('Agency Co', $b['by']);
    }

    public function test_names_typed_at_creation_are_saved_and_printed_in_arabic_and_english(): void
    {
        $id = $this->create(['prepared_for_name' => 'شركة النخيل التجارية', 'prepared_by_name' => 'Hanan Digital Studio']);

        $this->assertEquals(['prepared_for_name' => 'شركة النخيل التجارية', 'prepared_by_name' => 'Hanan Digital Studio'], Report::withoutGlobalScopes()->find($id)->config['branding']);
        $b = $this->branding($id);
        $this->assertSame('شركة النخيل التجارية', $b['client']['name']);
        $this->assertSame('Hanan Digital Studio', $b['agency']['name']);
        $this->assertSame('شركة النخيل التجارية', $b['name']);
        $this->assertSame('Hanan Digital Studio', $b['by']);
    }

    public function test_the_two_names_are_independent(): void
    {
        $onlyFor = $this->branding($this->create(['prepared_for_name' => 'Custom Client']));
        $this->assertSame('Custom Client', $onlyFor['client']['name']);
        $this->assertSame('Agency Co', $onlyFor['agency']['name'], 'the preparer was not touched');

        $onlyBy = $this->branding($this->create(['prepared_by_name' => 'Custom Studio']));
        $this->assertSame('Custom Studio', $onlyBy['agency']['name']);
        $this->assertSame('CampaignsHub Advertiser', $onlyBy['client']['name'], 'the client was not touched');
    }

    public function test_a_blank_name_falls_back_to_the_stored_name_and_stores_nothing(): void
    {
        $id = $this->create(['prepared_for_name' => '   ', 'prepared_by_name' => '']);

        $this->assertArrayNotHasKey('branding', (array) Report::withoutGlobalScopes()->find($id)->config);
        $b = $this->branding($id);
        $this->assertSame('CampaignsHub Advertiser', $b['client']['name']);
        $this->assertSame('Agency Co', $b['agency']['name']);
    }

    public function test_reopening_changes_the_names_and_clearing_them_returns_to_the_stored_ones_without_touching_the_rest_of_the_config(): void
    {
        $id = $this->create(['prepared_for_name' => 'First', 'branding_identity' => 'agency', 'config' => ['columns' => ['platforms' => ['spend']]]]);

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/projects/{$this->project->id}/reports/{$id}", ['prepared_for_name' => 'Second', 'prepared_by_name' => 'Studio'])->assertOk();
        $config = (array) Report::withoutGlobalScopes()->find($id)->config;
        $this->assertSame('Second', $config['branding']['prepared_for_name']);
        $this->assertSame('Studio', $config['branding']['prepared_by_name']);
        $this->assertSame('agency', $config['branding']['prefer'], 'the identity choice survives a name edit');
        $this->assertArrayHasKey('columns', $config, 'so does everything else in the config');

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/projects/{$this->project->id}/reports/{$id}", ['prepared_for_name' => '', 'prepared_by_name' => null])->assertOk();
        $config = (array) Report::withoutGlobalScopes()->find($id)->config;
        $this->assertArrayNotHasKey('prepared_for_name', $config['branding']);
        $this->assertArrayNotHasKey('prepared_by_name', $config['branding']);
        $this->assertSame('agency', $config['branding']['prefer']);
        $this->assertSame('CampaignsHub Advertiser', $this->branding($id)['client']['name']);
    }

    public function test_the_stored_client_and_company_records_are_never_renamed(): void
    {
        $id = $this->create(['prepared_for_name' => 'Printed Only', 'prepared_by_name' => 'Printed Only Too']);
        $this->branding($id);

        $this->assertSame('CampaignsHub Advertiser', ClientWorkspace::withoutGlobalScopes()->find($this->client->id)->name);
        $this->assertSame('Agency Co', Tenant::query()->find($this->tenant->id)->name);
    }

    public function test_the_agency_leading_choice_uses_the_typed_names_too(): void
    {
        $b = $this->branding($this->create(['branding_identity' => 'agency', 'prepared_by_name' => 'Studio X', 'prepared_for_name' => 'Client Y']));

        $this->assertSame('Studio X', $b['name'], 'the preparer leads');
        $this->assertSame('Client Y', $b['by'], 'and the secondary line names the client as typed');
    }

    public function test_the_print_and_share_resolver_agrees_with_the_header(): void
    {
        $id = $this->create(['prepared_for_name' => 'Print Client', 'prepared_by_name' => 'Print Studio']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::withoutGlobalScopes()->findOrFail($id);
        $resolved = app(SharedLinkBranding::class)->forReport($report, (string) $this->tenant->id, fn (?string $r = null): string => '/x/'.($r ?? 'auto'));

        $this->assertSame('Print Client', $resolved['client']['name']);
        $this->assertSame('Print Studio', $resolved['agency']['name']);
    }

    public function test_a_name_is_bounded_and_a_live_link_accepts_the_same_two_fields(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports", [
            'name' => 'W', 'type' => 'executive', 'audience' => 'client', 'scope' => $this->reportScopeFor((string) $this->project->id), 'prepared_for_name' => str_repeat('ا', 121),
        ])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/projects/{$this->project->id}/reports/{$this->create()}", ['prepared_by_name' => str_repeat('x', 121)])->assertStatus(422);
    }

    public function test_another_tenants_report_cannot_be_renamed(): void
    {
        $id = $this->create();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $spy = User::create(['name' => 'S', 'email' => 's@x.test', 'password' => 'secret123']);
        $this->grantMembership($spy, $other, Portal::Agency);
        $spy->assignRole($role);
        app(TenantContext::class)->forget();

        $res = $this->actingAs($spy, 'sanctum')->patchJson("/api/v1/projects/{$this->project->id}/reports/{$id}", ['prepared_for_name' => 'Hijacked']);
        $this->assertContains($res->status(), [403, 404]);
        $this->assertArrayNotHasKey('branding', (array) Report::withoutGlobalScopes()->find($id)->config);
    }
}
