<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Services\EnrolMemberNotifications;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * NOTIFICATION-ENROLMENT-001 — what enrolment is FOR: the senders now reach a colleague who never opened a settings screen, and the
 * «send me this now» chips prove it to the person who presses them without touching anyone else's mail or the scheduler's ledger.
 *
 * Nothing here sends: `Mail::fake()` and the mail provider is configured the way production configures it.
 */
final class EnrolledMemberDigestsTest extends TestCase
{
    use RefreshDatabase;

    /** 05:00 UTC is 08:00 in Riyadh: the default digest hour, on a Friday (so no weekly, no monthly). */
    private const NOW = '2026-08-07 05:00:00';

    private Tenant $tenant;

    private Project $project;

    private Role $role;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        Mail::fake();
        config([
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.provider.test', 'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-enrol-'.Str::random(4), 'status' => 'active', 'account_type' => 'agency']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-en-'.Str::random(4), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $this->role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-en']);
        $this->role->givePermissionTo('clients.view_all', 'projects.view.all', 'campaigns.view');

        $this->ops = $this->colleague('ops@digest.test');

        app(UpsertDailyMetrics::class)->handle([new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id,
            externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'acc')->toString(), externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'camp')->toString(),
            provider: 'meta', metricKey: 'spend', metricDate: Carbon::today()->subDay(), value: 1200.0,
        )]);
        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A verified staff member with the digest's own reach rule. NOT enrolled: that is each test's decision. */
    private function colleague(string $email, ?Role $role = null): User
    {
        $user = User::create(['name' => 'Ops', 'email' => $email, 'password' => 'secret123', 'locale' => 'ar']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'role' => 'member', 'status' => 'active']);
        $user->assignRole($role ?? $this->role);

        return $user->fresh();
    }

    private function enrol(User $user): void
    {
        $this->assertSame('enrolled', app(EnrolMemberNotifications::class)->enrol($user, (string) $this->tenant->id, 'test'));
    }

    private function to(string $address): callable
    {
        return static fn ($mail): bool => $mail->hasTo($address);
    }

    // ── the sweeps reach an enrolled member, and still skip everyone else ───────────────────────

    public function test_the_digest_sweep_reaches_an_enrolled_member_and_skips_one_who_was_never_enrolled(): void
    {
        $stranger = $this->colleague('never-enrolled@digest.test');
        $this->enrol($this->ops);

        Artisan::call('notifications:send-digests');

        Mail::assertSent(DailyDigestMail::class, $this->to('ops@digest.test'));
        Mail::assertSent(DailyDigestMail::class, 1);
        $this->assertSame(1, DB::table('digest_sends')->where('user_id', $this->ops->id)->where('kind', 'daily')->where('status', 'sent')->count());
        $this->assertSame(0, DB::table('digest_sends')->where('user_id', $stranger->id)->count(), 'a person with no row is still never mailed by the sweep');
        $this->assertSame(0, DB::table('digest_sends')->where('kind', 'monthly')->count(), 'monthly is opt-in');
    }

    public function test_an_enrolled_member_with_no_project_access_is_enrolled_and_is_sent_nothing(): void
    {
        $bare = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Bare', 'slug' => 'bare-en']);
        $newcomer = $this->colleague('newcomer@digest.test', $bare);
        $this->enrol($newcomer);

        Artisan::call('notifications:send-digests', ['--user' => $newcomer->id]);

        Mail::assertNothingSent();
        $this->assertSame('skipped', DB::table('digest_sends')->where('user_id', $newcomer->id)->value('status'), 'DigestScope fails closed: nothing they may read, nothing sent');
    }

    public function test_an_enrolled_member_whose_membership_withholds_the_money_gets_the_digest_without_spend(): void
    {
        $this->assertTrue(ProjectCapability::exists('money.view'));
        $buyer = $this->colleague('buyer@digest.test');
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $buyer->id,
            'role' => ProjectRole::MEDIA_BUYER, 'status' => 'active', 'denied' => ['money.view'],
        ]);
        $this->enrol($buyer);

        Artisan::call('notifications:send-digests', ['--user' => $buyer->id]);

        Mail::assertSent(DailyDigestMail::class, static fn (DailyDigestMail $m): bool => $m->hasTo('buyer@digest.test') && $m->audience === 'restricted');
    }

    // ── «أرسل لي الآن» — the digest ─────────────────────────────────────────────────────────────

    private function now(string $kind, ?User $as = null, array $body = [])
    {
        return $this->actingAs($as ?? $this->ops, 'sanctum')->postJson('/api/v1/settings/notifications/digest-now/'.$kind, $body);
    }

    public function test_send_me_this_now_sends_once_to_the_signed_in_persons_own_address_and_to_nobody_else(): void
    {
        $other = $this->colleague('colleague@digest.test');
        $this->enrol($other);

        // Aimed at somebody else, in every way a body could: it is ignored, the address is the signed-in person's.
        $this->now('daily', body: ['email' => 'colleague@digest.test', 'to' => 'colleague@digest.test', 'user_id' => $other->id])
            ->assertOk()->assertJsonPath('data.state', 'sent');

        Mail::assertSent(DailyDigestMail::class, 1);
        Mail::assertSent(DailyDigestMail::class, $this->to('ops@digest.test'));
        Mail::assertNotSent(DailyDigestMail::class, $this->to('colleague@digest.test'));
        $this->assertSame(0, DB::table('digest_sends')->where('user_id', $other->id)->count());
    }

    public function test_an_on_demand_digest_is_its_own_ledger_row_and_never_takes_the_scheduled_slot(): void
    {
        $this->enrol($this->ops);

        $this->now('daily')->assertOk()->assertJsonPath('data.state', 'sent');
        $row = DB::table('digest_sends')->where('user_id', $this->ops->id)->sole();
        $this->assertSame('now_daily', $row->kind, 'marked on-demand in the ledger');
        $this->assertStringContainsString('#', (string) $row->period_key);
        $this->assertSame('sent', $row->status);

        // The real scheduled send for the same day is NOT blocked by it …
        Artisan::call('notifications:send-digests');
        $this->assertSame('sent', DB::table('digest_sends')->where('user_id', $this->ops->id)->where('kind', 'daily')->value('status'));
        Mail::assertSent(DailyDigestMail::class, 2);

        // … and it does not block the person asking again, nor re-sends the scheduled one.
        $this->now('daily')->assertOk()->assertJsonPath('data.state', 'sent');
        Artisan::call('notifications:send-digests');
        Mail::assertSent(DailyDigestMail::class, 3);
        $this->assertSame(1, DB::table('digest_sends')->where('kind', 'daily')->count());
        $this->assertSame(2, DB::table('digest_sends')->where('kind', 'now_daily')->count());
    }

    public function test_every_rhythm_can_be_sent_on_demand_and_an_on_demand_failure_is_never_retried_behind_the_person(): void
    {
        foreach (['daily', 'weekly', 'monthly'] as $kind) {
            $this->now($kind)->assertOk()->assertJsonPath('data.state', 'sent');
            $this->assertSame(1, DB::table('digest_sends')->where('kind', 'now_'.$kind)->count());
        }
        Mail::assertSent(DailyDigestMail::class, 3);
        $this->assertSame(0, DB::table('digest_sends')->whereIn('kind', ['daily', 'weekly', 'monthly'])->count(), 'a scheduled slot was consumed');

        $this->assertSame([], app(DigestDispatcher::class)->retryDue(Carbon::now()->addDay()));
    }

    public function test_the_on_demand_chips_are_throttled_and_refuse_an_unproved_address_and_an_unknown_rhythm(): void
    {
        $this->now('daily')->assertOk();
        $this->now('daily')->assertOk();
        $this->now('weekly')->assertOk();
        $this->now('daily')->assertStatus(429);

        $this->now('yearly', $this->colleague('x@digest.test'))->assertNotFound();

        $unproved = $this->colleague('unproved@digest.test');
        $unproved->forceFill(['email_verified_at' => null])->save();
        $this->now('daily', $unproved)->assertStatus(422)->assertJsonPath('errors.code', 'address_not_proven');
        Mail::assertSent(DailyDigestMail::class, 3);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'mail.test_send.refused')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'notifications.digest_now')->count(), 'every press is audited');
    }

    // ── «أرسل تنبيهًا تجريبيًا» ─────────────────────────────────────────────────────────────────

    private function sample(?User $as = null)
    {
        return $this->actingAs($as ?? $this->ops, 'sanctum')->postJson('/api/v1/settings/notifications/sample-alert');
    }

    public function test_a_sample_alert_goes_through_the_real_alert_path_to_the_person_alone_and_is_marked(): void
    {
        $other = $this->colleague('colleague@digest.test');
        $this->enrol($this->ops);
        $this->enrol($other);

        $this->sample()->assertOk()->assertJsonPath('data.state', 'sent');

        Mail::assertSent(AlertBundleMail::class, 1);
        Mail::assertSent(AlertBundleMail::class, $this->to('ops@digest.test'));
        Mail::assertNotSent(AlertBundleMail::class, $this->to('colleague@digest.test'));
        $ledger = DB::table('digest_sends')->where('kind', 'alert_event')->sole();
        $this->assertSame($this->ops->id, (int) $ledger->user_id);
        $this->assertSame('on_demand_sample', $ledger->reason);
        $this->assertSame('sent', $ledger->status);
    }

    public function test_a_sample_alert_says_when_the_person_is_not_enrolled_or_has_email_off(): void
    {
        $this->sample()->assertOk()->assertJsonPath('data.state', 'not_enrolled');
        Mail::assertNothingSent();

        $this->enrol($this->ops);
        DB::table('notification_preferences')->where('user_id', $this->ops->id)->update(['channels' => json_encode(['in_app' => true, 'email' => false])]);
        $this->sample()->assertOk()->assertJsonPath('data.state', 'switched_off');
        Mail::assertNothingSent();
    }

    public function test_the_sample_alert_chip_is_throttled_and_needs_a_proved_address(): void
    {
        $this->enrol($this->ops);
        $this->sample()->assertOk();
        $this->sample()->assertOk();
        $this->sample()->assertOk();
        $this->sample()->assertStatus(429);

        $unproved = $this->colleague('unproved@digest.test');
        $unproved->forceFill(['email_verified_at' => null])->save();
        $this->sample($unproved)->assertStatus(422)->assertJsonPath('errors.code', 'address_not_proven');
    }
}
