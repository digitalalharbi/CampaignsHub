<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Console\SendMailGallery;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * EMAIL-CLIENT-COMPAT-001 — `mail:send-gallery`: the Owner's way to put the gallery fixtures in his own mailbox.
 *
 * The `smtp` mailer points at Symfony's array transport (as in `MailTestSendTest`): the install reads as live, the
 * messages really pass through the mailer, and nothing can leave this machine.
 */
final class SendMailGalleryCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TO = 'owner.mailbox@gmail.test';

    protected function setUp(): void
    {
        parent::setUp();
        // Real product data in the database: none of it may reach a test message.
        $tenant = Tenant::create(['name' => 'RealCustomerCo', 'slug' => 'real-customer-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'SecretClientEta', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => 'SecretProjectZeta', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    private function live(string $transport = 'array'): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'info@campaignshub.test',
            'mail.mailers.smtp.transport' => $transport,
            'mail.from.address' => 'no-reply@campaignshub.test',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        app('mail.manager')->purge('smtp');
    }

    /** @return list<Email> */
    private function wire(): array
    {
        $out = [];
        foreach (app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages() as $sent) {
            $original = $sent->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $original);
            $out[] = $original;
        }

        return $out;
    }

    public function test_a_dry_run_is_the_default_and_sends_nothing(): void
    {
        $this->live();

        $code = Artisan::call('mail:send-gallery', ['to' => self::TO]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN: 26 message(s)', $out);
        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());

        // --dry-run wins over --send.
        Artisan::call('mail:send-gallery', ['to' => self::TO, '--send' => true, '--dry-run' => true]);
        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_the_sender_alignment_is_reported_without_printing_an_address(): void
    {
        $this->live('smtp');

        Artisan::call('mail:send-gallery', ['to' => self::TO, '--keys' => ['digest-project'], '--locale' => 'ar']);
        $out = Artisan::output();

        $this->assertStringContainsString('sender: envelope_aligned_by_code', $out);
        $this->assertStringContainsString('from: n***@campaignshub.test', $out);
        $this->assertStringNotContainsString('info@campaignshub.test', $out);
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_send_puts_the_marked_fixtures_through_the_mailer_and_the_ledger_as_tests(): void
    {
        $this->live();

        $code = Artisan::call('mail:send-gallery', ['to' => self::TO, '--send' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $wire = $this->wire();
        $this->assertCount(26, $wire);
        $this->assertSame(26, count(SendMailGallery::DEFAULT_KEYS) * 2);

        foreach ($wire as $email) {
            $this->assertSame(self::TO, $email->getTo()[0]->getAddress());
            // The configured From, never an override (the envelope alignment is AlignEnvelopeSender's, on a real SMTP transport).
            $this->assertSame('no-reply@campaignshub.test', $email->getFrom()[0]->getAddress());
            $subject = (string) $email->getSubject();
            $this->assertTrue(str_contains($subject, '[اختبار]') || str_contains($subject, '[TEST]'), $subject);
            $html = (string) $email->getHtmlBody();
            $this->assertTrue(str_contains($html, 'رسالة اختبار') || str_contains($html, 'Test message, not a real notice.'), $subject);
            $this->assertNotNull($email->getTextBody());
            foreach (['RealCustomerCo', 'real-customer-co', 'SecretProjectZeta', 'SecretClientEta'] as $real) {
                $this->assertStringNotContainsString($real, $html);
                $this->assertStringNotContainsString($real, (string) $email->getTextBody());
            }
        }

        $rows = DB::table('mail_deliveries')->get();
        $this->assertCount(26, $rows);
        foreach ($rows as $row) {
            $this->assertSame(TransactionalMailer::TEST_KIND, $row->kind);
            $this->assertNull($row->tenant_id);
            $this->assertStringStartsWith('gallery.', (string) $row->template);
            $this->assertSame('sent', $row->status); // the provider accepted it; delivery needs mail:confirm-delivery
        }

        // The address is masked in the output; only counts and keys are printed.
        $this->assertStringNotContainsString(self::TO, $out);
        $this->assertStringNotContainsString('owner.mailbox', $out);
        $this->assertStringContainsString('o***@gmail.test', $out);
        $this->assertStringContainsString('states: sent=26', $out);
        $this->assertStringContainsString('external-month-end', $out);
    }

    public function test_it_is_never_scheduled(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertStringNotContainsString('mail:send-gallery', (string) $event->command);
        }
    }

    public function test_more_than_forty_messages_are_refused_before_anything_is_sent(): void
    {
        $this->live();
        $keys = array_slice(array_keys(MailGallery::messages('ar')), 0, 21);

        $code = Artisan::call('mail:send-gallery', ['to' => self::TO, '--send' => true, '--keys' => $keys]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('42 messages requested; at most 40', Artisan::output());
        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_anything_but_one_valid_address_is_refused(): void
    {
        $this->live();
        foreach (['not-an-address', 'a@b.test,c@d.test', 'a@b.test c@d.test', 'Owner <a@b.test>', 'a@b.test;c@d.test', ''] as $to) {
            $code = Artisan::call('mail:send-gallery', ['to' => $to, '--send' => true]);
            $this->assertNotSame(0, $code, $to);
            $this->assertStringContainsString('exactly one valid email address', Artisan::output());
        }
        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_unknown_keys_and_locales_are_refused(): void
    {
        $this->live();
        $this->assertNotSame(0, Artisan::call('mail:send-gallery', ['to' => self::TO, '--send' => true, '--keys' => ['digest-project,nope']]));
        $this->assertStringContainsString('unknown gallery key(s): nope', Artisan::output());
        $this->assertNotSame(0, Artisan::call('mail:send-gallery', ['to' => self::TO, '--send' => true, '--locale' => 'fr']));
        $this->assertSame([], $this->wire());
    }

    public function test_a_provider_refusal_is_reported_by_its_class_never_its_text(): void
    {
        Mail::extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Expected response code "250" but got code "553", with message "553 5.7.1 <no-reply@campaignshub.test>: Sender address rejected: not owned by user info@campaignshub.test".');
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
        $this->live('refusing');

        $code = Artisan::call('mail:send-gallery', ['to' => self::TO, '--send' => true, '--keys' => ['digest-project'], '--locale' => 'en']);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('states: failed=1', $out);
        $this->assertStringContainsString('refusal classes: sender_rejected=1', $out);
        $this->assertStringNotContainsString('not owned by user', $out);
        $this->assertSame('test', DB::table('mail_deliveries')->value('kind'));
    }
}
