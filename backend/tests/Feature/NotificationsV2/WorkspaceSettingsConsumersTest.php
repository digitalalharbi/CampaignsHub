<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Followup\FollowupTasks;
use App\Domains\Notifications\Followup\MorningBriefRunner;
use App\Domains\Notifications\Internal\InternalEvents;
use App\Domains\Notifications\Services\SoundPreferences;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RELEASE-INTEGRATION — the internal settings builder R's page saves are read by the engine and the follow-up assistant
 * (builders M and P): a workspace priority per event, the default sound, the follow-up task switch and the brief defaults.
 * Every one is a DEFAULT: without a saved value the behaviour is the one each builder shipped.
 */
final class WorkspaceSettingsConsumersTest extends TestCase
{
    use Concerns\BuildsWorkspaces;
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

    /** @param array<string, mixed> $settings */
    private function settings(array $settings): void
    {
        DB::table('workspace_notification_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
    }

    public function test_a_workspace_priority_overrides_the_catalog_for_internal_deliveries_only(): void
    {
        $project = $this->project($this->tenant, 'Launch');
        $owner = $this->member($this->tenant);
        app(InternalEvents::class)->raise('sync.failed', $this->tenant->id, $project->id);
        $catalog = (string) DB::table('notification_outbox')->where('recipient_user_id', $owner->id)->value('priority');
        $this->assertNotSame('critical', $catalog);

        DB::table('notification_outbox')->delete();
        $this->settings(['priorities' => ['sync.failed' => 'critical']]);
        app(InternalEvents::class)->raise('sync.failed', $this->tenant->id, $project->id, [], null, 'test', 'second');
        $this->assertSame(['critical'], DB::table('notification_outbox')->where('recipient_user_id', $owner->id)->distinct()->pluck('priority')->all());

        DB::table('notification_outbox')->delete();
        DB::table('workspace_notification_settings')->where('tenant_id', $this->tenant->id)->update(['settings' => json_encode(['priorities' => ['sync.failed' => 'nonsense']])]);
        app()->forgetScopedInstances();
        app(InternalEvents::class)->raise('sync.failed', $this->tenant->id, $project->id, [], null, 'test', 'third');
        $this->assertSame([$catalog], DB::table('notification_outbox')->where('recipient_user_id', $owner->id)->distinct()->pluck('priority')->all(), 'an unknown value keeps the catalog priority');
    }

    public function test_the_default_sound_applies_until_a_person_chooses(): void
    {
        $user = $this->member($this->tenant);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertTrue(app(SoundPreferences::class)->stored($user)['enabled'], 'default on');

        $this->settings(['default_sound' => false]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertFalse(app(SoundPreferences::class)->stored($user)['enabled']);

        app(SoundPreferences::class)->save($user, true, null);
        $this->assertTrue(app(SoundPreferences::class)->stored($user)['enabled'], 'the person\'s own choice wins');
    }

    public function test_the_follow_up_switch_off_opens_no_new_task_and_keeps_the_old_ones(): void
    {
        $project = $this->project($this->tenant, 'Launch');
        $this->member($this->tenant);
        $kept = app(FollowupTasks::class)->open($this->tenant->id, 'set_budget', $project->id);
        $this->assertNotNull($kept);

        $this->settings(['followup' => ['tasks' => false]]);
        $other = $this->project($this->tenant, 'Second');
        $this->assertNull(app(FollowupTasks::class)->open($this->tenant->id, 'set_budget', $other->id));
        $this->assertSame(1, DB::table('followup_tasks')->where('tenant_id', $this->tenant->id)->where('status', 'open')->count());
    }

    public function test_the_brief_follows_the_workspace_default_time_until_a_person_sets_their_own(): void
    {
        $this->project($this->tenant, 'Launch');
        $this->member($this->tenant);
        Carbon::setTestNow(Carbon::parse('2026-11-15 04:00:00', 'UTC')); // 07:00 Riyadh, before the 08:00 default

        app()->forgetScopedInstances();
        $this->assertSame(0, app(MorningBriefRunner::class)->run());

        $this->settings(['followup' => ['morning_time' => '06:30']]);
        $this->assertSame(1, app(MorningBriefRunner::class)->run());
    }
}
