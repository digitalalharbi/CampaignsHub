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
