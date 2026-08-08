<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Services\AlertDispatcher;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAIL-013 — quiet hours where mail actually leaves, and one bulletin instead of six emails.
 *
 * Two failures this is written against:
 *
 * 1. **Quiet hours were stored and honoured by nothing that sends mail.** The only reader was the
 *    bell's delivery ledger, which sends no email, and it compared the window against the SERVER's
 *    clock — so «22:00 to 08:00» meant whatever hour the container thought it was.
 * 2. **One email per finding.** A morning with a budget running ahead on two clients, a stopped sync
 *    and a climbing cost produced four emails in the same second. Four emails arriving together are
 *    not four times the attention; they are a filter rule, after which the one that mattered goes
 *    unread too.
 */
final class QuietHoursAndBundlingTest extends TestCase
{
    use RefreshDatabase;

    /** 09:00 UTC — midday in Riyadh, so a «night» window is unambiguous in both directions. */
    private const NOW = '2026-08-07 09:00:00';

    private Tenant $tenant;

    private Project $alpha;

    private Project $beta;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-quiet', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $one = ClientWorkspace::create(['name' => 'One', 'slug' => 'one-quiet', 'mode' => 'managed']);
        $two = ClientWorkspace::create(['name' => 'Two', 'slug' => 'two-quiet', 'mode' => 'managed']);
        $this->alpha = Project::create(['client_workspace_id' => $one->id, 'name' => 'مشروع', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $two->id, 'name' => 'الثاني', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-quiet']);
        $role->givePermissionTo('clients.view_all');

        $this->user = User::create(['name' => 'Ops', 'email' => 'ops@quiet.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();

        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aggregation ─────────────────────────────────────────────────────────────────────────────

    /**
     * Findings on two different clients arrive as one message, not two.
     *
     * The count is what makes this worth testing: `sent` still counts FINDINGS, because the ledger
     * and the cooldown are per finding. Only the email is one.
     */
    public function test_everything_one_sweep_found_arrives_as_a_single_message(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr($this->alpha);
        $this->seedFallingCtr($this->beta);

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(2, $counts['sent'], 'both findings should be recorded as sent');

        Mail::assertSentCount(1);
        Mail::assertSent(AlertBundleMail::class, function (AlertBundleMail $m): bool {
            $contexts = array_column($m->items, 'context');

            return count($m->items) === 2
                && in_array('مشروع', $contexts, true)
                && in_array('الثاني', $contexts, true);
        });
    }

    /**
     * The cooldown is still per finding, not per bulletin.
     *
     * If the claims were collapsed into one the whole message would share a cooldown, so a NEW
     * finding tomorrow would be silenced by an unrelated one sent today — the exact failure mode the
     * per-finding key was designed against.
     */
    public function test_a_finding_that_appears_later_is_not_silenced_by_an_earlier_bulletin(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr($this->alpha);

        $dispatcher = app(AlertDispatcher::class);
        $first = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        // A second client starts declining after the first bulletin has gone.
        $this->seedFallingCtr($this->beta);
        $second = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, $second['sent'], 'the new finding was swallowed by the earlier bulletin’s cooldown');
        $this->assertSame(1, $second['already_sent'], 'the repeated finding should still be held');
        Mail::assertSentCount(2);
    }

    /** With no provider, every finding in the bundle is recorded honestly and nothing is sent. */
    public function test_with_no_provider_every_finding_in_the_bundle_is_marked_awaiting_credentials(): void
    {
        Mail::fake();
        $this->seedFallingCtr($this->alpha);
        $this->seedFallingCtr($this->beta);

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(2, $counts['awaiting_credentials']);
        $this->assertSame(0, $counts['sent']);
        $this->assertSame(2, DB::table('digest_sends')->where('status', 'awaiting_credentials')->count());
        Mail::assertNothingSent();
    }

    // ── Quiet hours ─────────────────────────────────────────────────────────────────────────────

    /**
     * **The one that matters.** A held finding is not claimed, so it is not lost.
     *
     * There is no held-message table and no queue: the sweep simply does not send during the window,
     * and the next sweep after it closes finds the same observation and sends it. A design that
     * claimed the row and parked the message would need something to remember to deliver it later,
     * and the thing that forgets is what turns «quiet hours» into «never».
     */
    public function test_a_finding_during_quiet_hours_is_held_and_then_sent_by_the_next_sweep(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr($this->alpha);

        // 09:00 UTC is 12:00 in Riyadh — inside a window that runs from 11:00 to 14:00.
        $this->quietHours('11:00', '14:00', 'Asia/Riyadh');

        $dispatcher = app(AlertDispatcher::class);
        $during = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $during['held_by_quiet_hours']);
        $this->assertSame(0, $during['sent']);
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('digest_sends')->count(), 'a held finding must not be claimed');

        // The same day, after the window closes.
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'UTC')); // 15:00 in Riyadh
        $after = $dispatcher->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $after['sent'], 'the held finding never arrived');
        Mail::assertSentCount(1);
    }

    /**
     * The window is read in the recipient's timezone, not the server's.
     *
     * The same 11:00–14:00 window, stored against a timezone where the current moment is not inside
     * it. Under the old comparison this depended on where the container happened to be running.
     */
    public function test_the_window_belongs_to_the_hour_the_reader_is_living_in(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr($this->alpha);

        // 09:00 UTC is 05:00 in New York — outside 11:00–14:00, while Riyadh's 12:00 is inside it.
        $this->quietHours('11:00', '14:00', 'America/New_York');

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $counts['sent']);
        $this->assertSame(0, $counts['held_by_quiet_hours']);
    }

    /** A window that wraps past midnight is the usual case, and it still holds. */
    public function test_a_window_that_wraps_past_midnight_still_holds(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->seedFallingCtr($this->alpha);

        // 03:00 UTC is 06:00 in Riyadh, inside 22:00 → 08:00.
        Carbon::setTestNow(Carbon::parse('2026-08-07 03:00:00', 'UTC'));
        $this->quietHours('22:00', '08:00', 'Asia/Riyadh');

        $counts = app(AlertDispatcher::class)->sweep($this->user, (string) $this->tenant->id, Carbon::parse('2026-08-07'));

        $this->assertSame(1, $counts['held_by_quiet_hours']);
        Mail::assertNothingSent();
    }

    /**
     * A password reset is never held, whatever the hour.
     *
     * Quiet hours are a courtesy about interruptions. A reset link, a sign-in code and a security
     * alert are the answer to something the person just did, or the only warning they will get that
     * somebody else is in their account — delaying those to be polite builds the attacker a head
     * start. Enforced by `TransactionalMailer` never asking the question.
     */
    public function test_an_account_message_is_never_held_by_quiet_hours(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->quietHours('00:00', '23:59', 'Asia/Riyadh'); // the whole day is quiet

        app(PasswordResetService::class)->request('ops@quiet.test');

        $row = DB::table('mail_deliveries')->where('recipient', 'ops@quiet.test')->first();

        $this->assertNotNull($row, 'the reset was never even attempted');
        $this->assertSame('password_reset', $row->kind);
        // Whatever the transport says — what must never happen is the message being held.
        $this->assertContains($row->status, ['sent', 'sandbox'], "a reset link was held: {$row->status}");
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function quietHours(string $start, string $end, string $timezone): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $this->tenant->id,
            'user_id' => $this->user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode([]),
            'quiet_hours' => json_encode(['enabled' => true, 'start' => $start, 'end' => $end]),
            'digests' => json_encode(['daily' => false, 'weekly' => false, 'alerts' => true]),
            'timezone' => $timezone,
            'frequency' => 'realtime',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function withConfiguredEmail(): void
    {
        config()->set('providers.channels.email', ConfiguredQuietEmailProvider::class);
    }

    /**
     * A day whose click-through rate collapsed against a flat reach — a `falling_rate` warning.
     *
     * The external account and campaign ids are derived from the project, not shared. Two projects
     * pointing at one campaign id is not a fixture detail: the metrics table is keyed by it, so the
     * second write lands on the first project's row and only one finding is ever produced.
     */
    private function seedFallingCtr(Project $project): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $account = (string) Str::uuid();
        $campaign = (string) Str::uuid();

        $write = function (string $key, float $value, string $date) use ($project, $account, $campaign): void {
            app(UpsertDailyMetrics::class)->handle([
                new NormalizedMetric(
                    tenantId: (string) $this->tenant->id,
                    projectId: (string) $project->id,
                    externalAccountId: $account,
                    externalCampaignId: $campaign,
                    provider: 'meta',
                    metricKey: $key,
                    metricDate: Carbon::parse($date),
                    value: $value,
                ),
            ]);
        };

        $write('spend', 1000.0, '2026-08-07');
        $write('impressions', 300_000.0, '2026-08-07');
        $write('clicks', 1_000.0, '2026-08-07');

        $write('spend', 1000.0, '2026-08-06');
        $write('impressions', 300_000.0, '2026-08-06');
        $write('clicks', 3_000.0, '2026-08-06');

        app(TenantContext::class)->forget();
    }
}

/** A provider that reports itself configured, so the sweep gets past the honest-delivery gate. */
final class ConfiguredQuietEmailProvider implements MessageProvider
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
        return ['status' => 'sent', 'provider_message_id' => 'quiet-ack', 'error' => null];
    }
}
