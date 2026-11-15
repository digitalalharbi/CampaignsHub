<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Mail\DnsResolver;
use App\Domains\Notifications\Support\MailFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — the «stuck queued» mail defect and the read-only SPF / DKIM / DMARC report (spec §4). */
final class MailProviderFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_from_address_is_ours_and_permanent_not_a_recipient_problem_retried_as_queued(): void
    {
        $hostinger = 'Expected response code "250" but got code "553", with message "553 5.7.1 <no-reply@campaignshub.io>: Sender address rejected: not owned by user info@campaignshub.io"';
        $this->assertSame('sender_rejected', MailFailure::classify($hostinger));
        $this->assertTrue(MailFailure::isPermanent('sender_rejected'));
        $this->assertSame('recipient_rejected', MailFailure::classify('Expected response code "250" but got code "550", with message "550 5.1.1 user unknown"'));
    }

    public function test_every_smtp_message_is_enveloped_from_the_authenticated_mailbox(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.username' => 'info@campaignshub.io']);

        $email = (new Email)->from(new Address('no-reply@campaignshub.io', 'Alpha عبر CampaignsHub'))->to('a@b.test')->text('x');
        event(new MessageSending($email, []));
        $this->assertSame('info@campaignshub.io', $email->getSender()?->getAddress());
        $this->assertSame('no-reply@campaignshub.io', $email->getFrom()[0]->getAddress()); // what the reader sees is unchanged

        $aligned = (new Email)->from('info@campaignshub.io')->to('a@b.test')->text('x');
        event(new MessageSending($aligned, []));
        $this->assertNull($aligned->getSender());

        config(['mail.default' => 'log']);
        $logged = (new Email)->from('no-reply@campaignshub.io')->to('a@b.test')->text('x');
        event(new MessageSending($logged, []));
        $this->assertNull($logged->getSender());
    }

    public function test_the_dns_check_reports_what_is_missing_and_changes_nothing(): void
    {
        config(['mail.from.address' => 'info@campaignshub.io', 'mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'info@campaignshub.io']);
        $this->app->instance(DnsResolver::class, $this->dns([
            'campaignshub.io' => ['v=spf1 include:_spf.mail.hostinger.com ~all'],
        ], ['campaignshub.io' => ['mx1.hostinger.com']]));

        $this->artisan('mail:dns-check')
            ->expectsOutputToContain('SPF    ok')
            ->expectsOutputToContain('DKIM   missing')
            ->expectsOutputToContain('DMARC  missing')
            ->expectsOutputToContain('missing: DKIM, DMARC (report only')
            ->assertExitCode(1);

        $this->app->instance(DnsResolver::class, $this->dns([
            'campaignshub.io' => ['v=spf1 include:_spf.mail.hostinger.com ~all'],
            'hostingermail1._domainkey.campaignshub.io' => ['v=DKIM1; k=rsa; p=MIIB'],
            '_dmarc.campaignshub.io' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@campaignshub.io'],
        ], ['campaignshub.io' => ['mx1.hostinger.com']]));
        $this->artisan('mail:dns-check')->expectsOutputToContain('missing: none')->assertExitCode(0);
    }

    /**
     * @param  array<string, list<string>>  $txt
     * @param  array<string, list<string>>  $mx
     */
    private function dns(array $txt, array $mx): DnsResolver
    {
        return new class($txt, $mx) implements DnsResolver
        {
            /**
             * @param  array<string, list<string>>  $t
             * @param  array<string, list<string>>  $m
             */
            public function __construct(private readonly array $t, private readonly array $m) {}

            public function txt(string $host): array
            {
                return $this->t[$host] ?? [];
            }

            public function mx(string $host): array
            {
                return $this->m[$host] ?? [];
            }

            public function cname(string $host): array
            {
                return [];
            }
        };
    }
}
