<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — routing by role + project reach, de-dup, grouping, quiet hours, plan refusals (spec §3, §16 internal). */
final class RoutingAndOutboxTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // Sunday 15 Nov 2026, 12:00 Riyadh: a work day, outside quiet hours.
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha', 'agency');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function raise(string $event, ?string $projectId, array $extra = []): array
    {
        app()->forgetScopedInstances();

        return app(NotificationEngine::class)->raise(new DeliveryIntent(
            eventKey: $event, tenantId: $this->tenant->id, projectId: $projectId,
            objectType: $extra['objectType'] ?? 'campaign', objectId: $extra['objectId'] ?? 'c-1', threshold: $extra['threshold'] ?? null,
            payload: $extra['payload'] ?? [],
        ));
    }

    /** @return list<object> */
    private function outbox(?User $user = null, ?string $channel = null): array
    {
        return DB::table('notification_outbox')->where('tenant_id', $this->tenant->id)
            ->when($user !== null, fn ($q) => $q->where('recipient_user_id', $user->id))
            ->when($channel !== null, fn ($q) => $q->where('channel', $channel))->get()->all();
    }

    private function confine(User $user, string $projectId): void
    {
        $membership = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->firstOrFail();
        MembershipScope::create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_PROJECT, 'scope_id' => $projectId]);
    }

    public function test_a_member_not_assigned_to_the_project_receives_nothing(): void
    {
        $a = $this->project($this->tenant, 'A');
        $b = $this->project($this->tenant, 'B');
        $owner = $this->member($this->tenant, 'tenant-owner');
        $pm = $this->member($this->tenant, 'project-manager');
        $this->confine($pm, $b->id);

        $this->raise('sync.failed', $a->id);

        $this->assertNotEmpty($this->outbox($owner));
        $this->assertSame([], $this->outbox($pm));
    }

    public function test_a_client_portal_user_never_gets_a_sensitive_event_and_only_their_projects(): void
    {
        $a = $this->project($this->tenant, 'A');
        $portal = $this->member($this->tenant, 'client-viewer', [], 'portal');
        $this->confine($portal, $a->id);

        $this->raise('sync.failed', $a->id);
        $this->raise('budget.overrun', $a->id);
        $this->assertSame([], $this->outbox($portal));

        $this->raise('project.created', $a->id);
        $this->assertCount(1, $this->outbox($portal));
    }

    public function test_role_routing_follows_the_catalog_and_a_workspace_override(): void
    {
        $a = $this->project($this->tenant, 'A');
        $buyer = $this->member($this->tenant, 'media-buyer'); // profile role «other»
        $owner = $this->member($this->tenant, 'tenant-owner');

        $this->raise('connection.expired', $a->id);
        $this->assertSame([], $this->outbox($buyer));
        $this->assertNotEmpty($this->outbox($owner));

        DB::table('workspace_notification_settings')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'routing' => json_encode(['data.late' => ['roles' => ['other'], 'channels' => ['in_app']]]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->raise('data.late', $a->id);
        $this->assertCount(1, $this->outbox($buyer, 'in_app'));
        $this->assertSame([], array_filter($this->outbox($owner), static fn ($r) => $r->event_key === 'data.late'));
    }

    public function test_the_same_fact_on_the_same_day_is_one_delivery(): void
    {
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');

        $first = $this->raise('budget.threshold_100', $a->id, ['objectType' => 'budget', 'objectId' => 'b-1', 'threshold' => '100']);
        $again = $this->raise('budget.threshold_100', $a->id, ['objectType' => 'budget', 'objectId' => 'b-1', 'threshold' => '100']);

        $this->assertGreaterThan(0, $first['queued']);
        $this->assertSame(0, $again['queued']);
        $this->assertSame($first['queued'], $again['duplicates']);

        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00', 'UTC'));
        $this->assertGreaterThan(0, $this->raise('budget.threshold_100', $a->id, ['objectType' => 'budget', 'objectId' => 'b-1', 'threshold' => '100'])['queued']);
        $this->assertNotEmpty($this->outbox($owner));
    }

    public function test_three_similar_events_in_the_window_become_one_grouped_delivery(): void
    {
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');

        foreach (['c-1', 'c-2', 'c-3'] as $campaign) {
            $this->raise('campaign.stopped_spending', $a->id, ['objectId' => $campaign]);
        }

        $rows = $this->outbox($owner, 'in_app');
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]->group_count);
        $this->assertCount(3, json_decode($rows[0]->payload, true)['_objects']);
        // A repeat of one of them is still a duplicate, even inside the group.
        $this->assertSame(0, $this->raise('campaign.stopped_spending', $a->id, ['objectId' => 'c-2'])['grouped']);
    }

    public function test_in_quiet_hours_critical_goes_now_and_info_waits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 20:00:00', 'UTC')); // 23:00 Riyadh, inside the 21:00–08:00 default
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');

        $this->raise('connection.expired', $a->id);   // critical
        $this->raise('connection.expiring', $a->id);  // important

        $critical = collect($this->outbox($owner, 'email'))->firstWhere('event_key', 'connection.expired');
        $important = collect($this->outbox($owner, 'email'))->firstWhere('event_key', 'connection.expiring');
        $this->assertNull($critical->not_before);
        $this->assertSame('2026-11-16 05:00:00', Carbon::parse($important->not_before)->utc()->format('Y-m-d H:i:s')); // 08:00 Riyadh, Monday
        $this->assertSame('deferred', DB::table('notification_delivery_log')->where('delivery_id', $important->id)->value('status'));
    }

    public function test_a_holiday_defers_internal_and_external_alike_but_not_internal_critical(): void
    {
        DB::table('workspace_notification_settings')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'holidays' => json_encode(['2026-11-15']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $this->project($this->tenant, 'A');
        $owner = $this->member($this->tenant, 'tenant-owner');

        $this->raise('connection.expired', $a->id);
        $this->raise('data.late', $a->id);

        $this->assertNull(collect($this->outbox($owner, 'email'))->firstWhere('event_key', 'connection.expired')->not_before);
        $this->assertNotNull(collect($this->outbox($owner, 'email'))->firstWhere('event_key', 'data.late')->not_before);
    }

    public function test_a_disabled_plan_key_is_logged_as_blocked_by_plan_and_nothing_is_queued(): void
    {
        $a = $this->project($this->tenant, 'A');
        $this->member($this->tenant, 'tenant-owner');
        DB::table('notification_plan_features')->insert([
            'id' => (string) Str::uuid(), 'key' => 'notifications.internal', 'enabled' => false, 'grace_days' => 14, 'keep_existing' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->raise('sync.failed', $a->id);

        $this->assertSame(0, $result['queued']);
        $this->assertGreaterThan(0, $result['refused']);
        $this->assertSame(0, DB::table('notification_outbox')->where('state', 'pending')->count());
        $this->assertSame(['blocked_by_plan'], DB::table('notification_delivery_log')->distinct()->pluck('status')->all());
    }

    public function test_nothing_crosses_workspaces(): void
    {
        $a = $this->project($this->tenant, 'A');
        $this->member($this->tenant, 'tenant-owner');
        $other = $this->tenant('Other', 'agency');
        $stranger = $this->member($other, 'tenant-owner');
        $this->tenant = Tenant::query()->whereKey($a->tenant_id)->firstOrFail();

        $this->raise('sync.failed', $a->id);

        $this->assertSame(0, DB::table('notification_outbox')->where('recipient_user_id', $stranger->id)->count());
        $this->assertSame(0, DB::table('notification_outbox')->where('tenant_id', $other->id)->count());
    }
}
