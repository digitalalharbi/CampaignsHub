<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Alerts\Services\AlertEventMailer;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
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
 * EMAIL-ALERT-EVENTS-001 / EMAIL-NOISE-SUPPRESSION-001 — the alert engine's own events reach an inbox:
 * one bundle per person per sweep, never twice for the same trigger, honouring the person's choices,
 * and honestly «awaiting credentials» when no provider is configured.
 */
final class AlertEventEmailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Ramadan Push', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@alerts.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]), 'categories' => json_encode([]), 'frequency' => 'realtime', 'locale' => 'ar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rule(string $type): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => $type, 'name' => ucfirst($type), 'cooldown_minutes' => 720,
            'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ]);
    }

    private function syncRun(string $connId, string $status, Carbon $at): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'connection_id' => $connId, 'provider' => 'meta', 'status' => $status,
            'window_start' => $at->copy()->subDay()->toDateString(), 'window_end' => $at->toDateString(),
            'error' => $status === 'failed' ? 'token expired' : null, 'started_at' => $at, 'finished_at' => $at->copy()->addMinute(),
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function withConfiguredEmail(): void
    {
        config()->set('providers.channels.email', ConfiguredAlertEventEmailProvider::class);
    }

    public function test_without_a_provider_the_bundle_is_recorded_awaiting_credentials_never_sent(): void
    {
        Mail::fake();
        $this->rule('sync_failure');
        $this->syncRun((string) Str::uuid(), 'failed', now()->subMinutes(10));

        app(AlertEvaluator::class)->evaluateAll();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('digest_sends', ['user_id' => $this->user->id, 'kind' => AlertEventMailer::KIND, 'status' => 'awaiting_credentials', 'reason' => 'no_email_provider']);
        $this->assertDatabaseMissing('digest_sends', ['kind' => AlertEventMailer::KIND, 'status' => 'sent']);
    }

    public function test_one_bundle_per_person_per_sweep_in_the_reader_language_and_never_twice_for_one_trigger(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->rule('sync_failure');
        $this->syncRun((string) Str::uuid(), 'failed', now()->subMinutes(10));
        $this->syncRun((string) Str::uuid(), 'failed', now()->subMinutes(8));

        app(AlertEvaluator::class)->evaluateAll();

        Mail::assertSent(AlertBundleMail::class, 1);
        Mail::assertSent(AlertBundleMail::class, function (AlertBundleMail $m): bool {
            return $m->hasTo($this->user->email)
                && count($m->items) === 2
                && $m->lang === 'ar'
                && $m->items[0]['severity'] === 'warning'
                // LINK-HEALTH-YESTERDAY-001 — the one link text: yesterday's data did not arrive.
                && str_contains((string) $m->items[0]['title'], 'بيانات أمس ما وصلت')
                && str_starts_with((string) $m->items[0]['context'], 'Ramadan Push');
        });
        $this->assertSame(2, DB::table('digest_sends')->where('kind', AlertEventMailer::KIND)->where('status', 'sent')->count());

        // The same sweep again, inside the cooldown: nothing is raised, nothing is mailed.
        app(AlertEvaluator::class)->evaluateAll();
        Mail::assertSent(AlertBundleMail::class, 1);
    }

    /** EMAIL-GAPS — this sender goes around `TransactionalMailer` too. Same gate, same recorded refusal. */
    public function test_an_unsubscribed_person_gets_no_event_mail_and_the_ledger_says_why(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        DB::table('mail_unsubscribes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => (string) $this->tenant->id, 'recipient_hash' => hash('sha256', (string) $this->user->email), 'scope' => 'alerts']);
        $this->rule('sync_failure');
        $this->syncRun((string) Str::uuid(), 'failed', now()->subMinutes(10));

        app(AlertEvaluator::class)->evaluateAll();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('digest_sends', ['user_id' => $this->user->id, 'kind' => AlertEventMailer::KIND, 'status' => 'unsubscribed', 'reason' => 'unsubscribed']);
    }

    /** LINK-HEALTH-YESTERDAY-001 (§7أ) — a link that delivered yesterday again closes WITHOUT a mail; the person's choices still hold. */
    public function test_a_link_recovery_is_silent_and_the_person_s_choices_are_honoured(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        $this->rule('sync_failure');
        $connId = (string) Str::uuid();
        $this->syncRun($connId, 'failed', now()->subHours(2));
        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateAll();
        Mail::assertSent(AlertBundleMail::class, 1);

        $this->syncRun($connId, 'success', now()->subMinutes(5));
        $evaluator->evaluateAll();

        Mail::assertSent(AlertBundleMail::class, 1);

        // Switched off for email: a new failure raises an event and an in-app notification, but no mail.
        DB::table('notification_preferences')->where('user_id', $this->user->id)
            ->update(['types' => json_encode(['sync_failed' => ['email' => false, 'in_app' => true, 'rhythm' => 'immediate']])]);
        $this->syncRun((string) Str::uuid(), 'failed', now()->subMinute());
        // A fresh evaluator, as a real sweep is: the choices are memoised per instance.
        $counts = app(AlertEvaluator::class)->evaluateAll() > 0 ? 'raised' : 'none';
        $this->assertSame('raised', $counts);
        Mail::assertSent(AlertBundleMail::class, 1);
    }

    /**
     * EMAIL-TEST-SEND-001 (§17.4.5) — a rule's cooldown can be five minutes, so one open problem could
     * otherwise mail one person all day. Past the ceiling it is held and recorded, its recovery still goes,
     * and a new day starts again.
     */
    public function test_one_alert_reaches_one_person_a_limited_number_of_times_a_day(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        config(['mail.alert_repeats_per_day' => 3]);
        $mailer = app(AlertEventMailer::class);
        $tenant = (string) $this->tenant->id;
        $item = fn (string $severity, Carbon $at, string $event = 'event-one'): array => [
            'event_id' => $event, 'notification_type' => 'sync_failed', 'severity' => $severity, 'title' => 'T', 'detail' => 'D',
            'project_id' => (string) $this->project->id, 'project_name' => 'P', 'triggered_at' => $at->toIso8601String(),
        ];
        $start = Carbon::parse('2026-11-04 06:00:00');

        for ($i = 0; $i < 5; $i++) {
            $at = $start->copy()->addMinutes($i * 10);
            $counts = $mailer->sweep($tenant, [$item('warning', $at)], $at);
        }

        Mail::assertSent(AlertBundleMail::class, 3);
        $this->assertSame(1, $counts['repeat_limit']);
        $this->assertSame(3, DB::table('digest_sends')->where('kind', AlertEventMailer::KIND)->where('status', 'sent')->count());
        $this->assertSame(2, DB::table('digest_sends')->where('kind', AlertEventMailer::KIND)->where('status', 'repeat_limit')->count());
        $this->assertSame('repeat_limit', MailDeliveryState::suppressionReason('repeat_limit'));

        // Another alert has its own count, and being told it is over is never held.
        $later = $start->copy()->addHours(2);
        $mailer->sweep($tenant, [$item('warning', $later, 'event-two')], $later);
        Mail::assertSent(AlertBundleMail::class, 4);
        $recovered = $start->copy()->addHours(3);
        $mailer->sweep($tenant, [$item('info', $recovered)], $recovered);
        Mail::assertSent(AlertBundleMail::class, 5);

        // A rolling 24 hours later the same alert may be said again.
        $tomorrow = $start->copy()->addHours(25);
        $mailer->sweep($tenant, [$item('warning', $tomorrow)], $tomorrow);
        Mail::assertSent(AlertBundleMail::class, 6);
    }

    /** `0` is the documented way off, and is also what the behaviour was before the ceiling. */
    public function test_a_zero_ceiling_holds_nothing(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        config(['mail.alert_repeats_per_day' => 0]);
        $mailer = app(AlertEventMailer::class);
        $start = Carbon::parse('2026-11-04 06:00:00');

        for ($i = 0; $i < 5; $i++) {
            $at = $start->copy()->addMinutes($i * 10);
            $mailer->sweep((string) $this->tenant->id, [[
                'event_id' => 'event-one', 'notification_type' => 'sync_failed', 'severity' => 'warning', 'title' => 'T', 'detail' => 'D',
                'project_id' => (string) $this->project->id, 'project_name' => 'P', 'triggered_at' => $at->toIso8601String(),
            ]], $at);
        }

        Mail::assertSent(AlertBundleMail::class, 5);
    }

    public function test_quiet_hours_hold_the_bundle(): void
    {
        Mail::fake();
        $this->withConfiguredEmail();
        DB::table('notification_preferences')->where('user_id', $this->user->id)
            ->update(['quiet_hours' => json_encode(['enabled' => true, 'start' => '00:00', 'end' => '23:59']), 'timezone' => 'Asia/Riyadh']);
        $this->rule('sync_failure');
        $this->syncRun((string) Str::uuid(), 'failed', now()->subMinutes(10));

        $counts = app(AlertEvaluator::class)->flushMail((string) $this->tenant->id); // nothing on the outbox yet
        $this->assertSame(0, $counts['held_by_quiet_hours']);

        app(AlertEvaluator::class)->evaluateAll();
        Mail::assertNothingSent();
    }
}

final class ConfiguredAlertEventEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'alert-event-ack', 'error' => null];
    }
}
