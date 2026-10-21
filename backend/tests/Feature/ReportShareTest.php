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
use App\Domains\Reports\Support\ReportColumns;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // No note survives, so the section is absent (null) rather than an empty list.
        $this->assertEmpty($notes ?? [], 'a campaign-scoped note reached a client');
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

    /**
     * REPORT-TABLES-OWNER-COLUMNS-001 — the Owner's column choice is stored resolved: unknown columns
     * and tables are cut, the table's own order wins, and an unrestricted table is simply absent.
     */
    public function test_the_columns_an_owner_chooses_are_stored_resolved(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares", [
                'columns' => ['platforms' => ['ctr', 'spend', 'bogus', 'spend'], 'invented' => ['x']],
            ])->assertCreated();

        $share = ReportShare::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame(['platforms' => ['spend', 'ctr']], $share->settings['columns']);
        $this->assertSame(['platforms' => ['spend', 'ctr']], ReportColumns::forShare($share)->toArray());
    }

    /** The Tables view's accounts, content and day tables are choosable too, each cut to its own known columns. */
    public function test_the_other_tables_columns_are_stored_resolved_too(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares", [
                'columns' => [
                    'content' => ['cost', 'results', 'ctr', 'platform'],
                    'accounts' => ['spend'],
                    'days' => ['results', 'results', 'bogus'],
                ],
            ])->assertCreated();

        $share = ReportShare::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame(
            ['accounts' => ['spend'], 'content' => ['platform', 'results', 'cost'], 'days' => ['results']],
            ReportColumns::forShare($share)->toArray(),
            'a column the table does not have is cut, and the table\'s own order wins',
        );
    }

    public function test_a_link_with_no_column_choice_publishes_every_column(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares", [])
            ->assertCreated();

        $share = ReportShare::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame([], ReportColumns::forShare($share)->toArray());
    }

    /**
     * REPORT-LINK-ANALYTICS-001 — the Owner sees how often the link was opened: total views, privacy-safe
     * unique views, first and last opened. Link-preview fetches are not people, one browser opening it twice
     * is one person, and the client's own payload carries none of it.
     */
    public function test_the_owner_sees_link_analytics_and_the_client_never_does(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [], null);
        $at = Carbon::parse('2026-10-10 09:00:00');
        $log = function (string $action, string $ip, string $ua, Carbon $when) use ($share): void {
            DB::table('report_share_access_logs')->insert(['id' => (string) Str::uuid(), 'share_id' => $share->id, 'action' => $action, 'ip' => $ip, 'user_agent' => $ua, 'created_at' => $when]);
        };
        $chrome = 'Mozilla/5.0 Chrome/120';
        $log('view', '1.1.1.1', $chrome, $at);
        $log('view', '1.1.1.1', $chrome, $at->copy()->addDay());                 // same browser again
        $log('view', '2.2.2.2', 'Mozilla/5.0 Safari/17', $at->copy()->addDays(2)); // a second person
        $log('view', '3.3.3.3', 'WhatsApp/2.23', $at->copy()->addHour());          // a link-preview fetch
        $log('view', '4.4.4.4', 'Mozilla/5.0 (compatible; Googlebot/2.1)', $at);   // a crawler
        $log('download', '1.1.1.1', $chrome, $at->copy()->addDays(3));
        $log('denied', '9.9.9.9', $chrome, $at);

        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}/analytics";
        $res = $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk();

        $this->assertSame(3, $res->json('data.views'));
        $this->assertSame(2, $res->json('data.unique_views'));
        $this->assertSame('2026-10-10T09:00:00+00:00', $res->json('data.first_viewed_at'));
        $this->assertSame('2026-10-12T09:00:00+00:00', $res->json('data.last_viewed_at'));
        $this->assertSame(1, $res->json('data.downloads'));
        $this->assertStringNotContainsString('1.1.1.1', $res->getContent(), 'no address ever leaves the analytics');

        $public = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->getContent();
        foreach (['unique_views', 'first_viewed_at', 'analytics'] as $private) {
            $this->assertStringNotContainsString($private, $public, "the client's payload carries {$private}");
        }
    }

    /**
     * REPORT-LINK-ANALYTICS-003 — the drawn window against the equal window before it, in the report's own days,
     * and the link's own state beside the figures: a paused link says so and since when.
     */
    public function test_the_window_is_compared_with_the_one_before_it_and_the_link_states_itself(): void
    {
        $this->report->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        [$share] = app(ShareService::class)->create($this->report, [], null);
        $log = function (string $action, string $ip, string $utc) use ($share): void {
            DB::table('report_share_access_logs')->insert(['id' => (string) Str::uuid(), 'share_id' => $share->id, 'action' => $action, 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 Chrome/120', 'created_at' => Carbon::parse($utc, 'UTC')]);
        };
        // The earlier window (Oct 5–9): three opens by two devices, one download.
        $log('view', '1.1.1.1', '2026-10-05 09:00:00');
        $log('view', '1.1.1.1', '2026-10-07 09:00:00');
        $log('view', '2.2.2.2', '2026-10-09 20:30:00'); // 23:30 Riyadh — still the 9th, still the earlier window
        $log('download', '2.2.2.2', '2026-10-09 10:00:00');
        // The drawn window (Oct 10–14): two opens by one device, no download.
        $log('view', '3.3.3.3', '2026-10-10 09:00:00');
        $log('view', '3.3.3.3', '2026-10-13 09:00:00');
        $log('view', '4.4.4.4', '2026-10-09 21:30:00'); // 00:30 on the 10th in Riyadh — the drawn window

        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}/analytics?from=2026-10-10&to=2026-10-14";
        $data = $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk()->json('data');

        $this->assertSame('active', $data['state']);
        $this->assertNull($data['state_since']);
        $this->assertSame(['from' => '2026-10-10', 'to' => '2026-10-14', 'views' => 3, 'devices' => 2, 'downloads' => 0], $data['comparison']['current']);
        $this->assertSame(['from' => '2026-10-05', 'to' => '2026-10-09', 'views' => 3, 'devices' => 2, 'downloads' => 1], $data['comparison']['previous']);

        $share->forceFill(['paused_at' => Carbon::parse('2026-10-15 08:00:00', 'UTC')])->save();
        $data = $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk()->json('data');
        $this->assertSame('paused', $data['state']);
        $this->assertSame('2026-10-15T08:00:00+00:00', $data['state_since']);

        $share->forceFill(['paused_at' => null, 'expires_at' => Carbon::parse('2026-10-16 00:00:00', 'UTC')])->save();
        Carbon::setTestNow('2026-10-20 00:00:00');
        $this->assertSame('expired', $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk()->json('data.state'));
        Carbon::setTestNow();
    }

    /**
     * REPORT-LINK-ANALYTICS-002 — the trend, the repeat opens and what was left out as a machine, each in the
     * report's own day, with the empty days present so a chart is continuous.
     */
    public function test_the_trend_is_bucketed_in_the_reports_own_day_and_says_what_the_unique_count_is(): void
    {
        $this->report->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        [$share] = app(ShareService::class)->create($this->report, [], null);
        $log = function (string $ip, string $ua, string $utc) use ($share): void {
            DB::table('report_share_access_logs')->insert(['id' => (string) Str::uuid(), 'share_id' => $share->id, 'action' => 'view', 'ip' => $ip, 'user_agent' => $ua, 'created_at' => Carbon::parse($utc, 'UTC')]);
        };
        $chrome = 'Mozilla/5.0 Chrome/120';
        $log('1.1.1.1', $chrome, '2026-10-10 09:00:00');
        $log('1.1.1.1', $chrome, '2026-10-10 10:00:00');          // the same device twice
        $log('2.2.2.2', 'Mozilla/5.0 Safari/17', '2026-10-10 21:30:00'); // 00:30 on the 11th in Riyadh
        $log('3.3.3.3', 'WhatsApp/2.23', '2026-10-12 09:00:00');   // a preview fetch: not counted
        $log('1.1.1.1', $chrome, '2026-10-13 09:00:00');

        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}/analytics?from=2026-10-09&to=2026-10-13";
        $data = $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk()->json('data');

        $this->assertSame(4, $data['views']);
        $this->assertSame(2, $data['unique_views']);
        $this->assertSame(2, $data['repeat_visits'], 'four human opens by two devices');
        $this->assertSame(1, $data['bot_opens']);
        $this->assertSame('device_estimate', $data['unique_basis']);
        $this->assertSame('day', $data['granularity']);

        $byDay = collect($data['series'])->keyBy('period');
        $this->assertSame(['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'], $byDay->keys()->all(), 'empty days are present');
        $this->assertSame(0, $byDay['2026-10-09']['views']);
        $this->assertSame(2, $byDay['2026-10-10']['views']);
        $this->assertSame(1, $byDay['2026-10-11']['views'], 'an open at 00:30 local belongs to the day the client lived it in');
        $this->assertSame(0, $byDay['2026-10-12']['views'], 'the preview fetch is not a view');
        $this->assertSame(1, $byDay['2026-10-13']['views']);
        $this->assertSame(4, array_sum(array_column($data['series'], 'views')), 'the series adds up to the total');
        $this->assertStringNotContainsString('1.1.1.1', (string) json_encode($data));
    }

    public function test_the_weekly_and_monthly_trends_group_and_a_bad_granularity_is_refused(): void
    {
        [$share] = app(ShareService::class)->create($this->report, [], null);
        foreach (['2026-09-30 09:00:00', '2026-10-05 09:00:00', '2026-10-06 09:00:00'] as $i => $when) {
            DB::table('report_share_access_logs')->insert(['id' => (string) Str::uuid(), 'share_id' => $share->id, 'action' => 'view', 'ip' => "9.9.9.$i", 'user_agent' => 'Mozilla/5.0 Chrome/120', 'created_at' => Carbon::parse($when, 'UTC')]);
        }
        $base = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}/analytics";
        $as = $this->actingAs($this->owner, 'sanctum');

        $month = collect($as->getJson($base.'?granularity=month&from=2026-09-01&to=2026-10-31')->assertOk()->json('data.series'))->keyBy('period');
        $this->assertSame(1, $month['2026-09-01']['views']);
        $this->assertSame(2, $month['2026-10-01']['views']);

        $week = collect($as->getJson($base.'?granularity=week&from=2026-09-28&to=2026-10-11')->assertOk()->json('data.series'))->keyBy('period');
        $this->assertSame(1, $week['2026-09-28']['views'], 'Monday 28 September starts the week of the 30th');
        $this->assertSame(2, $week['2026-10-05']['views']);

        $as->getJson($base.'?granularity=hour')->assertStatus(422);
        $as->getJson($base.'?from=not-a-date')->assertStatus(422);
    }

    public function test_link_analytics_are_not_available_without_the_share_permission(): void
    {
        [$share] = app(ShareService::class)->create($this->report, [], null);
        $viewer = User::create(['name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo('reports.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}/analytics")->assertForbidden();
    }

    /**
     * REPORT-LINK-EDIT-IN-PLACE-001 — the Owner edits a published link and the SAME URL reflects it at once.
     */
    public function test_a_published_link_is_edited_in_place_and_the_same_token_reflects_it_immediately(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, ['hide_spend' => true], null);
        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}";

        $before = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.kpis');
        $this->assertNull($before['spend']);

        $this->actingAs($this->owner, 'sanctum')->patchJson($url, ['hide_spend' => false, 'hide_revenue' => true, 'password' => 'secret9'])->assertOk();

        $this->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(401);                       // the new password gates the SAME url
        $after = $this->withHeader('X-Report-Password', 'secret9')->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.kpis');
        $this->assertEquals(100, $after['spend']);                                                // spend now shown
        $this->assertNull($after['revenue']);                                                      // revenue now hidden

        $this->patchJson($url, ['clear_password' => true])->assertOk();
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();

        $fresh = ReportShare::withoutGlobalScopes()->find($share->id);
        $this->assertSame($share->token_hash, $fresh->token_hash, 'the link itself never changes');
        $this->assertSame(1, ReportShare::withoutGlobalScopes()->count(), 'no second link was made');
    }

    public function test_an_edit_goes_through_the_same_value_objects_and_a_revoked_link_cannot_be_edited(): void
    {
        [$share] = app(ShareService::class)->create($this->report, [], null);
        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}";
        $this->actingAs($this->owner, 'sanctum');

        // `roas` beside a hidden spend is a published spend: the value object closes that combination.
        $this->patchJson($url, ['creatives' => ['spend' => false, 'roas' => true]])->assertOk();
        $stored = ReportShare::withoutGlobalScopes()->find($share->id)->settings['creatives'];
        $this->assertFalse($stored['spend'] ?? false);
        $this->assertFalse($stored['roas'] ?? false);

        $this->patchJson($url, ['expires_at' => now()->subDay()->toDateString()])->assertStatus(422);

        $share->update(['revoked_at' => now()]);
        $this->patchJson($url, ['watermark' => true])->assertStatus(409);
    }

    public function test_only_someone_who_may_share_can_edit_a_link(): void
    {
        [$share] = app(ShareService::class)->create($this->report, [], null);
        $viewer = User::create(['name' => 'V', 'email' => 'v2@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V2', 'slug' => 'v2']);
        $role->givePermissionTo('reports.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->patchJson("/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}", ['watermark' => true])->assertForbidden();
    }

    /**
     * REPORT-CLIENT-NO-DELTAS-001 — a new client link carries no previous-period comparison unless asked, and
     * naming one switch in an edit does not reset the others.
     */
    public function test_a_new_client_link_has_no_previous_comparison_by_default_and_an_edit_keeps_the_other_switches(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $base = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares";

        $made = $this->postJson($base, ['sections' => ['budget' => false]])->assertCreated()->json('data');
        $this->assertFalse($made['sections']['previous_comparison']);
        $this->assertFalse($made['sections']['budget']);

        $asked = $this->postJson($base, ['sections' => ['previous_comparison' => true]])->assertCreated()->json('data');
        $this->assertTrue($asked['sections']['previous_comparison']);

        $this->patchJson("{$base}/{$made['id']}", ['sections' => ['previous_comparison' => true]])->assertOk();
        $stored = ReportShare::withoutGlobalScopes()->find($made['id'])->settings['sections'];
        $this->assertTrue($stored['previous_comparison']);
        $this->assertFalse($stored['budget'], 'naming one switch left the other exactly as it was');
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

    /** REPORT-LINK-PAUSE-001 — pausing switches the SAME link off and resuming brings it back untouched. */
    public function test_a_paused_link_stops_opening_and_resumes_as_the_same_link(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [], null);
        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}";
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("{$url}/pause")->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.revoked_at', null);
        $this->assertNotNull($this->postJson("{$url}/pause")->assertOk()->json('data.paused_at'), 'pausing twice changes nothing');
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertStatus(404);

        $this->postJson("{$url}/resume")->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.paused_at', null);
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();
    }

    public function test_a_revoked_link_can_be_neither_paused_nor_resumed_and_a_viewer_cannot_pause(): void
    {
        [$share] = app(ShareService::class)->create($this->report, [], null);
        $url = "/api/v1/projects/{$this->report->project_id}/reports/{$this->report->id}/shares/{$share->id}";

        $viewer = User::create(['name' => 'V', 'email' => 'v3@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $this->actingAs($viewer, 'sanctum')->postJson("{$url}/pause")->assertForbidden();

        $share->update(['revoked_at' => now()]);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("{$url}/pause")->assertStatus(409);
        $this->postJson("{$url}/resume")->assertStatus(409);
        $this->assertNull($share->fresh()->paused_at);
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
