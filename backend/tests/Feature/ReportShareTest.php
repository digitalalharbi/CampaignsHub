<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Secure client links: only the token hash is stored, expiry/revocation/password gate access, hidden
 * figures are stripped from the public payload, and access is logged.
 */
final class ReportShareTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Report $report;

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
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'data' => ['kpis' => ['spend' => 100, 'revenue' => 400, 'roas' => 4.0], 'platforms' => [['provider' => 'meta', 'spend' => 100, 'revenue' => 400]]],
        ]);
        app(TenantContext::class)->forget();
    }

    public function test_only_token_hash_is_stored(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [], null);
        $this->assertNotSame($raw, $share->token_hash);
        $this->assertSame(hash('sha256', $raw), $share->token_hash);
        // Raw token is nowhere in the DB.
        $this->assertDatabaseMissing('report_shares', ['token_hash' => $raw]);
    }

    public function test_public_show_respects_hide_and_logs_access(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, ['hide_spend' => true], null);

        $data = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');
        $this->assertNull($data['data']['kpis']['spend']);   // hidden
        $this->assertEquals(400, $data['data']['kpis']['revenue']); // visible

        $share = ReportShare::withoutGlobalScopes()->first();
        $this->assertSame(1, $share->view_count);
        $this->assertSame('view', $share->logs()->first()->action);
    }

    public function test_password_gate(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, ['password' => 'secret1'], null);
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(401);
        $this->withHeader('X-Report-Password', 'wrong')->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(401);
        $this->withHeader('X-Report-Password', 'secret1')->getJson("/api/v1/reports/shared/{$raw}")->assertOk();
    }

    public function test_expired_and_revoked_links_are_dead(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [], null);
        $share->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(404);

        [$share2, $raw2] = app(ShareService::class)->create($this->report, [], null);
        $share2->update(['revoked_at' => Carbon::now()]);
        $this->getJson("/api/v1/reports/shared/{$raw2}")->assertStatus(404);
    }

    public function test_share_requires_permission(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'reports.view'); // no reports.share
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $viewer->assignRole($role);
        app(TenantContext::class)->forget();

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares", [])
            ->assertForbidden();
    }
}
