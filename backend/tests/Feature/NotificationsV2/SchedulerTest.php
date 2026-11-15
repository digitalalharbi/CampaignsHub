<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Contracts\Scheduler;
use App\Domains\Notifications\Engine\Data\Subscription;
use App\Domains\Notifications\Engine\Scheduling\DigestPeriods;
use App\Domains\Notifications\Engine\Scheduling\DigestRunner;
use App\Domains\Notifications\Engine\Scheduling\SubscriptionScheduler;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — periodic digests: periods end yesterday, weekdays, week start, combinations (spec §10, §16). */
final class SchedulerTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 05:00:00', 'UTC'));
        $this->tenant = $this->tenant('Alpha', 'agency');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $over */
    private function subscription(array $projectIds, array $over = [], string $recipientStatus = 'active'): string
    {
        $recipient = (string) Str::uuid();
        DB::table('notification_external_recipients')->insert(['id' => $recipient, 'tenant_id' => $this->tenant->id, 'email' => uniqid().'@client.test', 'status' => $recipientStatus, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('notification_subscriptions')->insert(array_merge([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'recipient_id' => $recipient, 'status' => 'active',
            'period_key' => 'this_month_to_yesterday', 'frequency' => 'daily', 'send_time' => '08:00', 'timezone' => 'Asia/Riyadh',
            'event_keys' => json_encode(['digest.periodic']), 'created_at' => now(), 'updated_at' => now(),
        ], $over));
        foreach ($projectIds as $p) {
            DB::table('notification_subscription_projects')->insert(['subscription_id' => $id, 'project_id' => $p, 'tenant_id' => $this->tenant->id]);
        }

        return $id;
    }

    private function hydrate(string $id): Subscription
    {
        return app(SubscriptionScheduler::class)->hydrate(DB::table('notification_subscriptions')->where('id', $id)->first());
    }

    public function test_this_month_until_yesterday_on_the_15th_covers_1_to_14_and_on_the_16th_through_the_15th(): void
    {
        $this->assertSame(['key' => 'this_month_to_yesterday', 'from' => '2026-11-01', 'to' => '2026-11-14'], DigestPeriods::resolve('this_month_to_yesterday', '2026-11-15'));
        $this->assertSame('2026-11-15', DigestPeriods::resolve('this_month_to_yesterday', '2026-11-16')['to']);
        $this->assertNull(DigestPeriods::resolve('this_month_to_yesterday', '2026-11-01'));
    }

    public function test_every_period_ends_yesterday_at_the_latest(): void
    {
        // Sunday is the first day of the week: «this week until yesterday» is empty, so that run is skipped.
        $this->assertNull(DigestPeriods::resolve('this_week_to_yesterday', '2026-11-15'));
        $this->assertSame(['2026-11-15', '2026-11-17'], array_values(array_slice(DigestPeriods::resolve('this_week_to_yesterday', '2026-11-18'), 1)));
        foreach (array_diff(DigestPeriods::KEYS, ['active_budget_period', 'this_week_to_yesterday']) as $key) {
            $p = DigestPeriods::resolve($key, '2026-11-15');
            $this->assertNotNull($p, $key);
            $this->assertLessThanOrEqual('2026-11-14', $p['to'], $key);
        }
        $this->assertSame(['2026-11-08', '2026-11-14'], [DigestPeriods::resolve('last_7', '2026-11-15')['from'], DigestPeriods::resolve('last_7', '2026-11-15')['to']]);
        $this->assertNull(DigestPeriods::resolve('ytd', '2027-01-01'));
    }

    public function test_last_week_with_weekly_respects_the_sunday_week_start(): void
    {
        // Sunday 15 Nov: last week = Sunday 8 → Saturday 14.
        $this->assertSame(['key' => 'last_week', 'from' => '2026-11-08', 'to' => '2026-11-14'], DigestPeriods::resolve('last_week', '2026-11-15'));
        // Wednesday 18 Nov: still 8 → 14.
        $this->assertSame('2026-11-08', DigestPeriods::resolve('last_week', '2026-11-18')['from']);

        $p = $this->project($this->tenant);
        $id = $this->subscription([$p->id], ['period_key' => 'last_week', 'frequency' => 'weekly']);
        $next = app(Scheduler::class)->nextRun($this->hydrate($id), Carbon::parse('2026-11-15 06:00:00', 'UTC'));
        $this->assertSame('2026-11-22 08:00', $next?->setTimezone('Asia/Riyadh')->format('Y-m-d H:i')); // the next Sunday
    }

    public function test_a_daily_digest_does_not_run_on_the_weekend(): void
    {
        $p = $this->project($this->tenant);
        $id = $this->subscription([$p->id]);
        // Thursday 19 Nov 09:00 Riyadh → next is Sunday 22 Nov 08:00, skipping Friday and Saturday.
        $next = app(Scheduler::class)->nextRun($this->hydrate($id), Carbon::parse('2026-11-19 06:00:00', 'UTC'));
        $this->assertSame('2026-11-22 08:00', $next?->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'));
    }

    public function test_illogical_combinations_are_refused_with_an_alternative(): void
    {
        $this->assertSame(['ok' => false, 'alternative' => 'last_7'], DigestPeriods::check('yesterday', 'weekly'));
        $this->assertSame(['ok' => false, 'alternative' => 'last_month'], DigestPeriods::check('this_month_to_yesterday', 'monthly'));
        $this->assertSame(['ok' => true, 'alternative' => null], DigestPeriods::check('this_month_to_yesterday', 'daily'));
    }

    public function test_a_due_subscription_queues_one_digest_per_live_project_and_advances(): void
    {
        $live = $this->project($this->tenant, 'Live');
        $archived = $this->project($this->tenant, 'Old', 'archived');
        $id = $this->subscription([$live->id, $archived->id], ['next_run_at' => Carbon::parse('2026-11-15 05:00:00', 'UTC')]);
        $pending = $this->subscription([$live->id], ['next_run_at' => Carbon::parse('2026-11-15 05:00:00', 'UTC')], 'pending');

        $result = app(DigestRunner::class)->run();

        $this->assertSame(1, $result['subscriptions']);
        $rows = DB::table('notification_outbox')->where('event_key', 'digest.periodic')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($live->id, $rows[0]->project_id);
        $this->assertSame($id, $rows[0]->subscription_id);
        $this->assertEquals(['key' => 'this_month_to_yesterday', 'from' => '2026-11-01', 'to' => '2026-11-14'], json_decode($rows[0]->payload, true)['period']);
        $this->assertSame('2026-11-16 05:00:00', Carbon::parse(DB::table('notification_subscriptions')->where('id', $id)->value('next_run_at'))->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, DB::table('notification_outbox')->where('subscription_id', $pending)->count());

        // The same run again (a retry of the cron) queues nothing new.
        DB::table('notification_subscriptions')->where('id', $id)->update(['next_run_at' => Carbon::parse('2026-11-15 05:00:00', 'UTC')]);
        app()->forgetScopedInstances();
        app(DigestRunner::class)->run();
        $this->assertSame(1, DB::table('notification_outbox')->where('event_key', 'digest.periodic')->count());
    }
}
