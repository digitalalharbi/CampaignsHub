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
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'data' => ['kpis' => ['spend' => 100, 'revenue' => 400, 'roas' => 4.0], 'platforms' => [['provider' => 'meta', 'spend' => 100, 'revenue' => 400]]],
        ]);
        app(TenantContext::class)->forget();
    }

    /**
     * A note that states a hidden figure in prose is dropped, not reworded.
     *
     * Column redaction nulls table cells. It does not reach «صُرف 27,745.88 SAR من أصل 16,666.67
     * SAR», which publishes the same spend in a sentence — and a client link told to hide spend
     * would have carried it in the section clients read first.
     */
    public function test_a_link_that_hides_spend_drops_the_notes_that_state_it(): void
    {
        $this->report->forceFill(['data' => $this->report->data + ['observations' => [
            ['id' => 'a', 'kind' => 'budget_pace', 'severity' => 'critical', 'reveals' => ['spend'],
                'title' => 'حملة «الصيف» تستهلك الميزانية أسرع من الخطة',
                'detail' => 'صُرف 27,745.88 SAR من أصل 16,666.67 SAR.',
                'scope' => ['type' => 'campaign', 'name' => 'الصيف']],
            ['id' => 'b', 'kind' => 'falling_rate', 'severity' => 'warning', 'reveals' => [],
                'title' => 'تراجع معدل النقر', 'detail' => 'معدل النقر تراجع 30%.',
                'scope' => ['type' => 'period', 'name' => null]],
        ]]])->saveQuietly();

        [, $raw] = app(ShareService::class)->create($this->report, ['hide_spend' => true], null);
        $notes = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.observations');

        $this->assertSame(['b'], array_column($notes, 'id'));
        // Belt and braces: the figure itself must not survive anywhere in the payload.
        $this->assertStringNotContainsString('27,745.88', json_encode($notes, JSON_UNESCAPED_UNICODE));
    }

    /** A note ABOUT one campaign is nothing once the campaign cannot be named. */
    public function test_a_link_that_hides_campaign_names_drops_the_notes_about_one_campaign(): void
    {
        $this->report->forceFill(['data' => $this->report->data + ['observations' => [
            ['id' => 'a', 'kind' => 'budget_pace', 'severity' => 'critical', 'reveals' => [],
                'title' => 'حملة «الصيف»', 'detail' => '…', 'scope' => ['type' => 'campaign', 'name' => 'الصيف']],
            ['id' => 'b', 'kind' => 'data_gap', 'severity' => 'info', 'reveals' => [],
                'title' => 'مؤشرات ناقصة', 'detail' => '…', 'scope' => ['type' => 'data', 'name' => null]],
        ]]])->saveQuietly();

        [, $raw] = app(ShareService::class)->create($this->report, ['hide_campaign_names' => true], null);
        $notes = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.observations');

        $this->assertSame(['b'], array_column($notes, 'id'));
    }

    /**
     * An internal marker in a campaign name must not reach a client through a NOTE.
     *
     * Every other surface that prints a campaign name goes through `clientName`. The observations
     * were prose the sanitiser did not know about.
     */
    public function test_an_internal_campaign_name_is_cleaned_inside_a_note(): void
    {
        $this->report->forceFill(['data' => $this->report->data + ['observations' => [
            ['id' => 'a', 'kind' => 'budget_pace', 'severity' => 'critical', 'reveals' => [],
                'title' => 'حملة «Meta — Lead Gen (burner)» تستهلك الميزانية أسرع من الخطة',
                'detail' => 'راجع «Meta — Lead Gen (burner)».',
                'scope' => ['type' => 'campaign', 'name' => 'Meta — Lead Gen (burner)']],
        ]]])->saveQuietly();

        [, $raw] = app(ShareService::class)->create($this->report, [], null);
        $notes = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.observations');

        $encoded = json_encode($notes, JSON_UNESCAPED_UNICODE);
        // The MARKER goes; the name it was attached to is legitimate and stays, which is what
        // `clientName` has always done everywhere else a campaign is printed.
        $this->assertStringNotContainsString('burner', $encoded);
        $this->assertStringContainsString('Meta — Lead Gen', $encoded);
        $this->assertSame('Meta — Lead Gen', $notes[0]['scope']['name']);
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
        $viewer = User::create(['name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);
        app(TenantContext::class)->forget();

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares", [])
            ->assertForbidden();
    }
}
