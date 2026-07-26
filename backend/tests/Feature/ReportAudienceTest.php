<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ClientReportContentValidator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Audience isolation: internal reports cannot be shared; the client print response carries no internal data. */
final class ReportAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function report(string $audience): Report
    {
        return Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'audience' => $audience,
            'status' => 'completed', 'currency' => 'SAR',
            'data' => [
                'currency' => 'SAR', 'checksum' => str_repeat('a', 40), 'tenant_id' => $this->tenant->id,
                'kpis' => ['spend' => 100, 'revenue' => 400, 'conversions' => 5, 'roas' => 4.0, 'cpa' => 20.0],
                'platforms' => [['provider' => 'meta', 'spend' => 100, 'revenue' => 400, 'conversions' => 5]],
                'campaigns' => [['campaign_name' => 'Meta — Lead Gen (burner)', 'provider' => 'meta', 'spend' => 100]],
                'recommendations' => [['id' => 'x', 'title' => 'زيادة الميزانية', 'status' => 'draft', 'type' => 'recommendation']],
            ],
        ]);
    }

    public function test_internal_report_cannot_be_shared(): void
    {
        Sanctum::actingAs($this->owner);
        $internal = $this->report('internal');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$internal->id}/shares", [])
            ->assertStatus(422);

        // A client report can be shared.
        $client = $this->report('client');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$client->id}/shares", [])
            ->assertStatus(201);
    }

    public function test_authenticated_client_export_is_filtered_but_internal_is_full(): void
    {
        $exporter = app(\App\Domains\Reports\Services\ReportExporter::class);

        // Client CSV: internal marker sanitised, draft recommendation dropped — even for an admin export.
        $clientCsv = $exporter->render($this->report('client'), 'csv');
        $this->assertStringNotContainsStringIgnoringCase('burner', $clientCsv);

        // Internal CSV: full snapshot, internal campaign name retained for the team.
        $internalCsv = $exporter->render($this->report('internal'), 'csv');
        $this->assertStringContainsStringIgnoringCase('burner', $internalCsv);
    }

    public function test_client_print_response_has_no_internal_data(): void
    {
        $client = $this->report('client');
        $token = 'clienttok';
        Cache::put('report-print:'.hash('sha256', $token), ['report_id' => (string) $client->id, 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client'], 300);

        $res = $this->getJson("/api/v1/reports/print/{$token}")->assertOk();
        $body = $res->json('data.data');

        // Internal fields removed from the client body; draft rec dropped; internal name sanitised.
        $this->assertArrayNotHasKey('checksum', $body);
        $this->assertArrayNotHasKey('tenant_id', $body);
        $this->assertCount(0, $body['recommendations']);
        $this->assertStringNotContainsStringIgnoringCase('burner', json_encode($body, JSON_UNESCAPED_UNICODE));
        // Content validator confirms a clean client body.
        $this->assertTrue(app(ClientReportContentValidator::class)->passes($body));
    }
}
