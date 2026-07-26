<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Print pipeline: a token is only issued for a report that passes the readiness gate, and the public
 * print-data endpoint serves the snapshot for a valid token and 404s for a bad/expired one.
 */
final class ReportPrintTest extends TestCase
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

    private function report(array $data): Report
    {
        return Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive',
            'status' => 'completed', 'currency' => 'SAR', 'data' => $data,
        ]);
    }

    private function validSnapshot(): array
    {
        return [
            'currency' => 'SAR', 'kpis' => ['spend' => 100, 'revenue' => 400, 'conversions' => 5, 'roas' => 4.0, 'cpa' => 20.0],
            'platforms' => [['provider' => 'meta', 'spend' => 100, 'revenue' => 400, 'conversions' => 5]],
            'checksum' => 'abc',
        ];
    }

    public function test_issue_token_then_fetch_data(): void
    {
        Sanctum::actingAs($this->owner);
        $report = $this->report($this->validSnapshot());

        $res = $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/print-token", ['type' => 'presentation']);
        $res->assertOk();
        $token = $res->json('data.token');
        $this->assertNotEmpty($token);

        // Public data endpoint returns the snapshot (no session needed).
        $this->getJson("/api/v1/reports/print/{$token}")
            ->assertOk()
            ->assertJsonPath('data.name', 'R')
            ->assertJsonPath('data.type', 'presentation');
    }

    public function test_token_not_issued_for_invalid_report(): void
    {
        Sanctum::actingAs($this->owner);
        $bad = $this->report([
            'currency' => 'SAR', 'kpis' => ['spend' => 0, 'revenue' => 0, 'conversions' => 307],
            'platforms' => [['provider' => 'meta', 'spend' => 0, 'revenue' => 0, 'conversions' => 307]],
        ]);

        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$bad->id}/print-token")
            ->assertStatus(422);
    }

    public function test_bad_print_token_is_404(): void
    {
        $this->getJson('/api/v1/reports/print/nonexistent-token')->assertNotFound();
        Cache::put('report-print:'.hash('sha256', 'ghost'), ['report_id' => '00000000-0000-0000-0000-000000000000', 'type' => 'presentation', 'theme' => 'light'], 60);
        $this->getJson('/api/v1/reports/print/ghost')->assertNotFound();
    }
}
