<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Push\PushTypes;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-INTERNAL-001 — the new event types ride builder L's push switches and sound rules (spec §5: sound only important/critical). */
final class InternalPushAndSoundTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC')); // 12:00 Riyadh, outside the personal quiet hours
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha', 'agency');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $kind, string $severity, array $over = []): AppNotification
    {
        return AppNotification::create($over + ['tenant_id' => $this->tenant->id, 'project_id' => null, 'user_id' => null, 'type' => 'message', 'kind' => $kind, 'title' => 't', 'message' => 'm',
            'status' => 'unread', 'severity' => $severity, 'source' => 'notifications_v2', 'entity_id' => Str::random(6), 'dedup_key' => Str::random(12)]);
    }

    public function test_catalog_events_are_pushed_under_the_same_five_switches_and_nothing_else_is(): void
    {
        $this->assertSame(PushTypes::BUDGET_90, PushTypes::of($this->row('budget.threshold_90', 'warning')));
        $this->assertSame(PushTypes::BUDGET_100, PushTypes::of($this->row('budget.threshold_100', 'critical')));
        $this->assertSame(PushTypes::BUDGET_100, PushTypes::of($this->row('budget.overrun', 'critical')));
        $this->assertSame(PushTypes::BUDGET_100, PushTypes::of($this->row('spend_limit.reached', 'critical')));
        $this->assertSame(PushTypes::SYNC_FAILED, PushTypes::of($this->row('sync.failed', 'warning')));
        $this->assertSame(PushTypes::SYNC_FAILED, PushTypes::of($this->row('connection.expired', 'critical')));
        $this->assertSame(PushTypes::APPROVAL, PushTypes::of($this->row('recommendation.awaiting_approval', 'warning')));
        $this->assertSame(PushTypes::CRITICAL, PushTypes::of($this->row('plan.grace_ending', 'critical')));

        foreach (['budget.changed', 'project.created', 'followup.morning_brief', 'external.recipient_unsubscribed', 'data.late', 'campaign.cost_per_result_up'] as $quiet) {
            $this->assertNull(PushTypes::of($this->row($quiet, 'info')), "{$quiet} stays in the centre");
        }
    }

    public function test_the_sound_cue_names_only_important_and_critical_never_info(): void
    {
        $me = $this->member($this->tenant, 'tenant-owner');
        $info = $this->row('budget.changed', 'info', ['user_id' => $me->id]);
        $important = $this->row('sync.failed', 'warning', ['user_id' => $me->id]);
        $critical = $this->row('budget.overrun', 'critical', ['user_id' => $me->id]);

        $attention = collect($this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->assertOk()->json('data.attention'))->pluck('severity', 'id');

        $this->assertSame(['warning', 'critical'], [$attention[$important->id], $attention[$critical->id]]);
        $this->assertFalse($attention->has($info->id));
    }

    public function test_the_sound_stays_silent_in_the_persons_quiet_hours(): void
    {
        $me = $this->member($this->tenant, 'tenant-owner');
        Carbon::setTestNow(Carbon::parse('2026-11-15 20:30:00', 'UTC')); // 23:30 Riyadh, inside the default 22:00-08:00

        $this->assertTrue($this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/summary')->assertOk()->json('data.sound.quiet'));
    }
}
