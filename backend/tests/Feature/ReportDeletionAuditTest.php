<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a report removes its links with it, so the deletion must leave an authorised record of what went and who did it.
 *
 * Found on NASEL, 2026-10-29: two client links answered 404, the project's report list no longer held their reports, and
 * nothing anywhere said who deleted them or when — sending and revoking were audited, deleting the whole report was not.
 */
final class ReportDeletionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_report_records_who_deleted_it_and_every_link_it_took_with_it(): void
    {
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Del', 'slug' => 'del-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->getKey());
        $role = Role::create(['tenant_id' => $tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $operator = User::create(['name' => 'Op', 'email' => 'op@del.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($operator, $tenant);
        $operator->assignRole($role);
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['tenant_id' => $tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $project->getKey());

        $report = Report::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(),
            'name' => 'Client monthly', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-28', 'currency' => 'SAR', 'data' => ['kpis' => []],
        ]);
        [$share, $raw] = app(ShareService::class)->create($report, [], null);

        $this->actingAs($operator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$project->getKey()}/reports/{$report->getKey()}")
            ->assertOk();

        $this->assertNull(Report::query()->find($report->getKey()));
        $this->assertNull(ReportShare::query()->find($share->getKey()));

        $row = AuditLog::query()->where('action', 'report.deleted')->where('entity_id', (string) $report->getKey())->first();
        $this->assertNotNull($row, 'deleting a report left no audit row');
        $this->assertSame($operator->id, $row->user_id);
        $this->assertSame($report->name, $row->before['name']);
        $this->assertSame('2026-10-01', $row->before['period_start']);
        $this->assertSame([(string) $share->getKey()], array_column($row->before['shares'], 'id'));
        // The raw token is a credential; the record identifies the link without it.
        $this->assertStringNotContainsString($raw, json_encode($row->before, JSON_THROW_ON_ERROR));
    }
}
