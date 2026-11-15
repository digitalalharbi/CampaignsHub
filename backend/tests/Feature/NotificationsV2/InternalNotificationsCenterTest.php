<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Internal\EngineKinds;
use App\Domains\Notifications\Internal\InternalEvents;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/**
 * NOTIF-V2-INTERNAL-001 — the internal catalog events through the shared engine and the notification centre (Owner spec §5, §16
 * «Internal»): who hears what, active projects only, client-portal privacy, the centre's tabs / filters / snooze / mute, and
 * «mark all read» with 300+ unread.
 */
final class InternalNotificationsCenterTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC')); // Sunday 12:00 Riyadh, a work day outside quiet hours
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha Agency', 'agency');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function events(): InternalEvents
    {
        app()->forgetScopedInstances();

        return app(InternalEvents::class);
    }

    private function send(): void
    {
        // A grouping window (10 min) holds a groupable event so a burst becomes one; the cron then finds it due.
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        Artisan::call('notifications:v2-send');
    }

    /** @return list<object> */
    private function outbox(User $user, ?string $event = null): array
    {
        return DB::table('notification_outbox')->where('tenant_id', $this->tenant->id)->where('recipient_user_id', $user->id)
            ->when($event !== null, fn ($q) => $q->where('event_key', $event))->get()->all();
    }

    private function confine(User $user, string $projectId): void
    {
        $membership = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->firstOrFail();
        MembershipScope::create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_PROJECT, 'scope_id' => $projectId]);
    }

    public function test_every_section_5_event_is_in_the_catalog_with_both_languages_and_a_page(): void
    {
        $registry = app(EventRegistry::class);
        foreach (EngineKinds::events() as $key) {
            $event = $registry->get($key);
            $this->assertNotNull($event, $key);
            if ($event->scope->value !== 'external') {
                foreach (['ar', 'en'] as $locale) {
                    $this->assertNotNull(EngineKinds::label($key, $locale === 'ar'), "{$key} {$locale}");
                }
            }
            $this->assertNotNull(EngineKinds::path($key, 'p1'), $key);
        }
    }

    public function test_an_event_reaches_only_people_assigned_to_the_project_and_names_project_and_workspace(): void
    {
        $a = $this->project($this->tenant, 'A');
        $b = $this->project($this->tenant, 'B');
        $owner = $this->member($this->tenant, 'tenant-owner');
        $pm = $this->member($this->tenant, 'project-manager');
        $this->confine($pm, $b->id);

        $this->events()->raise('sync.failed', $this->tenant->id, $a->id);
        $this->send();

        $this->assertNotEmpty($this->outbox($owner));
        $this->assertSame([], $this->outbox($pm));
        $row = AppNotification::withoutGlobalScopes()->where('user_id', $owner->id)->where('kind', 'sync.failed')->firstOrFail();
        $this->assertStringContainsString('A', (string) $row->message);
        $this->assertSame($a->id, $row->project_id);

        $json = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertSame('A', $json['project_name']);
        $this->assertSame('Alpha Agency', $json['workspace_name']);
        $this->assertSame('important', $json['priority']);
        $this->assertSame('/integrations?project='.$a->id, $json['open_url']);
    }

    public function test_only_active_projects_raise_operational_events(): void
    {
        $archived = $this->project($this->tenant, 'Old', 'archived');
        $paused = $this->project($this->tenant, 'Paused', 'paused');
        $live = $this->project($this->tenant, 'Live');
        $owner = $this->member($this->tenant, 'tenant-owner');

        $this->events()->raise('sync.failed', $this->tenant->id, $archived->id);
        $this->events()->raise('budget.overrun', $this->tenant->id, $paused->id);
        $this->assertSame([], $this->outbox($owner));

        $this->events()->raise('sync.failed', $this->tenant->id, $live->id);
        $this->assertNotEmpty($this->outbox($owner));
    }

    public function test_a_client_portal_user_gets_their_project_without_sensitive_events_and_in_positive_words(): void
    {
        $a = $this->project($this->tenant, 'A');
        $b = $this->project($this->tenant, 'B');
        $portal = $this->member($this->tenant, 'client-viewer', [], 'portal');
        $this->confine($portal, $a->id);

        foreach (['sync.failed', 'budget.overrun', 'spend_limit.reached', 'campaign.spend_without_results', 'plan.grace_ending', 'external.held_for_review'] as $sensitive) {
            $this->events()->raise($sensitive, $this->tenant->id, $a->id);
        }
        $this->assertSame([], $this->outbox($portal));

        $this->events()->raise('project.created', $this->tenant->id, $a->id);
        $this->events()->raise('project.created', $this->tenant->id, $b->id);
        $rows = $this->outbox($portal);
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]->project_id);
    }

    public function test_a_budget_change_says_who_and_before_and_after_in_its_own_words(): void
    {
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');
        $editor = $this->member($this->tenant, 'finance');

        $this->events()->raise('budget.changed', $this->tenant->id, $a->id, ['variant' => 'edited', 'before' => '1,000 SAR', 'after' => '1,500 SAR'], $editor->id, 'funding_payment', 'x1');
        $this->send();

        $row = AppNotification::withoutGlobalScopes()->where('user_id', $owner->id)->where('kind', 'budget.changed')->firstOrFail();
        $this->assertStringContainsString($editor->name, (string) $row->message);
        $this->assertStringContainsString('1,000 SAR', (string) $row->message);
        $this->assertStringContainsString('1,500 SAR', (string) $row->message);
        $this->assertStringContainsString('1,500 SAR', (string) $row->message_ar);
        $this->assertSame('A budget was edited', $row->title);
        $this->assertSame('عُدّلت ميزانية', $row->title_ar);
    }

    public function test_critical_in_quiet_hours_is_delivered_and_info_is_deferred_by_the_engine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 20:30:00', 'UTC')); // 23:30 Riyadh
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');

        $this->events()->raise('budget.overrun', $this->tenant->id, $a->id);
        $this->events()->raise('data.late', $this->tenant->id, $a->id);

        $critical = DB::table('notification_outbox')->where('recipient_user_id', $owner->id)->where('event_key', 'budget.overrun')->where('channel', 'email')->first();
        $info = DB::table('notification_outbox')->where('recipient_user_id', $owner->id)->where('event_key', 'data.late')->where('channel', 'email')->first();
        $this->assertNull($critical->deferred_reason);
        $this->assertSame('quiet_hours', $info->deferred_reason);
    }

    public function test_three_similar_events_become_one_grouped_notification(): void
    {
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');

        foreach (['c1', 'c2', 'c3'] as $c) {
            $this->events()->raise('campaign.cost_per_result_up', $this->tenant->id, $a->id, [], null, 'campaign', $c);
        }
        $rows = $this->outbox($owner, 'campaign.cost_per_result_up');
        $this->assertCount(2, $rows); // in_app + email, one each
        $this->assertSame([3, 3], array_map(static fn ($r) => (int) $r->group_count, $rows));
    }

    public function test_unread_count_drops_to_zero_for_300_plus_and_a_colleague_keeps_theirs(): void
    {
        $a = $this->project($this->tenant, 'A');
        $me = $this->member($this->tenant, 'tenant-owner');
        $other = $this->member($this->tenant, 'tenant-owner');
        for ($i = 0; $i < 320; $i++) {
            AppNotification::create(['tenant_id' => $this->tenant->id, 'project_id' => $a->id, 'user_id' => null, 'type' => 'budget_risk', 'kind' => 'budget_90', 'title' => 't', 'message' => 'm',
                'status' => 'unread', 'severity' => 'warning', 'source' => 'alerts', 'entity_id' => 'e'.$i, 'dedup_key' => 'k'.$i]);
        }
        $this->assertSame(320, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));

        $this->actingAs($me, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame(0, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));
        $this->assertSame(320, $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));
    }

    public function test_the_centre_tabs_and_filters_by_project_type_and_priority(): void
    {
        $a = $this->project($this->tenant, 'A');
        $b = $this->project($this->tenant, 'B');
        $me = $this->member($this->tenant, 'tenant-owner');
        $mk = fn (string $project, string $kind, string $severity, string $status = 'unread') => AppNotification::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project, 'user_id' => $me->id, 'type' => 'budget_risk', 'kind' => $kind, 'title' => $kind, 'message' => 'm',
            'status' => $status, 'severity' => $severity, 'source' => 'x', 'entity_id' => Str::random(6), 'dedup_key' => Str::random(12),
        ]);
        $mk($a->id, 'budget_90', 'warning');
        $mk($a->id, 'sync_failed', 'critical');
        $mk($b->id, 'budget_90', 'warning', 'read');

        $get = fn (string $q) => collect($this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications?'.$q)->assertOk()->json('data'));
        $this->assertCount(3, $get(''));
        $this->assertCount(2, $get('tab=unread'));
        $this->assertCount(2, $get('project_id='.$a->id));
        $this->assertCount(2, $get('type=budget_90'));
        $this->assertCount(1, $get('priority=critical'));
        $this->assertCount(1, $get('tab=unread&project_id='.$a->id.'&priority=important'));

        $facets = $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications?project_id='.$a->id)->json('meta.facets');
        $this->assertEqualsCanonicalizing(['A', 'B'], array_column($facets['projects'], 'name'), 'filters offer every project, not only the filtered one');
    }

    public function test_snooze_hides_a_notification_until_its_time_and_only_for_me(): void
    {
        $a = $this->project($this->tenant, 'A');
        $me = $this->member($this->tenant, 'tenant-owner');
        $other = $this->member($this->tenant, 'tenant-owner');
        $n = AppNotification::create(['tenant_id' => $this->tenant->id, 'project_id' => $a->id, 'user_id' => null, 'type' => 'budget_risk', 'kind' => 'budget_90', 'title' => 't', 'message' => 'm',
            'status' => 'unread', 'severity' => 'warning', 'source' => 'alerts', 'entity_id' => 'e', 'dedup_key' => 'k']);

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/snooze", ['preset' => '1h'])->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame(0, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));
        $this->assertSame(1, $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));

        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->assertSame(1, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'), 'it returns, unread, when the hour is over');

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/snooze", ['preset' => 'tomorrow'])->assertOk();
        $until = Carbon::parse(DB::table('notification_user_snoozes')->where('user_id', $me->id)->value('snoozed_until'));
        $this->assertSame('2026-11-16 05:00:00', $until->utc()->toDateTimeString(), '08:00 tomorrow in the workspace timezone (Riyadh)');
    }

    public function test_mute_this_type_for_this_project_is_personal_and_reversible(): void
    {
        $a = $this->project($this->tenant, 'A');
        $b = $this->project($this->tenant, 'B');
        $me = $this->member($this->tenant, 'tenant-owner');
        $other = $this->member($this->tenant, 'tenant-owner');
        $mk = fn (string $project) => AppNotification::create(['tenant_id' => $this->tenant->id, 'project_id' => $project, 'user_id' => null, 'type' => 'budget_risk', 'kind' => 'budget_90',
            'title' => 't', 'message' => 'm', 'status' => 'unread', 'severity' => 'warning', 'source' => 'alerts', 'entity_id' => $project, 'dedup_key' => 'k'.$project]);
        $na = $mk($a->id);
        $mk($b->id);

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$na->id}/mute")->assertOk()->assertJsonPath('data.unread', 1);
        $this->assertSame(1, $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'), 'project B still arrives');
        $this->assertSame(2, $this->actingAs($other, 'sanctum')->getJson('/api/v1/notifications/summary')->json('data.unread'));

        // The legacy kind and the engine event are muted together.
        $this->assertSame(1, AppNotification::withoutGlobalScopes()->where('id', $na->id)->count());
        $mutes = $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/mutes')->assertOk()->json('data');
        $this->assertCount(1, $mutes);
        $this->assertSame('A', $mutes[0]['project_name']);

        $this->actingAs($me, 'sanctum')->postJson('/api/v1/notifications/mutes/remove', ['ids' => $mutes[0]['ids']])->assertOk()->assertJsonPath('data.unread', 2);
    }

    public function test_one_cannot_snooze_or_mute_what_one_cannot_see_in_another_workspace(): void
    {
        $a = $this->project($this->tenant, 'A');
        $me = $this->member($this->tenant, 'tenant-owner');
        $foreign = $this->tenant('Beta', 'agency');
        $fp = $this->project($foreign, 'F');
        $n = AppNotification::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'project_id' => $fp->id, 'user_id' => null, 'type' => 'budget_risk', 'kind' => 'budget_90', 'title' => 't', 'message' => 'm',
            'status' => 'unread', 'severity' => 'warning', 'source' => 'alerts', 'entity_id' => 'f', 'dedup_key' => 'f']);

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/snooze", ['preset' => '1h'])->assertNotFound();
        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/mute")->assertNotFound();
        $this->assertSame(0, DB::table('notification_user_snoozes')->count() + DB::table('notification_mutes')->count());
    }

    public function test_with_the_internal_key_off_the_server_refuses_new_actions_and_the_bell_still_lists(): void
    {
        $a = $this->project($this->tenant, 'A');
        $me = $this->member($this->tenant, 'tenant-owner');
        $n = AppNotification::create(['tenant_id' => $this->tenant->id, 'project_id' => $a->id, 'user_id' => $me->id, 'type' => 'budget_risk', 'kind' => 'budget_90', 'title' => 't', 'message' => 'm',
            'status' => 'unread', 'severity' => 'warning', 'source' => 'alerts', 'entity_id' => 'e', 'dedup_key' => 'k']);
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => 'notifications.internal', 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
        $this->assertFalse(app(EntitlementGate::class)->allows($this->tenant->id, EntitlementKey::Internal));

        $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/snooze", ['preset' => '1h'])->assertForbidden()->assertJsonPath('meta.upgrade', true);
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications')->assertOk();

        $a2 = $this->project($this->tenant, 'A2');
        $this->events()->raise('sync.failed', $this->tenant->id, $a2->id);
        $this->assertSame('blocked_by_plan', DB::table('notification_delivery_log')->where('event_key', 'sync.failed')->value('status'));
    }
}
