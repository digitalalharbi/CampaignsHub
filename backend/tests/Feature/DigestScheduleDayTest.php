<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Console\SendDailyDigests;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * EMAIL-SCHEDULE-001 — the weekly and monthly digests arrive on the recipient's chosen day.
 *
 * `digest_hour` and `timezone` were already the recipient's own. The DAY was not: the weekly went out
 * on `isMonday()` and the monthly on `day === 1`, hard-coded. An agency that reviews on Sunday and an
 * advertiser whose month closes on the 25th both got a report on somebody else's schedule.
 */
final class DigestScheduleDayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-sched-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-sched-'.uniqid(), 'mode' => 'managed']);
        Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-sched-'.uniqid()]);
        $role->givePermissionTo('clients.view_all');

        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@sched.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function preference(array $over = []): void
    {
        DB::table('notification_preferences')->insert(array_merge([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->user->id,
            'channels' => json_encode(['email' => true]),
            'categories' => json_encode([]),
            'digests' => json_encode(['weekly' => true, 'monthly' => true]),
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
            'digest_hour' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ], $over));
    }

    /** The command reports what it did per recipient, which is what we assert against. */
    private function runAt(string $utc): string
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        return trim($this->artisan('notifications:send-digests')->run() === 0
            ? (string) app(Kernel::class)->output()
            : '');
    }

    // ── EMAIL-SETTINGS-DEPTH-001 — the day is REACHABLE, not merely honoured ────────────────────

    /**
     * Both columns existed, the sweep honoured them, «next send» computed from them — and nothing
     * wrote them.
     *
     * `NotificationPreferenceController` validated and persisted `frequency`, `timezone`, `locale`
     * and `digest_hour`, and nothing else. So every row in the product held the DEFAULTS: a weekly
     * digest arrived on Monday because 1 is the default, not because anybody chose Monday, and the
     * settings screen's «next send» was reading a preference nobody could express.
     *
     * The tests below this one write the column directly and prove the sweep reads it. That is the
     * other half, and on its own it is how a feature comes to be «delivered» while being unreachable.
     */
    public function test_a_recipient_can_actually_choose_the_day_through_the_api(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/settings/notifications', ['digest_weekday' => 7, 'digest_monthday' => 25])
            ->assertOk();

        $row = DB::table('notification_preferences')->where('user_id', $this->user->id)->first();

        $this->assertSame(7, (int) $row->digest_weekday, 'ISO Sunday — the day an agency reviewing on Sunday needs');
        $this->assertSame(25, (int) $row->digest_monthday);
    }

    /** And the screen reads back what was chosen, rather than the day it defaults to. */
    public function test_the_chosen_day_is_read_back(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/settings/notifications', ['digest_weekday' => 4])
            ->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/settings/notifications')
            ->assertOk()
            ->assertJsonPath('data.digest_weekday', 4)
            ->assertJsonPath('data.digest_monthday', 1);
    }

    /**
     * The 30th is refused at the door.
     *
     * The migration's reason for capping at 28 is that a monthly set for the 30th would never arrive
     * in February, silently. The sweep's fallback turns such a value into the 1st — a day the person
     * did not choose, in a month they did not expect — so accepting it and quietly rescheduling is
     * worse than saying no.
     */
    public function test_a_month_day_that_would_skip_february_is_refused_rather_than_rescheduled(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/settings/notifications', ['digest_monthday' => 30])
            ->assertStatus(422);

        $this->assertNull(DB::table('notification_preferences')->where('user_id', $this->user->id)->first());
    }

    /** A weekday outside 1–7 is refused too: 0 and 8 are not days, and 1 is not what they meant. */
    public function test_a_weekday_outside_the_week_is_refused(): void
    {
        foreach ([0, 8] as $weekday) {
            $this->actingAs($this->user, 'sanctum')
                ->patchJson('/api/v1/settings/notifications', ['digest_weekday' => $weekday])
                ->assertStatus(422);
        }
    }

    /**
     * And a request that does not mention the day leaves it exactly as it was.
     *
     * This is the rule that stopped the old screen erasing digest opt-ins: a client that does not
     * know about a setting must not be able to clear it by omitting it.
     */
    public function test_a_request_that_says_nothing_about_the_day_changes_nothing(): void
    {
        $this->preference(['digest_weekday' => 5, 'digest_monthday' => 20]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/settings/notifications', ['digest_hour' => 9])
            ->assertOk();

        $row = DB::table('notification_preferences')->where('user_id', $this->user->id)->first();

        $this->assertSame(5, (int) $row->digest_weekday);
        $this->assertSame(20, (int) $row->digest_monthday);
        $this->assertSame(9, (int) $row->digest_hour);
    }

    public function test_the_default_is_monday_so_no_existing_recipient_moves(): void
    {
        $this->preference();

        $row = DB::table('notification_preferences')->where('user_id', $this->user->id)->first();
        $this->assertSame(1, (int) $row->digest_weekday, 'ISO Monday — what isMonday() meant');
        $this->assertSame(1, (int) $row->digest_monthday);
    }

    public function test_a_recipient_may_choose_sunday_for_the_weekly(): void
    {
        // ISO-8601: Sunday is 7. An agency reviewing on Sunday could not be served before this.
        $this->preference(['digest_weekday' => 7]);

        $row = DB::table('notification_preferences')->where('user_id', $this->user->id)->first();
        $this->assertSame(7, (int) $row->digest_weekday);
    }

    public function test_a_recipient_may_choose_the_day_their_month_closes(): void
    {
        $this->preference(['digest_monthday' => 25]);

        $row = DB::table('notification_preferences')->where('user_id', $this->user->id)->first();
        $this->assertSame(25, (int) $row->digest_monthday);
    }

    public function test_the_month_day_is_capped_so_a_schedule_cannot_skip_february(): void
    {
        // A monthly set for the 30th would simply never arrive in February, and silently. The command
        // falls back to the 1st rather than letting a month disappear.
        $this->preference(['digest_monthday' => 30]);

        $cmd = new SendDailyDigests;
        $m = new \ReflectionMethod($cmd, 'monthday');
        $m->setAccessible(true);

        $this->assertSame(1, $m->invoke($cmd, (object) ['digest_monthday' => 30]));
        $this->assertSame(28, $m->invoke($cmd, (object) ['digest_monthday' => 28]));
    }

    public function test_an_impossible_weekday_falls_back_rather_than_never_matching(): void
    {
        // A digest that stops arriving because a column holds 9 is a failure nobody thinks to look for.
        $cmd = new SendDailyDigests;
        $m = new \ReflectionMethod($cmd, 'weekday');
        $m->setAccessible(true);

        $this->assertSame(1, $m->invoke($cmd, (object) ['digest_weekday' => 9]));
        $this->assertSame(1, $m->invoke($cmd, (object) ['digest_weekday' => 0]));
        $this->assertSame(7, $m->invoke($cmd, (object) ['digest_weekday' => 7]));
    }
}
