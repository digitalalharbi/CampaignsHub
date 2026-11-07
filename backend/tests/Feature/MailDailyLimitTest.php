<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Providers\NullEmailProvider;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** EMAIL-GAPS — a refusal is a ledger row with a real reason, and one tenant cannot spend another's allowance. */
final class MailDailyLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
            'mail.daily_limit_per_tenant' => 2,
        ]);
        $this->a = Tenant::create(['name' => 'A', 'slug' => 'a-'.Str::random(5), 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'B', 'slug' => 'b-'.Str::random(5), 'status' => 'active']);
    }

    private function send(Tenant $tenant, string $key): string
    {
        return app(TransactionalMailer::class)->send(
            'x@example.test',
            new OperationalMail(kind: 'client_request', severity: 'info', title: 'T', detail: 'D', context: 'C'),
            'client_request', 'mail.operational', 'ar', $tenant->id, dedupKey: $key,
        );
    }

    public function test_past_the_limit_a_message_is_refused_with_a_reason_and_never_dropped(): void
    {
        Mail::fake();

        $this->assertSame('sent', $this->send($this->a, 'a1'));
        $this->assertSame('sent', $this->send($this->a, 'a2'));
        $this->assertSame('limit_reached', $this->send($this->a, 'a3'));

        Mail::assertSentCount(2);
        $row = DB::table('mail_deliveries')->where('dedup_key', 'a3')->first();
        $this->assertSame('suppressed', MailDeliveryState::of($row->status));
        $this->assertSame('daily_limit', MailDeliveryState::suppressionReason($row->status));
        $this->assertSame((string) $this->a->id, (string) $row->tenant_id);
    }

    public function test_one_tenant_cannot_use_anothers_allowance(): void
    {
        Mail::fake();

        $this->send($this->a, 'a1');
        $this->send($this->a, 'a2');
        $this->assertSame('limit_reached', $this->send($this->a, 'a3'));

        $this->assertSame('sent', $this->send($this->b, 'b1'));
        $this->assertSame(1, (int) DB::table('mail_daily_usage')->where('tenant_id', $this->b->id)->value('used'));
        $this->assertSame(2, (int) DB::table('mail_daily_usage')->where('tenant_id', $this->a->id)->value('used'));
    }

    public function test_the_allowance_comes_back_the_next_utc_day(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-04 23:30:00');

        $this->send($this->a, 'a1');
        $this->send($this->a, 'a2');
        $this->assertSame('limit_reached', $this->send($this->a, 'a3'));

        Carbon::setTestNow('2026-11-05 00:10:00');
        $this->assertSame('sent', $this->send($this->a, 'a4'));
        Carbon::setTestNow();
    }

    public function test_security_mail_is_neither_refused_nor_counted(): void
    {
        Mail::fake();
        $this->send($this->a, 'a1');
        $this->send($this->a, 'a2');

        $state = app(TransactionalMailer::class)->send('x@example.test', new SecurityAlertMail(event: SecurityAlertMail::PASSWORD_CHANGED), 'security_alert', 'mail.security', 'ar', $this->a->id);

        $this->assertSame('sent', $state);
        $this->assertSame(2, (int) DB::table('mail_daily_usage')->where('tenant_id', $this->a->id)->value('used'));
    }

    /** A retry is the same message, so it is not charged twice. */
    public function test_a_retry_is_not_charged_again(): void
    {
        $mailer = app(TransactionalMailer::class);
        $mail = new OperationalMail(kind: 'client_request', severity: 'info', title: 'T', detail: 'D', context: 'C');
        $id = $mailer->enqueue('x@example.test', $mail, 'client_request', 'mail.operational', 'ar', $this->a->id, dedupKey: 'r');

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Connection could not be established'));
        $this->assertSame('retry', $mailer->deliverQueued($id, $mail));

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnNull();
        $this->assertSame('sent', $mailer->deliverQueued($id, $mail));

        $this->assertSame(1, (int) DB::table('mail_daily_usage')->where('tenant_id', $this->a->id)->value('used'));
    }

    public function test_zero_switches_the_limit_off(): void
    {
        Mail::fake();
        config(['mail.daily_limit_per_tenant' => 0]);

        foreach (['a1', 'a2', 'a3'] as $key) {
            $this->assertSame('sent', $this->send($this->a, $key));
        }
    }

    public function test_nothing_is_counted_when_no_provider_would_send_it(): void
    {
        config(['providers.channels.email' => NullEmailProvider::class]);

        $this->assertSame('awaiting_credentials', $this->send($this->a, 'a1'));
        $this->assertSame(0, DB::table('mail_daily_usage')->count());
    }
}
