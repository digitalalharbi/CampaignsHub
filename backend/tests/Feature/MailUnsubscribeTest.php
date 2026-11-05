<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\MailUnsubscribes;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailDeliveryState;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** EMAIL-GAPS (§17.4.5) — one signed click, no sign-in, honoured at send time; security mail cannot be left. */
final class MailUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.Str::random(5), 'status' => 'active']);
    }

    private function links(): MailUnsubscribes
    {
        return app(MailUnsubscribes::class);
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function notice(): OperationalMail
    {
        return new OperationalMail(kind: 'client_request', severity: 'info', title: 'T', detail: 'D', context: 'C');
    }

    public function test_the_link_needs_no_session_and_post_records_the_click(): void
    {
        $url = $this->links()->url($this->tenant->id, 'Sara@Example.test', 'client_request');

        $this->post($this->path($url))->assertOk()->assertSee('تم إلغاء اشتراكك');

        $this->assertTrue($this->links()->hasLeft($this->tenant->id, 'sara@example.test', 'client_request'));
        // Clicking twice is not an error, and the address itself is never stored.
        $this->post($this->path($url))->assertOk();
        $this->assertSame(1, DB::table('mail_unsubscribes')->count());
        $this->assertStringNotContainsString('example', (string) json_encode(DB::table('mail_unsubscribes')->first()));
    }

    /** A link scanner fetches every URL in a message. It must not unsubscribe anybody. */
    public function test_get_changes_nothing_and_the_page_submits_itself_with_a_button_as_fallback(): void
    {
        $url = $this->links()->url($this->tenant->id, 'sara@example.test', 'client_request');

        $this->get($this->path($url))->assertOk()
            ->assertSee('method="post"', false)
            ->assertSee('.submit()', false)
            ->assertSee('<button type="submit">', false);

        $this->assertSame(0, DB::table('mail_unsubscribes')->count());
    }

    public function test_a_mail_clients_one_click_post_needs_no_csrf_token(): void
    {
        $url = $this->links()->url($this->tenant->id, 'sara@example.test', 'client_request');

        // A real provider's POST: no session, no token, and the middleware not relaxed.
        $this->call('POST', $this->path($url), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertSame(1, DB::table('mail_unsubscribes')->count());
    }

    public function test_a_forged_or_altered_token_is_refused_and_writes_nothing(): void
    {
        $path = $this->path($this->links()->url($this->tenant->id, 'sara@example.test', 'client_request'));
        [$payload, $signature] = explode('.', basename($path));

        $this->post('/mail/unsubscribe/'.$payload.'.'.str_repeat('0', strlen($signature)))->assertNotFound();

        $other = rtrim(strtr(base64_encode((string) json_encode(['t' => $this->tenant->id, 'h' => 'x', 's' => 'client_request', 'u' => null])), '+/', '-_'), '=');
        $this->post('/mail/unsubscribe/'.$other.'.'.$signature)->assertNotFound();

        $this->assertSame(0, DB::table('mail_unsubscribes')->count());
    }

    /** Which is which, stated for every type the catalogue has. A new type has to land on one side here. */
    public function test_exactly_the_account_and_billing_types_cannot_be_left(): void
    {
        $exempt = array_values(array_filter(MessageCatalogue::keys(), static fn (string $t): bool => ! MessageCatalogue::isUnsubscribable($t)));
        sort($exempt);

        $this->assertSame([
            'email_verification', 'invitation', 'member_setup', 'member_welcome', 'password_reset',
            'role_changed', 'security_alert', 'sign_in_code', 'subscription',
        ], $exempt);

        foreach ($exempt as $type) {
            $this->assertNull(MailUnsubscribes::scopeOf($type), $type);
            $this->assertNull($this->links()->url($this->tenant->id, 'sara@example.test', $type), $type);
        }

        foreach (['client_request', 'scheduled_report', 'weekly_digest', 'report_ready', 'message'] as $type) {
            $this->assertSame($type, MailUnsubscribes::scopeOf($type));
        }

        $this->assertSame('delivery_reminder', MailUnsubscribes::scopeOf('delivery_review_due'));
        $this->assertNull(MailUnsubscribes::scopeOf('test'));
    }

    /** Even a correctly signed token cannot name a security message. */
    public function test_a_validly_signed_token_for_a_security_type_is_refused(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['t' => $this->tenant->id, 'h' => 'x', 's' => 'security_alert', 'u' => null])), '+/', '-_'), '=');
        $key = hash_hmac('sha256', 'mail-unsubscribe', (string) config('app.key'));
        $signature = substr(hash_hmac('sha256', $payload, $key), 0, 40);

        $this->post("/mail/unsubscribe/{$payload}.{$signature}")->assertNotFound();
        $this->assertSame(0, DB::table('mail_unsubscribes')->count());
    }

    public function test_a_security_message_still_goes_after_every_optional_type_was_left(): void
    {
        Mail::fake();
        foreach (MessageCatalogue::keys() as $type) {
            DB::table('mail_unsubscribes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'recipient_hash' => MailUnsubscribes::hash('sara@example.test'), 'scope' => $type]);
        }

        $state = app(TransactionalMailer::class)->send('sara@example.test', new SecurityAlertMail(event: SecurityAlertMail::PASSWORD_CHANGED), 'security_alert', 'mail.security', 'ar', $this->tenant->id);

        $this->assertSame('sent', $state);
        Mail::assertSent(SecurityAlertMail::class, fn (SecurityAlertMail $m): bool => ! method_exists($m, 'offerUnsubscribe'));
    }

    public function test_a_send_after_the_click_is_recorded_not_sent(): void
    {
        Mail::fake();
        $this->links()->leave($this->links()->read(basename($this->path($this->links()->url($this->tenant->id, 'sara@example.test', 'client_request')))));

        $state = app(TransactionalMailer::class)->send('SARA@example.test', $this->notice(), 'client_request', 'mail.operational', 'ar', $this->tenant->id, dedupKey: 'a');

        $this->assertSame('unsubscribed', $state);
        Mail::assertNothingSent();
        $row = DB::table('mail_deliveries')->where('dedup_key', 'a')->first();
        $this->assertSame('suppressed', MailDeliveryState::of($row->status));
        $this->assertSame('unsubscribed', MailDeliveryState::suppressionReason($row->status));
    }

    /** The click lands while the message waits for a worker. That is what «at send time» means. */
    public function test_a_click_while_the_message_is_queued_stops_it(): void
    {
        Mail::fake();
        $mailer = app(TransactionalMailer::class);
        $id = $mailer->enqueue('sara@example.test', $this->notice(), 'client_request', 'mail.operational', 'ar', $this->tenant->id, dedupKey: 'q');
        $this->assertSame('queued', DB::table('mail_deliveries')->where('id', $id)->value('status'));

        $this->links()->leave($this->links()->read(basename($this->path($this->links()->url($this->tenant->id, 'sara@example.test', 'client_request')))));

        $this->assertSame('unsubscribed', $mailer->deliverQueued($id, $this->notice()));
        Mail::assertNothingSent();
    }

    /** Leaving one workspace's mail says nothing about another's, and one type says nothing about another. */
    public function test_an_unsubscribe_is_scoped_to_its_tenant_and_type(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.Str::random(5), 'status' => 'active']);
        $this->links()->leave($this->links()->read(basename($this->path($this->links()->url($this->tenant->id, 'sara@example.test', 'client_request')))));

        $this->assertFalse($this->links()->hasLeft($other->id, 'sara@example.test', 'client_request'));
        $this->assertFalse($this->links()->hasLeft($this->tenant->id, 'sara@example.test', 'scheduled_report'));
    }

    public function test_the_message_carries_the_link_and_the_rfc_8058_headers(): void
    {
        config(['mail.default' => 'array']);
        $mail = $this->notice()->offerUnsubscribe($url = $this->links()->url($this->tenant->id, 'sara@example.test', 'client_request'));

        Mail::mailer('array')->to('sara@example.test')->send($mail);
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $message = $sent[count($sent) - 1]->getOriginalMessage();

        $this->assertSame('<'.$url.'>', $message->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString($url, (string) $message->getHtmlBody());
        $this->assertStringContainsString($url, (string) $message->getTextBody());
        // The address is hashed inside the token, so it is not readable in the link.
        $this->assertStringNotContainsString('sara', (string) base64_decode(strtr((string) strtok(basename($url), '.'), '-_', '+/')));
    }

    public function test_a_person_with_an_account_sees_it_off_on_the_settings_screen_and_can_turn_it_back_on(): void
    {
        $user = User::factory()->create(['email' => 'sara@example.test']);
        $this->grantMembership($user, $this->tenant);

        $this->links()->leave($this->links()->read(basename($this->path($this->links()->url($this->tenant->id, 'sara@example.test', 'client_request', (int) $user->id)))));
        app(NotificationChoices::class)->forget();

        $this->assertFalse(app(NotificationChoices::class)->wants((int) $user->id, $this->tenant->id, 'client_request', 'email'));

        $api = $this->actingAs($user, 'sanctum')->withHeader('X-Tenant-Id', $this->tenant->id);

        // Saving the screen unchanged is not a change of mind.
        $api->putJson('/api/v1/settings/notifications', ['types' => ['client_request' => ['email' => false]]])->assertOk();
        $this->assertTrue($this->links()->hasLeft($this->tenant->id, 'sara@example.test', 'client_request'));

        $api->putJson('/api/v1/settings/notifications', ['types' => ['client_request' => ['email' => true]]])->assertOk();
        $this->assertFalse($this->links()->hasLeft($this->tenant->id, 'sara@example.test', 'client_request'));
    }
}
