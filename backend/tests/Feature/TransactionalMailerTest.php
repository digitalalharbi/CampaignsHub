<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Services\TransactionalMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * MAIL-009 — a delivery state is something that HAPPENED, not something that was written down.
 *
 * The defect these tests exist against is subtle and was live: three flows wrote
 * `awaiting_provider_credentials` as a literal and composed no message. The value was true of an
 * install with no mailer, so nothing looked wrong — and it was unconditional, so configuring real
 * SMTP would have changed nothing except that people would start wondering why no invitation arrived.
 *
 * Every test below turns one of those states into an outcome that can be observed.
 */
final class TransactionalMailerTest extends TestCase
{
    use RefreshDatabase;

    private function mail(): CredentialMail
    {
        return new CredentialMail(purpose: CredentialMail::PASSWORD_RESET, url: 'https://campaignshub.io/x');
    }

    private function send(?string $dedupKey = null): string
    {
        return app(TransactionalMailer::class)->send(
            recipient: 'person@example.com',
            mail: $this->mail(),
            kind: 'password_reset',
            template: 'mail.credential',
            dedupKey: $dedupKey,
        );
    }

    /** Point the channel at a provider that says it is configured, without wiring a real transport. */
    private function withConfiguredChannel(): void
    {
        config(['providers.channels.email' => ConfiguredTestEmailProvider::class]);
    }

    /**
     * The default install: nothing is attempted, and the row says so.
     *
     * This is the state the whole product ships in today, and it must remain distinguishable from a
     * delivery. `sent_at` stays null because nothing was sent.
     */
    public function test_with_no_provider_nothing_is_attempted_and_the_row_says_so(): void
    {
        Mail::fake();

        $this->assertSame('awaiting_credentials', $this->send());

        Mail::assertNothingSent();

        $row = DB::table('mail_deliveries')->first();
        $this->assertSame('awaiting_credentials', $row->status);
        $this->assertNull($row->sent_at);
        $this->assertSame('password_reset', $row->kind);
        $this->assertSame('person@example.com', $row->recipient);
    }

    /**
     * A driver that works and reaches nobody is `sandbox`, never `sent`.
     *
     * The test environment's mailer is `array` — it accepts everything and delivers none of it. An
     * install that records that as a delivery is one where a developer's local run and a customer's
     * production run look identical in the ledger, which is the point at which the ledger stops being
     * evidence of anything.
     */
    public function test_a_driver_that_reaches_nobody_is_not_recorded_as_a_delivery(): void
    {
        $this->withConfiguredChannel();
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->assertSame('sandbox', $this->send());

        // It WAS handed to the transport — the distinction is about what the transport does with it.
        Mail::assertSent(CredentialMail::class);
        $this->assertSame('sandbox', DB::table('mail_deliveries')->value('status'));
    }

    /**
     * A transport that throws leaves its own message in the row, and nothing is retried here.
     *
     * Retrying a password reset in a loop mails somebody the same link four times; retrying a broken
     * template mails nobody anything, repeatedly. Re-sending is a decision a person makes.
     */
    public function test_a_failure_is_recorded_with_its_reason_and_not_retried(): void
    {
        $this->withConfiguredChannel();
        Mail::shouldReceive('to->send')->once()->andThrow(new RuntimeException('relay refused the recipient'));

        $this->assertSame('failed', $this->send());

        $row = DB::table('mail_deliveries')->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('relay refused', (string) $row->error);
        $this->assertNull($row->sent_at);
        $this->assertSame(1, (int) $row->attempts);
    }

    /**
     * Exactly once, enforced by the index rather than by a check.
     *
     * A check-then-send has a window between the two, and that window is where a retried queue job
     * sends somebody a second copy of their own invitation.
     */
    public function test_the_same_message_cannot_be_sent_twice(): void
    {
        $this->withConfiguredChannel();
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->assertSame('sandbox', $this->send('invitation:abc'));
        $this->assertSame('already_sent', $this->send('invitation:abc'));

        Mail::assertSentCount(1);
        $this->assertSame(1, DB::table('mail_deliveries')->count());
    }

    /**
     * A caller with nothing meaningful to name is not deduplicated into silence.
     *
     * NULLs do not collide in a unique index, and they must not: somebody whose first reset email
     * went to spam has to be able to ask for a second one.
     */
    public function test_messages_with_no_name_are_never_collapsed_into_one(): void
    {
        $this->withConfiguredChannel();
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->send();
        $this->send();

        $this->assertSame(2, DB::table('mail_deliveries')->count());
        Mail::assertSentCount(2);
    }
}

/** A stand-in for a channel that has credentials. The transport itself is faked separately. */
final class ConfiguredTestEmailProvider implements MessageProvider
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
        return ['status' => 'sent', 'provider_message_id' => 'test', 'error' => null];
    }
}
