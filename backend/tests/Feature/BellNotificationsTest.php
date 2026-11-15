<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Support\BellKind;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NOTIF-BELL-001 — the bell says what a notification really is, counts once, and clears for real.
 *
 * Owner report (2026-11-15): «صرف بدون نتائج» was titled «تجاوز حدود الميزانية»; a repeat of one alert added a new row;
 * «تعليم الكل كمقروء» left the number where it was.
 */
final class BellNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Bell Agency', 'slug' => 'bell-agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->agency->id, 'name' => 'Alpha', 'slug' => 'alpha-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id, 'name' => 'A1', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $email): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all');
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $this->agency, portal: Portal::Agency, role: 'member'));

        return $user;
    }

    private function aRule(): AlertRule
    {
        return AlertRule::create(['tenant_id' => $this->agency->id, 'type' => 'no_results', 'name' => 'R', 'threshold' => ['days' => 3], 'cooldown_minutes' => 0, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true]);
    }

    /** @param array<string,mixed> $over */
    private function note(array $over = []): AppNotification
    {
        return AppNotification::create($over + [
            'tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->project->client_workspace_id,
            'user_id' => null, 'type' => 'budget_risk', 'title' => 'x', 'message' => 'm', 'status' => 'unread', 'severity' => 'warning', 'source' => 'alerts',
        ]);
    }

    /** @return array<string,mixed> */
    private function bell(User $user, string $query = ''): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications'.$query)->assertOk()->json();
    }

    public function test_345_unread_marked_all_in_one_call_leave_the_bell_at_zero_for_that_reader_only(): void
    {
        $me = $this->member('me@bell.dev');
        $other = $this->member('other@bell.dev');
        for ($i = 0; $i < 300; $i++) {
            $this->note(['entity_id' => 'team-'.$i, 'dedup_key' => 't'.$i]);
        }
        for ($i = 0; $i < 45; $i++) {
            $this->note(['user_id' => $me->id, 'entity_id' => 'own-'.$i, 'dedup_key' => 'o'.$i]);
        }

        // The bell is the REAL count: 345 for the one who owns 45 and shares 300, not the 100 rows of a page.
        $before = $this->bell($me);
        $this->assertSame(345, $before['meta']['unread']);
        $this->assertCount(100, $before['data']);
        $this->assertSame(345, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));

        $this->actingAs($me, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.cleared', 345)->assertJsonPath('data.unread', 0);

        $after = $this->bell($me);
        $this->assertSame(0, $after['meta']['unread']);
        $this->assertSame(0, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));
        $this->assertSame([], array_values(array_filter($after['data'], static fn (array $n): bool => $n['status'] === 'unread')));

        // Read is per person: the team-wide rows are still waiting for the colleague.
        $this->assertSame(300, $this->bell($other)['meta']['unread']);
    }

    public function test_a_team_wide_row_can_be_marked_read_by_a_member_who_can_see_it(): void
    {
        $me = $this->member('me@bell.dev');
        $n = $this->note(['entity_id' => 'one']);

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/read")->assertOk();

        $this->assertSame(0, $this->bell($me)['meta']['unread']);
        $this->assertSame('read', $this->bell($me)['data'][0]['status']);
    }

    public function test_the_same_finding_raised_twice_the_same_day_is_one_notification_and_the_second_updates_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 06:00:00', 'UTC'));
        $d = app(NotificationDispatcher::class);
        $p = ['tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'type' => 'budget_risk', 'kind' => 'budget_90', 'severity' => 'warning',
            'title' => 'Budget at 90%', 'message' => 'first', 'entity_type' => 'limit', 'entity_id' => 'L1', 'upsert_daily' => true];

        $first = $d->dispatch($p);
        // Two hours later is past the old 60-minute window — it used to be a second row.
        Carbon::setTestNow(Carbon::parse('2026-11-15 08:00:00', 'UTC'));
        $second = $d->dispatch(['message' => 'second, with fresher figures'] + $p);

        $this->assertSame($first->id, $second->id);
        $rows = AppNotification::where('kind', 'budget_90')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('second, with fresher figures', $rows[0]->message);
        $this->assertSame(2, (int) $rows[0]->repeat_count);

        // Another day is another notification, and another kind the same day is another notification.
        $d->dispatch(['kind' => 'budget_100', 'title' => 'Budget at 100%'] + $p);
        Carbon::setTestNow(Carbon::parse('2026-11-16 06:00:00', 'UTC'));
        $d->dispatch($p);
        $this->assertSame(3, AppNotification::count());
    }

    public function test_a_repeat_that_got_worse_comes_back_as_unread_and_a_plain_repeat_does_not(): void
    {
        $me = $this->member('me@bell.dev');
        $d = app(NotificationDispatcher::class);
        $p = ['tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'type' => 'budget_risk', 'kind' => 'budget_90', 'severity' => 'warning',
            'title' => 'Budget at 90%', 'entity_type' => 'limit', 'entity_id' => 'L1', 'upsert_daily' => true];
        $d->dispatch($p);
        $this->actingAs($me, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk();

        $d->dispatch($p);
        $this->assertSame(0, $this->bell($me)['meta']['unread']);

        $d->dispatch(['severity' => 'error'] + $p);
        $this->assertSame(1, $this->bell($me)['meta']['unread']);
        $this->assertSame('critical', $this->bell($me)['data'][0]['severity']);
    }

    /** The spec's list, and the one that must not be said for a budget that is not over. */
    public function test_each_kind_is_named_by_what_it_is_and_over_is_said_only_when_the_spend_is_over(): void
    {
        $this->assertSame('صرف بدون نتائج', BellKind::label(BellKind::forAlert('no_results'), true));
        $this->assertSame('ارتفاع تكلفة النتيجة', BellKind::label(BellKind::forAlert('cpa_increase'), true));
        $this->assertSame('ارتفاع تكلفة النتيجة', BellKind::label(BellKind::forAlert('cpl_increase'), true));
        $this->assertSame('انخفاض العائد', BellKind::label(BellKind::forAlert('roas_drop'), true));
        $this->assertSame('حملة ما تصرف', BellKind::label(BellKind::forAlert('not_spending'), true));
        $this->assertSame('فشل المزامنة', BellKind::label(BellKind::forAlert('sync_failure'), true));
        $this->assertSame('تقرير جاهز', BellKind::label(BellKind::forType('report_ready'), true));

        $limit = static fn (float $used): array => ['consumed' => $used, 'limit' => 1000.0, 'threshold' => 100];
        $this->assertSame('الميزانية 75%', BellKind::label(BellKind::forAlert('budget_risk', $limit(760)), true));
        $this->assertSame('الميزانية 90%', BellKind::label(BellKind::forAlert('budget_risk', $limit(910)), true));
        $this->assertSame('الميزانية 100%', BellKind::label(BellKind::forAlert('budget_risk', $limit(1000)), true));
        $this->assertSame('تجاوز حدود الميزانية', BellKind::label(BellKind::forAlert('budget_risk', $limit(1000.01)), true));
        $this->assertSame('تجاوز حدود الميزانية', BellKind::label(BellKind::forAlert('budget_risk', ['ratio' => 1.2]), true));
        $this->assertSame('الميزانية 90%', BellKind::label(BellKind::forAlert('budget_risk', ['ratio' => 0.93]), true));
        // No figure behind it: not claimed as an overrun.
        $this->assertNotSame('تجاوز حدود الميزانية', BellKind::label(BellKind::forAlert('budget_risk', []), true));
    }

    /** The row the owner screenshotted: type `budget_risk`, alert `no_results`. Served as «صرف بدون نتائج». */
    public function test_a_no_results_alert_filed_under_the_budget_type_is_never_titled_as_a_budget_overrun(): void
    {
        $me = $this->member('me@bell.dev');
        $n = $this->note(['title' => 'Spend with no results', 'severity' => 'warning', 'entity_type' => 'campaign', 'entity_id' => 'C1']);
        AlertEvent::create(['tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'rule_id' => $this->aRule()->id, 'type' => 'no_results',
            'entity_type' => 'campaign', 'entity_id' => 'C1', 'dedup_key' => 'k', 'status' => 'open', 'severity' => 'warning', 'context' => [], 'notification_id' => $n->id, 'last_triggered_at' => now()]);

        $row = $this->bell($me)['data'][0];
        $this->assertSame('no_results', $row['kind']);
        $this->assertSame('صرف بدون نتائج', $row['kind_label']);
        $this->assertSame('performance', $row['group']);
        $this->assertNotSame('تجاوز حدود الميزانية', $row['kind_label']);

        // A budget_risk row with nothing behind it is not claimed to be an overrun either.
        $bare = $this->note(['entity_id' => 'bare', 'dedup_key' => 'bare']);
        $labels = array_column($this->bell($me)['data'], 'kind_label', 'id');
        $this->assertSame('تنبيه الميزانية', $labels[$bare->id]);
    }

    public function test_the_panel_filters_narrow_the_list_but_not_the_bell_number(): void
    {
        $me = $this->member('me@bell.dev');
        $this->note(['kind' => 'budget_90', 'entity_id' => '1', 'dedup_key' => '1']);
        $this->note(['kind' => 'no_results', 'entity_id' => '2', 'dedup_key' => '2']);
        $this->note(['kind' => 'sync_failed', 'type' => 'sync_failed', 'severity' => 'error', 'entity_id' => '3', 'dedup_key' => '3']);

        $this->assertSame(['budget_90'], array_column($this->bell($me, '?group=budget')['data'], 'kind'));
        $this->assertSame(['no_results'], array_column($this->bell($me, '?group=performance')['data'], 'kind'));
        $this->assertSame(['sync_failed'], array_column($this->bell($me, '?group=sync')['data'], 'kind'));
        $critical = $this->bell($me, '?group=critical');
        $this->assertSame(['sync_failed'], array_column($critical['data'], 'kind'));
        $this->assertSame(3, $critical['meta']['unread']);
    }

    public function test_a_notification_closes_when_its_alert_closes(): void
    {
        $me = $this->member('me@bell.dev');
        $n = $this->note(['kind' => 'no_results', 'entity_type' => 'campaign', 'entity_id' => 'C1']);
        $earlier = $this->note(['kind' => 'no_results', 'entity_type' => 'campaign', 'entity_id' => 'C1', 'dedup_key' => 'yesterday']);
        $otherFinding = $this->note(['kind' => 'not_spending', 'entity_type' => 'campaign', 'entity_id' => 'C1', 'dedup_key' => 'other']);
        $event = AlertEvent::create(['tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'rule_id' => $this->aRule()->id, 'type' => 'no_results',
            'entity_type' => 'campaign', 'entity_id' => 'C1', 'dedup_key' => 'k', 'status' => 'open', 'severity' => 'warning', 'context' => [], 'notification_id' => $n->id, 'last_triggered_at' => now()]);
        $this->assertSame(3, $this->bell($me)['meta']['unread']);

        $event->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        $this->assertSame('resolved', $n->fresh()->status);
        $this->assertSame('resolved', $earlier->fresh()->status);
        $this->assertSame('unread', $otherFinding->fresh()->status, 'a different finding about the same campaign is still open');
        $this->assertSame(1, $this->bell($me)['meta']['unread']);
    }

    public function test_summary_lists_only_unread_warning_and_critical_for_the_sound(): void
    {
        $me = $this->member('me@bell.dev');
        $crit = $this->note(['severity' => 'error', 'entity_id' => '1', 'dedup_key' => '1']);
        $warn = $this->note(['severity' => 'warning', 'entity_id' => '2', 'dedup_key' => '2']);
        $this->note(['severity' => 'info', 'entity_id' => '3', 'dedup_key' => '3']);

        $json = $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->assertOk()->json('data');

        $this->assertSame(3, $json['unread']);
        $this->assertEqualsCanonicalizing([$crit->id, $warn->id], array_column($json['attention'], 'id'));
        $this->assertContains('critical', array_column($json['attention'], 'severity'));
    }

    public function test_a_reader_cannot_clear_a_row_they_cannot_see(): void
    {
        $me = $this->member('me@bell.dev');
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-t', 'status' => 'active']);
        $foreign = AppNotification::withoutGlobalScopes()->create(['tenant_id' => $otherTenant->id, 'user_id' => null, 'type' => 'x', 'title' => 't', 'status' => 'unread', 'severity' => 'info']);

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$foreign->id}/read")->assertStatus(404);
        $this->assertDatabaseMissing('notification_reads', ['notification_id' => $foreign->id]);
    }

    // ---- through the real sweep ------------------------------------------------------------------------------------

    private function sweepFixture(): AlertRule
    {
        $c = UnifiedCampaign::create(['tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'name' => 'Camp', 'objective' => 'conversions', 'status' => 'active', 'budget_currency' => 'SAR', 'meta' => ['adopted_from' => 'sync']]);
        DB::table('unified_campaigns')->where('id', $c->id)->update(['created_at' => '2026-11-05 10:00:00+00', 'updated_at' => '2026-11-05 10:00:00+00']);
        $credential = new IntegrationCredential(['provider' => 'snapchat', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::create(['tenant_id' => $this->agency->id, 'credential_id' => $credential->id, 'provider' => 'snapchat', 'connection_name' => 'snapchat', 'scope' => 'project_only', 'status' => 'connected']);
        $account = (string) Str::uuid();
        DB::table('external_accounts')->insert(['id' => $account, 'tenant_id' => $this->agency->id, 'provider_connection_id' => $connection->id, 'provider' => 'snapchat', 'account_type' => 'ad_account', 'external_id' => 'a-'.uniqid(), 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('external_campaigns')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $c->id, 'external_account_id' => $account, 'provider' => 'snapchat', 'external_id' => 'e-'.uniqid(), 'name' => $c->name, 'status' => 'active', 'last_synced_at' => '2026-11-13 06:57:00+00', 'created_at' => '2026-11-05 10:00:00+00', 'updated_at' => '2026-11-13 06:57:00+00']);
        foreach (['2026-11-10' => 30, '2026-11-11' => 25, '2026-11-12' => 27] as $date => $spend) {
            foreach (['spend' => $spend, 'impressions' => 1000, 'conversions' => 0] as $key => $value) {
                DB::table('daily_metrics')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $c->id, 'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => $date, 'value' => $value, 'project_currency' => 'SAR', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return AlertRule::create(['tenant_id' => $this->agency->id, 'type' => 'no_results', 'name' => 'No results', 'threshold' => ['days' => 3], 'cooldown_minutes' => 0, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true]);
    }

    public function test_the_real_sweep_names_the_alert_by_its_type_and_a_second_sweep_the_same_day_updates_it(): void
    {
        $me = $this->member('me@bell.dev');
        Carbon::setTestNow(Carbon::parse('2026-11-13 05:15:00', 'UTC'));
        $rule = $this->sweepFixture();

        app(AlertEvaluator::class)->evaluateRule($rule);
        Carbon::setTestNow(Carbon::parse('2026-11-13 09:15:00', 'UTC')); // four hours on, same Riyadh day, past the old window
        app(AlertEvaluator::class)->evaluateRule($rule);

        $rows = $this->bell($me)['data'];
        $this->assertCount(1, $rows, 'one finding, one notification');
        // The preference category is unchanged; the bell names the row by its kind.
        $this->assertSame('budget_risk', $rows[0]['type']);
        $this->assertSame('صرف بدون نتائج', $rows[0]['kind_label']);
        $this->assertGreaterThanOrEqual(2, $rows[0]['repeat_count']);
    }
}
