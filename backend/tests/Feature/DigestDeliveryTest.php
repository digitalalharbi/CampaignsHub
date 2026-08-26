<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * MAIL-003 — sent once, at the right hour, and never claimed to have been sent when it was not.
 *
 * The three failures these guard are the ones a recipient actually notices: the same digest twice,
 * a digest at three in the morning, and a delivery ledger that says «sent» about an email nobody
 * received.
 */
final class DigestDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    /**
     * The instant this whole fixture lives at.
     *
     * Frozen BEFORE anything is seeded, because the metrics below are written relative to «today»
     * and two of the tests then pin the clock to a literal date. While the freeze happened inside
     * those tests, the seeded day and the pinned day agreed on exactly ONE calendar day — the day
     * they were written — and the suite went red the following morning for no change to the product.
     *
     * 05:00 UTC is 08:00 in Riyadh, which is the default digest hour: the sweep has something to do
     * at the frozen instant without any test having to move the clock forward to make it work.
     */
    private const NOW = '2026-08-07 05:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-digest-delivery', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-dd', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-dd']);
        $role->givePermissionTo('clients.view_all');

        $this->user = User::create(['name' => 'Ops', 'email' => 'ops@digest.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();

        // A day with real spend, so the digest is sendable.
        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->id,
                projectId: (string) $this->project->id,
                externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'acc')->toString(),
                externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'camp')->toString(),
                provider: 'meta',
                metricKey: 'spend',
                metricDate: Carbon::today()->subDay(),
                value: 1200.0,
            ),
        ]);

        app(TenantContext::class)->forget();
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
            'digests' => json_encode(['daily' => true]),
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
            'digest_hour' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ], $over));
    }

    /**
     * Wire a credentialed provider, the way production would.
     *
     * Through CONFIG rather than by swapping the registry: `ProviderRegistry` is final on purpose,
     * and the honest-status mapping this suite depends on is the registry's own. A test that
     * replaced it would be asserting against a different object than the one that ships.
     */
    private function withConfiguredEmail(): void
    {
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
    }

    /**
     * The same digest is never sent twice — and it is a constraint, not a check.
     *
     * A check-then-send has a window between the two, and that window is where a retried queue job
     * or an overlapping scheduler run sends yesterday's numbers a second time. Somebody who receives
     * them twice stops trusting the ones they receive once.
     */
    public function test_a_digest_is_sent_once_per_period_however_many_times_it_is_attempted(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        $day = Carbon::today()->subDay();
        $dispatcher = app(DigestDispatcher::class);

        $first = $dispatcher->sendDaily($this->user, (string) $this->tenant->id, $day);
        $second = $dispatcher->sendDaily($this->user, (string) $this->tenant->id, $day);
        $third = $dispatcher->sendDaily($this->user, (string) $this->tenant->id, $day);

        $this->assertSame('sent', $first);
        $this->assertSame('already_sent', $second);
        $this->assertSame('already_sent', $third);

        Mail::assertSentCount(1);
        $this->assertSame(1, DB::table('digest_sends')->where('user_id', $this->user->id)->count());
    }

    /**
     * With no mail provider wired, nothing is sent and nothing claims to have been.
     *
     * `awaiting_credentials` is the product's standing vocabulary for this, and it is a state rather
     * than a failure — retrying it every hour would be pretending a missing provider is a transient
     * fault.
     */
    public function test_with_no_provider_the_state_is_awaiting_credentials_and_no_mail_is_sent(): void
    {
        Mail::fake();

        $state = app(DigestDispatcher::class)->sendDaily($this->user, (string) $this->tenant->id, Carbon::today()->subDay());

        $this->assertSame('awaiting_credentials', $state);
        Mail::assertNothingSent();

        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->first();
        $this->assertSame('awaiting_credentials', $row->status);
        $this->assertSame('no_email_provider', $row->reason);
        $this->assertNull($row->sent_at, 'nothing was sent, so nothing may carry a sent timestamp');
    }

    /**
     * A failure is recorded WITH its message, and retried a bounded number of times.
     *
     * The standing rule in this repo is that an intermittent failure is never hidden by a retry. So
     * the error is kept, the attempt is counted, and after the ceiling the row stops being
     * re-claimed rather than looping forever on a broken template.
     */
    public function test_a_failed_send_keeps_its_error_and_stops_retrying_after_the_ceiling(): void
    {
        $this->withConfiguredEmail();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp refused the connection'));

        $day = Carbon::today()->subDay();
        $dispatcher = app(DigestDispatcher::class);

        for ($i = 0; $i < 5; $i++) {
            $dispatcher->sendDaily($this->user, (string) $this->tenant->id, $day);
        }

        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->first();

        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('smtp refused', (string) $row->last_error);
        $this->assertSame(3, (int) $row->attempts, 'retries are bounded — a broken template must not loop');
    }

    /** Nothing in scope is a recorded reason, not a silent absence. */
    public function test_no_activity_is_recorded_as_a_reason_rather_than_a_missing_row(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        // A day with no metrics at all.
        $state = app(DigestDispatcher::class)->sendDaily($this->user, (string) $this->tenant->id, Carbon::today()->subDays(30));

        $this->assertSame('skipped', $state);
        Mail::assertNothingSent();

        $row = DB::table('digest_sends')->where('user_id', $this->user->id)->first();
        $this->assertSame('no_activity', $row->reason);
    }

    /**
     * The weekly is the same engine over seven days, keyed on the ISO week.
     *
     * A run on any day of that week converges on one row, so a `--force` and the Monday schedule
     * cannot both land. And it inherits every rule the daily follows, because it IS the daily
     * builder over a different window rather than a second implementation to keep in step.
     */
    public function test_the_weekly_digest_is_sent_once_per_iso_week(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        $dispatcher = app(DigestDispatcher::class);
        $end = Carbon::today();

        $first = $dispatcher->sendWeekly($this->user, (string) $this->tenant->id, $end);
        // A different day of the SAME week must not produce a second email.
        $second = $dispatcher->sendWeekly($this->user, (string) $this->tenant->id, $end->copy()->subDays(2));

        $this->assertSame('sent', $first);
        $this->assertSame('already_sent', $second);

        Mail::assertSent(DailyDigestMail::class, 1);
        $this->assertSame(1, DB::table('digest_sends')->where('kind', 'weekly')->count());
        $this->assertSame($end->format('o-\WW'), DB::table('digest_sends')->where('kind', 'weekly')->value('period_key'));
    }

    /**
     * EMAIL-INTELLIGENCE-001 — the monthly covers a whole calendar month, once.
     *
     * The window is the month, not «the last 30 days»: a monthly report that slides is not
     * comparable with the one before it, and comparability is the only reason to send a monthly
     * report rather than another weekly.
     */
    public function test_the_monthly_covers_the_calendar_month_and_sends_once(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        $dispatcher = app(DigestDispatcher::class);
        $mid = Carbon::parse('2026-08-14');

        $first = $dispatcher->sendMonthly($this->user, (string) $this->tenant->id, $mid);
        // Any other day of the SAME month converges on the same row.
        $second = $dispatcher->sendMonthly($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-29'));

        $this->assertSame('sent', $first);
        $this->assertSame('already_sent', $second);

        Mail::assertSent(DailyDigestMail::class, 1);
        $this->assertSame(1, DB::table('digest_sends')->where('kind', 'monthly')->count());
        $this->assertSame('2026-08', DB::table('digest_sends')->where('kind', 'monthly')->value('period_key'));
    }

    /**
     * A different month is a different report — the guard must not swallow the next one.
     *
     * Asserted on the dedup BOUNDARY rather than on delivery. A month with no rows is skipped as an
     * empty digest, which is correct and unrelated to what this test is for; asserting «sent» would
     * make it a test about which months the seed happens to cover.
     */
    public function test_a_new_month_is_a_new_send(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        $dispatcher = app(DigestDispatcher::class);
        $tenantId = (string) $this->tenant->id;

        $july = $dispatcher->sendMonthly($this->user, $tenantId, Carbon::parse('2026-07-10'));
        $august = $dispatcher->sendMonthly($this->user, $tenantId, Carbon::parse('2026-08-10'));

        // Neither was refused as a repeat of the other.
        $this->assertNotSame('already_sent', $july);
        $this->assertNotSame('already_sent', $august);

        // And the same month IS refused, so the guard is on and keyed by the month.
        $this->assertSame('already_sent', $dispatcher->sendMonthly($this->user, $tenantId, Carbon::parse('2026-08-27')));

        $keys = DB::table('digest_sends')->where('kind', 'monthly')->pluck('period_key')->sort()->values()->all();
        $this->assertSame(['2026-07', '2026-08'], $keys);
    }

    /** All three rhythms are separate rows: choosing all three means three emails. */
    public function test_the_monthly_does_not_deduplicate_against_the_daily_or_weekly(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        $dispatcher = app(DigestDispatcher::class);
        // The day the sibling daily/weekly test uses — a fixed past date has no rows, and an empty
        // digest is skipped rather than sent, which would make this assert the wrong thing.
        $day = Carbon::today()->subDay();

        $this->assertSame('sent', $dispatcher->sendDaily($this->user, (string) $this->tenant->id, $day));
        $this->assertSame('sent', $dispatcher->sendWeekly($this->user, (string) $this->tenant->id, $day));
        $this->assertSame('sent', $dispatcher->sendMonthly($this->user, (string) $this->tenant->id, $day));

        Mail::assertSent(DailyDigestMail::class, 3);
    }

    /** Daily and weekly are separate rows: asking for both means two emails, not one deduped away. */
    public function test_the_daily_and_weekly_do_not_deduplicate_against_each_other(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        $dispatcher = app(DigestDispatcher::class);
        $day = Carbon::today()->subDay();

        $this->assertSame('sent', $dispatcher->sendDaily($this->user, (string) $this->tenant->id, $day));
        $this->assertSame('sent', $dispatcher->sendWeekly($this->user, (string) $this->tenant->id, $day));

        Mail::assertSent(DailyDigestMail::class, 2);
    }

    // ---- the sweep -------------------------------------------------------------------------------

    /**
     * Each recipient is sent at THEIR hour, not the server's.
     *
     * The sweep runs hourly and asks each row whether it is currently their chosen local hour. Two
     * people, two timezones, one run: only the one whose clock says 08:00 is mailed.
     */
    public function test_the_sweep_sends_only_to_recipients_whose_local_hour_has_come(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        // The frozen 05:00 UTC is 08:00 in Riyadh (+03) and 06:00 in London (+01 in August), so one
        // recipient's hour has come and the other's has not.
        $this->preference(['timezone' => 'Asia/Riyadh', 'digest_hour' => 8]);

        $other = User::create(['name' => 'Late', 'email' => 'late@digest.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'portal' => 'agency', 'status' => 'active']);
        DB::table('notification_preferences')->insert([
            'id' => (string) Uuid::uuid4(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $other->id,
            'channels' => json_encode(['email' => true]),
            'categories' => json_encode([]),
            'digests' => json_encode(['daily' => true]),
            'timezone' => 'Europe/London',
            'locale' => 'en',
            'digest_hour' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('notifications:send-digests')->assertSuccessful();

        Mail::assertSent(DailyDigestMail::class, 1);
        Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $m) => $m->hasTo('ops@digest.test'));
    }

    /**
     * The digest is opt-IN. A preference row that never asked for it is never mailed.
     *
     * An opt-in that defaults to on is not an opt-in, and the first scheduled run after a deploy is
     * the worst possible moment to discover that.
     */
    public function test_a_recipient_who_never_asked_for_the_digest_is_not_mailed(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();

        // No `digests` key at all — the shape every existing row already has.
        $this->preference(['digests' => null]);

        $this->artisan('notifications:send-digests')->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * One malformed timezone must not silence everybody else's digest.
     *
     * An unrecognised identifier would throw out of `setTimezone` and abort the sweep — a single bad
     * preference row taking the feature down for the whole installation.
     */
    public function test_an_unrecognised_timezone_does_not_abort_the_sweep(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        // Same DAY as the fixture — only the hour moves, so «yesterday» still holds the seeded spend.
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->setTime(8, 0));

        $this->preference(['timezone' => 'Mars/Olympus', 'digest_hour' => 8]);

        $this->artisan('notifications:send-digests')->assertSuccessful();

        // It falls back to UTC, where the hour DOES match — so the sweep completes and delivers.
        Mail::assertSent(DailyDigestMail::class, 1);
    }
}

/** A stand-in for a real, credentialed provider — the registry's own honest mapping is what is tested. */
final class ConfiguredDigestEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'digest-ack', 'error' => null];
    }
}
