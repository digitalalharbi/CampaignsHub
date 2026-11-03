<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * EMAIL-DELIVERY-LEDGER-001 (§17.4.4, §17.4.6) — every stage says only what is known at that stage.
 *
 * The state a reader sees comes from one translation. A transport's acceptance is `accepted` and never
 * `delivered`; nothing short of evidence writes `delivered`; a failure shows its operational reason
 * and never the credential or the code that was in the transport's message.
 */
final class MailDeliveryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function live(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
    }

    private function notice(): OperationalMail
    {
        return new OperationalMail(kind: 'member_welcome', severity: 'positive', title: 'Welcome', detail: 'Hello', context: 'A');
    }

    private function row(string $dedup): object
    {
        return DB::table('mail_deliveries')->where('dedup_key', $dedup)->first();
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function translations(): array
    {
        return [
            'queued' => ['queued', null, 'queued'],
            'in flight' => ['claimed', null, 'attempted'],
            'transport said yes' => ['sent', null, 'accepted'],
            'a person read it' => ['sent', '2026-11-03 10:00:00', 'delivered'],
            'failed' => ['failed', null, 'failed'],
            'no provider' => ['awaiting_credentials', null, 'suppressed'],
            'sandbox' => ['sandbox', null, 'suppressed'],
            'nothing to report' => ['skipped', null, 'suppressed'],
            'preference' => ['suppressed', null, 'suppressed'],
        ];
    }

    /** @dataProvider translations */
    public function test_each_stored_status_reads_as_one_stage(string $status, ?string $deliveredAt, string $state): void
    {
        $this->assertSame($state, MailDeliveryState::of($status, $deliveredAt));
    }

    public function test_a_failed_row_can_never_read_as_delivered_and_acceptance_never_does(): void
    {
        $this->assertSame('failed', MailDeliveryState::of('failed', '2026-11-03 10:00:00'));
        $this->assertSame('suppressed', MailDeliveryState::of('sandbox', '2026-11-03 10:00:00'));
        $this->assertNotSame('delivered', MailDeliveryState::of('sent', null));
    }

    public function test_a_real_acceptance_records_each_stage_and_stops_short_of_delivered(): void
    {
        $this->live();
        Mail::fake();

        $state = app(TransactionalMailer::class)->send('a@example.test', $this->notice(), 'test', 'mail.operational', dedupKey: 'accept-1');

        $this->assertSame('sent', $state);
        $row = $this->row('accept-1');
        $this->assertNotNull($row->attempted_at);
        $this->assertNotNull($row->accepted_at);
        $this->assertNull($row->delivered_at, 'an SMTP 250 is not a delivery');
        $this->assertSame('accepted', MailDeliveryState::of($row->status, $row->delivered_at));
    }

    /** @return array<string, array{string, string}> */
    public static function failures(): array
    {
        return [
            'refused login' => ['Failed to authenticate on SMTP server with username "operator-login" using the following authenticators: "LOGIN". Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".', 'auth_failed'],
            'host unreachable' => ['Connection could not be established with host "smtp.provider.test:587": stream_socket_client(): Connection refused', 'connection_failed'],
            'certificate' => ['Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed, certificate verify failed', 'tls_failed'],
            'recipient' => ['Expected response code "250" but got code "550", with message "550 5.1.1 user unknown"', 'recipient_rejected'],
            'throttled' => ['Expected response code "250" but got code "421", with message "421 4.7.0 Too many messages"', 'throttled'],
            'template' => ['Undefined variable $projects (View: /app/resources/views/mail/daily-digest.blade.php)', 'template_error'],
            'other' => ['Something nobody has seen', 'unknown'],
        ];
    }

    /** @dataProvider failures */
    public function test_a_transport_failure_is_named(string $message, string $code): void
    {
        $this->assertSame($code, MailFailure::from(new RuntimeException($message))['code']);
    }

    public function test_a_failure_keeps_the_reason_and_loses_the_credential_and_the_code(): void
    {
        $this->live();
        Mail::shouldReceive('to->send')->once()->andThrow(new RuntimeException(
            'Failed to authenticate on SMTP server with username "operator-login" using password=hunter2 for code 482913 at /reset?token=abcDEF123456 AUTH cGFzc3dvcmQtaW4tYmFzZTY0LWZvcm0tbG9uZw==',
        ));

        $state = app(TransactionalMailer::class)->send('a@example.test', new CredentialMail(purpose: CredentialMail::SIGN_IN_CODE, code: '482913'), 'sign_in_code', 'mail.credential', dedupKey: 'fail-1');

        $this->assertSame('failed', $state);
        $row = $this->row('fail-1');
        $this->assertSame('auth_failed', $row->failure_code);
        $this->assertStringContainsString('Failed to authenticate', (string) $row->error);
        foreach (['operator-login', 'hunter2', '482913', 'abcDEF123456', 'cGFzc3dvcmQ'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $row->error);
        }
    }

    public function test_a_message_with_a_secret_cannot_be_queued(): void
    {
        $this->live();
        $this->expectException(InvalidArgumentException::class);

        app(TransactionalMailer::class)->queue('a@example.test', new CredentialMail(purpose: CredentialMail::SIGN_IN_CODE, code: '482913'), 'sign_in_code', 'mail.credential');
    }

    public function test_a_queued_notice_retries_with_backoff_and_is_sent_once(): void
    {
        $this->live();
        Mail::shouldReceive('to->send')->once()->andThrow(new RuntimeException('Connection could not be established with host'));

        $mailer = app(TransactionalMailer::class);
        $id = $mailer->enqueue('a@example.test', $this->notice(), 'member_welcome', 'mail.operational', dedupKey: 'q-1');
        $this->assertSame('queued', $this->row('q-1')->status);

        $this->assertSame('retry', $mailer->deliverQueued($id, $this->notice()));
        $row = $this->row('q-1');
        $this->assertSame('queued', $row->status, 'a retry that is waiting is not a failure yet');
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertSame('connection_failed', $row->failure_code);

        Mail::swap(new MailManager(app())); // out of the mock, for the next step
        Mail::fake();
        $this->assertSame('sent', $mailer->deliverQueued($id, $this->notice()));
        // The queue redelivering a finished job changes nothing and sends nothing.
        $this->assertSame('already_sent', $mailer->deliverQueued($id, $this->notice()));
        Mail::assertSentCount(1);
        $row = $this->row('q-1');
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->next_attempt_at);
        $this->assertNull($row->failure_code);
    }

    public function test_a_queued_notice_stops_at_the_ceiling_and_says_failed(): void
    {
        $this->live();
        Mail::shouldReceive('to->send')->times(3)->andThrow(new RuntimeException('Connection could not be established with host'));

        $mailer = app(TransactionalMailer::class);
        $id = $mailer->enqueue('a@example.test', $this->notice(), 'member_welcome', 'mail.operational', dedupKey: 'q-2');

        $this->assertSame('retry', $mailer->deliverQueued($id, $this->notice()));
        $this->assertSame('retry', $mailer->deliverQueued($id, $this->notice()));
        $this->assertSame('failed', $mailer->deliverQueued($id, $this->notice()));
        $this->assertSame('already_sent', $mailer->deliverQueued($id, $this->notice()));
        $this->assertNull($this->row('q-2')->next_attempt_at);
    }

    public function test_a_broken_template_is_not_retried(): void
    {
        $this->live();
        Mail::shouldReceive('to->send')->once()->andThrow(new RuntimeException('Undefined variable $x (View: mail.operational)'));

        $mailer = app(TransactionalMailer::class);
        $id = $mailer->enqueue('a@example.test', $this->notice(), 'member_welcome', 'mail.operational', dedupKey: 'q-3');

        $this->assertSame('failed', $mailer->deliverQueued($id, $this->notice()));
    }

    public function test_queue_runs_through_a_real_serialised_job(): void
    {
        $this->live();
        Mail::fake();

        $state = app(TransactionalMailer::class)->queue('a@example.test', $this->notice(), 'member_welcome', 'mail.operational', dedupKey: 'q-4');

        $this->assertSame('sent', $state);
        Mail::assertSent(OperationalMail::class, 1);
    }

    /** §17.3.4 — an optional notice honours the switch; a security message does not ask. */
    public function test_an_optional_message_is_suppressed_by_preference_and_a_security_one_is_not(): void
    {
        $this->live();
        Mail::fake();
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-ledger', 'status' => 'active']);
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'secret123']);
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
            // Email is off altogether: the broadest «stop» a person can say.
            'channels' => json_encode(['in_app' => true, 'email' => false]),
            'categories' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mailer = app(TransactionalMailer::class);

        $this->assertSame('suppressed', $mailer->send('u@example.test', $this->notice(), 'delivery_review_due', 'mail.operational', tenantId: (string) $tenant->id, userId: (int) $user->id, dedupKey: 'opt-1'));
        Mail::assertNothingSent();
        $this->assertSame('suppressed', MailDeliveryState::of($this->row('opt-1')->status, null));

        foreach (['password_reset', 'sign_in_code', 'email_verification', 'security_alert', 'invitation', 'member_setup', 'role_changed'] as $i => $kind) {
            $this->assertSame('sent', $mailer->send('u@example.test', $this->notice(), $kind, 'mail.operational', tenantId: (string) $tenant->id, userId: (int) $user->id, dedupKey: "sec-{$i}"), $kind);
        }
        Mail::assertSentCount(7);
    }

    /**
     * The defect §17.4.4 names, found live in `NotificationDispatcher`: with a provider configured the
     * bell's email row was written `sent`, and no message was composed at all.
     */
    public function test_the_bell_never_records_an_email_it_did_not_send(): void
    {
        $this->live();
        Mail::fake();
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-bell', 'status' => 'active']);
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'secret123']);
        app(TenantContext::class)->setTenantId($tenant->id);

        // Mailed by its own sender (`AlertEventMailer`): this row must not claim it.
        $alert = app(NotificationDispatcher::class)->dispatch(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => 'budget_risk', 'title' => 'Budget']);
        $this->assertSame('handled_elsewhere', DB::table('notification_deliveries')->where('notification_id', $alert->id)->where('channel', 'email')->value('status'));

        // Nothing mails this kind at all, so it says so rather than «sent».
        $request = app(NotificationDispatcher::class)->dispatch(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => 'request.sla_warning', 'title' => 'SLA']);
        $this->assertSame('in_app_only', DB::table('notification_deliveries')->where('notification_id', $request->id)->where('channel', 'email')->value('status'));
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('notification_deliveries')->where('channel', 'email')->where('status', 'sent')->count());
    }

    /** A person who switched a conversation message on now really receives it. The switch used to control nothing. */
    public function test_a_bell_message_someone_opted_into_is_really_mailed_once(): void
    {
        $this->live();
        Mail::fake();
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-bell2', 'status' => 'active']);
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'secret123']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $payload = ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => 'message', 'title' => 'New message', 'message' => 'Hello', 'action_url' => '/app/messages/1'];

        // The catalogue's default for a conversation is off: nothing leaves.
        $off = app(NotificationDispatcher::class)->dispatch($payload + ['dedup_extra' => 'a']);
        Mail::assertNothingSent();
        $this->assertSame('suppressed_by_preference', DB::table('notification_deliveries')->where('notification_id', $off->id)->where('channel', 'email')->value('status'));

        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]), 'categories' => json_encode([]),
            'types' => json_encode(['message' => ['email' => true]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(NotificationChoices::class)->forget();

        $on = app(NotificationDispatcher::class)->dispatch($payload + ['dedup_extra' => 'b']);

        Mail::assertSent(OperationalMail::class, fn (OperationalMail $m) => $m->hasTo('u@example.test') && $m->path === '/app/messages/1');
        $this->assertSame('sent', DB::table('mail_deliveries')->where('dedup_key', 'bell:'.$on->id)->value('status'));
        $this->assertSame('sent', DB::table('notification_deliveries')->where('notification_id', $on->id)->where('channel', 'email')->value('status'));
    }

    public function test_only_a_person_with_evidence_can_mark_a_message_delivered(): void
    {
        $this->live();
        Mail::fake();
        app(TransactionalMailer::class)->send('a@example.test', $this->notice(), 'test', 'mail.operational', dedupKey: 'proof-1');
        $id = (string) $this->row('proof-1')->id;

        $this->artisan('mail:confirm-delivery', ['id' => $id])->assertFailed();
        $this->assertNull($this->row('proof-1')->delivered_at);

        $this->artisan('mail:confirm-delivery', ['id' => $id, '--evidence' => 'Owner read it in Gmail 2026-11-03'])->assertSuccessful();
        $row = $this->row('proof-1');
        $this->assertNotNull($row->delivered_at);
        $this->assertSame('Owner read it in Gmail 2026-11-03', $row->delivery_evidence);
        $this->assertSame('delivered', MailDeliveryState::of($row->status, $row->delivered_at));
    }

    public function test_a_message_that_never_left_cannot_be_confirmed(): void
    {
        DB::table('mail_deliveries')->insert([
            'id' => $id = (string) Str::uuid(), 'kind' => 'test', 'recipient' => 'a@example.test', 'locale' => 'ar',
            'template' => 'mail.operational', 'status' => 'sandbox', 'transport' => 'sandbox', 'attempts' => 1,
            'dedup_key' => 'proof-2', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('mail:confirm-delivery', ['id' => $id, '--evidence' => 'I saw it'])->assertFailed();
        $this->assertNull($this->row('proof-2')->delivered_at);
    }
}
