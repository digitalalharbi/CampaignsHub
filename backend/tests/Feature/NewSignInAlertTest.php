<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Services\NewSignInAlert;
use App\Domains\Notifications\Jobs\SendQueuedMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** EMAIL-GAPS — told about a new device; never on a refresh; off until approved; can never cost a sign-in. */
final class NewSignInAlertTest extends TestCase
{
    use RefreshDatabase;

    private const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const MAC_CHROME_NEWER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
            'security.new_sign_in_alert' => true,
        ]);
        $this->user = User::create(['name' => 'Sara', 'email' => 'sara@example.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
    }

    /** The `Login` event as the framework raises it, on the real listener chain, from this browser. */
    private function loginFrom(string $agent): void
    {
        $this->app['request']->headers->set('User-Agent', $agent);
        event(new Login('web', $this->user, false));
    }

    private function alerts(): int
    {
        return DB::table('mail_deliveries')->where('kind', 'security_alert')->count();
    }

    public function test_it_is_off_by_default_and_a_deploy_mails_nobody(): void
    {
        // Read from the file, so the value set in this test's setUp cannot answer for it.
        $this->assertFalse((require base_path('config/security.php'))['new_sign_in_alert']);

        config(['security.new_sign_in_alert' => false]);
        Mail::fake();
        $this->loginFrom(self::MAC_CHROME);
        $this->loginFrom(self::IPHONE);

        $this->assertSame(0, $this->alerts());
        Mail::assertNothingSent();
    }

    /** Recorded while off, so switching it on tells nobody that their everyday browser is new. */
    public function test_switching_it_on_later_does_not_report_devices_already_in_use(): void
    {
        config(['security.new_sign_in_alert' => false]);
        Queue::fake();
        $this->loginFrom(self::MAC_CHROME);

        config(['security.new_sign_in_alert' => true]);
        $this->loginFrom(self::MAC_CHROME);

        $this->assertSame(0, $this->alerts());
    }

    public function test_a_persons_first_device_is_not_news(): void
    {
        Queue::fake();
        $this->loginFrom(self::MAC_CHROME);

        $this->assertSame(0, $this->alerts());
        $this->assertSame(1, DB::table('known_sign_in_devices')->where('user_id', $this->user->id)->count());
    }

    public function test_a_new_device_queues_one_alert_and_nothing_talks_to_a_mail_server_on_the_request(): void
    {
        Queue::fake();
        Mail::fake();
        $this->loginFrom(self::MAC_CHROME);
        $this->loginFrom(self::IPHONE);

        $this->assertSame(1, $this->alerts());
        Queue::assertPushed(SendQueuedMail::class, fn (SendQueuedMail $job): bool => $job->mail instanceof SecurityAlertMail
            && $job->mail->event === SecurityAlertMail::NEW_SIGN_IN
            && $job->mail->device === 'Safari · iOS');
        Mail::assertNothingSent();
    }

    /** Laravel raises `Login` again when a remember-me cookie reopens a session. That is not a sign-in. */
    public function test_a_session_refresh_never_fires_it(): void
    {
        Queue::fake();
        $this->loginFrom(self::MAC_CHROME);
        $this->loginFrom(self::IPHONE);

        for ($i = 0; $i < 5; $i++) {
            $this->app['request']->headers->set('User-Agent', self::IPHONE);
            event(new Login('web', $this->user, true));
        }

        $this->assertSame(1, $this->alerts());
    }

    public function test_a_browser_update_is_not_a_new_device(): void
    {
        Queue::fake();
        $this->loginFrom(self::MAC_CHROME);
        $this->loginFrom(self::MAC_CHROME_NEWER);

        $this->assertSame(0, $this->alerts());
    }

    /** Through the real endpoint: the transport throws, and the person is signed in anyway. */
    public function test_a_failing_mail_transport_does_not_break_the_sign_in(): void
    {
        config(['queue.default' => 'sync']);
        DB::table('known_sign_in_devices')->insert(['user_id' => $this->user->id, 'fingerprint' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection could not be established'));

        $this->withHeaders(['Origin' => 'http://localhost:5173', 'User-Agent' => self::IPHONE])
            ->postJson('/api/v1/auth/login', ['email' => 'sara@example.test', 'password' => 'secret1234'])
            ->assertOk();

        $this->assertAuthenticatedAs($this->user, 'web');
        // A connection failure is worth a retry, so it waits with its reason written down rather than vanishing.
        $row = DB::table('mail_deliveries')->where('kind', 'security_alert')->first();
        $this->assertSame('queued', $row->status);
        $this->assertSame('connection_failed', $row->failure_code);
    }

    public function test_a_queue_that_cannot_be_reached_is_recorded_and_the_login_event_survives(): void
    {
        DB::table('known_sign_in_devices')->insert(['user_id' => $this->user->id, 'fingerprint' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Redis is down'));

        $this->loginFrom(self::IPHONE);

        $this->assertSame('failed', DB::table('mail_deliveries')->where('kind', 'security_alert')->value('status'));
    }

    public function test_anything_else_going_wrong_inside_it_is_reported_and_swallowed(): void
    {
        Exceptions::fake();
        $request = $this->app['request'];
        $this->app->bind('request', static fn () => throw new RuntimeException('anything at all'));

        try {
            (new NewSignInAlert(app(TransactionalMailer::class)))->handle(new Login('web', $this->user, false));
        } finally {
            $this->app->instance('request', $request);
        }

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_it_is_exempt_from_unsubscribe_and_carries_no_way_out(): void
    {
        $this->assertFalse(method_exists(SecurityAlertMail::class, 'offerUnsubscribe'));
    }
}
