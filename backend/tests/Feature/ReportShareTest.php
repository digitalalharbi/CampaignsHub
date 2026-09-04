<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
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
use Illuminate\Support\Str;
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
     * A campaign name must not reach a client through a NOTE — CLIENT-REPORT-ENTITY-BOUNDARY-001.
     *
     * This asserted that «(burner)» was STRIPPED and «Meta — Lead Gen» survived, which was the old
     * rule: sanitise the name and print it. The owner's correction is that the name was never the
     * problem — the container is, and removing the embarrassing half of a label does not change
     * whose label it is.
     *
     * The note is prose written down by an earlier release, so it cannot be re-attributed to a
     * platform: the figures behind it are per-campaign. It is dropped, and a report generated today
     * states the same finding by platform and keeps it.
     */
    public function test_a_note_that_names_a_campaign_does_not_reach_a_client(): void
    {
        $this->report->forceFill(['data' => $this->report->data + ['observations' => [
            ['id' => 'a', 'kind' => 'budget_pace', 'severity' => 'critical', 'reveals' => [],
                'title' => 'حملة «Meta — Lead Gen (burner)» تستهلك الميزانية أسرع من الخطة',
                'detail' => 'راجع «Meta — Lead Gen (burner)».',
                'scope' => ['type' => 'campaign', 'name' => 'Meta — Lead Gen (burner)']],
        ]]])->saveQuietly();

        [, $raw] = app(ShareService::class)->create($this->report, [], null);
        $notes = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.observations');

        $encoded = json_encode($notes, JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertSame([], $notes, 'a campaign-scoped note reached a client');
        $this->assertStringNotContainsString('burner', $encoded);
        $this->assertStringNotContainsString('Lead Gen', $encoded);
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

    /**
     * A share's CEILING comes from the metrics, never from the report's own payload.
     *
     * `campaignsOf()` read `data['campaigns']` — a rendering of the document — and used it to decide
     * what the link may reach. CLIENT-REPORT-ENTITY-BOUNDARY-001 emptied that list, because a client
     * report carries no campaign roster, and the ceiling silently became «no campaigns». The
     * aggregator reads an empty campaign filter as its fail-closed «match nothing», so every share
     * created from a project-level report would have opened on a page of zeros.
     *
     * Four E2E specs on the live link went red at once and no unit test did, because each half was
     * correct on its own: the report was right to stop printing the roster, and `forCampaigns([])`
     * was right to fail closed. What was wrong was deriving an AUTHORISATION fact from a
     * PRESENTATION one. This test is that seam, held.
     */
    public function test_a_new_share_reaches_the_campaigns_that_spent_even_though_the_report_names_none(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $campaign = UnifiedCampaign::create([
            'project_id' => $this->report->project_id, 'name' => 'Eid', 'status' => 'active',
        ]);
        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->report->project_id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaign->id,
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => Carbon::today()->toDateString(),
            'value' => 100,
        ]);
        app(TenantContext::class)->forget();

        // The report itself names no campaign — that is the boundary working, not a gap in the data.
        $this->assertSame([], $this->report->data['campaigns'] ?? []);

        // A LIVE link, because only a live one carries a scope — a snapshot has its figures already.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares", ['mode' => 'live'])
            ->assertCreated();

        $share = ReportShare::withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($share);
        $this->assertSame(
            [(string) $campaign->id],
            $share->scope['campaign_ids'] ?? [],
            'the link was created unable to reach any campaign — every figure on it would read zero',
        );
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
