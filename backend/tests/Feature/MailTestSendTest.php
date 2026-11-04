<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Providers\NullEmailProvider;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\MailSubject;
use App\Domains\Platform\Services\OperationalReadiness;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * EMAIL-TEST-SEND-001 (Owner §17.4.5) — «ولا تعتبر الرسائل مرسلة دون دليل».
 *
 * Almost every test here reads the message the transport really received. A Mail::fake() would keep
 * the mailable and never run the callback that marks the subject, so a test against it would pass
 * while customers' template subjects went out unmarked. The `smtp` mailer is pointed at Symfony's
 * array transport: the install reads as `live` and nothing can leave this machine.
 */
final class MailTestSendTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/v1/admin/email/test';

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'RealCustomerCo', 'slug' => 'real-customer-co', 'status' => 'active']);
        $this->admin = User::create(['name' => 'Platform', 'email' => 'owner@campaignshub.test', 'password' => 'secret123']);
        // Neither is mass-assignable, on purpose.
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    private function live(string $transport = 'array'): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'mail.mailers.smtp.transport' => $transport,
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        app('mail.manager')->purge('smtp');
    }

    /** @return list<Email> what the transport was really handed */
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

    private function member(string $email, bool $manager = false): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'status' => 'active']);
        if ($manager) {
            $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
            $role->givePermissionTo('settings.manage', 'clients.view_all');
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    public function test_anybody_but_a_super_admin_is_refused_and_nothing_leaves(): void
    {
        $this->live();

        $this->postJson(self::ROUTE, ['lang' => 'en'])->assertStatus(401);
        $this->actingAs($this->member('member@acme.test'), 'sanctum')->postJson(self::ROUTE)->assertStatus(403);
        // A workspace owner with every workspace permission is still not the platform's owner.
        $this->actingAs($this->member('manager@acme.test', true), 'sanctum')
            ->withHeader('X-Tenant-Id', (string) $this->tenant->id)
            ->postJson(self::ROUTE, ['key' => 'alerts-one'])->assertStatus(403);

        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    /** It lived on feat/email-gaps with a member's and a manager's permission: the one route is the admin's. */
    public function test_the_workspace_test_route_is_gone_for_members_and_managers(): void
    {
        $this->live();

        foreach ([$this->member('member@acme.test'), $this->member('manager@acme.test', true)] as $user) {
            $this->actingAs($user, 'sanctum')->withHeader('X-Tenant-Id', (string) $this->tenant->id)
                ->postJson('/api/v1/settings/notifications/test', ['lang' => 'en'])->assertStatus(404);
        }

        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    /** No field of the request can name a recipient, and no copy goes anywhere else. */
    public function test_a_typed_address_is_never_used_and_no_copy_goes_elsewhere(): void
    {
        $this->live();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, [
            'to' => 'customer@victim.test', 'recipient' => 'customer@victim.test', 'email' => 'customer@victim.test',
            'cc' => 'customer@victim.test', 'bcc' => 'customer@victim.test', 'lang' => 'en',
        ])->assertOk();

        $this->assertSame('owner@campaignshub.test', $res->json('data.recipient'));
        [$message] = $this->wire();
        $this->assertSame(['owner@campaignshub.test'], array_map(fn ($a) => $a->getAddress(), $message->getTo()));
        $this->assertSame([], $message->getCc());
        $this->assertSame([], $message->getBcc());
        $this->assertSame(0, DB::table('mail_deliveries')->where('recipient', 'customer@victim.test')->count());
    }

    public function test_an_address_nobody_proved_is_refused_and_the_refusal_is_audited(): void
    {
        $this->live();
        $this->admin->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->admin->fresh(), 'sanctum')->postJson(self::ROUTE, ['lang' => 'en'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'address_not_proven');

        $this->assertSame([], $this->wire());
        $this->assertSame(0, DB::table('mail_deliveries')->count());
        $audit = DB::table('audit_logs')->where('action', 'mail.test_send.refused')->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $this->admin->id, (int) $audit->user_id);
        $this->assertStringNotContainsString('owner@campaignshub.test', (string) json_encode($audit));
    }

    public function test_the_subject_and_the_body_say_it_is_a_test_in_both_languages(): void
    {
        $this->live();

        $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'ar', 'key' => 'report-ready'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en', 'key' => 'report-ready'])->assertOk();

        [$ar, $en] = $this->wire();
        $this->assertStringStartsWith(MailSubject::PREFIX.'[اختبار] ', (string) $ar->getSubject());
        $this->assertStringStartsWith(MailSubject::PREFIX.'[TEST] ', (string) $en->getSubject());
        $this->assertStringContainsString('رسالة اختبار', (string) $ar->getHtmlBody());
        $this->assertStringContainsString('dir="rtl"', (string) $ar->getHtmlBody());
        $this->assertStringContainsString('Test message', (string) $en->getHtmlBody());
        $this->assertStringContainsString('dir="ltr"', (string) $en->getHtmlBody());
        // The text part is built from the body, so the mark survives a client that shows only that.
        $this->assertStringContainsString('رسالة اختبار', (string) $ar->getTextBody());
        $this->assertStringContainsString('Test message', (string) $en->getTextBody());
    }

    /** The same minute, one template, two languages: reviewing both must not read as a duplicate. */
    public function test_one_template_can_be_tested_in_both_languages_inside_a_minute(): void
    {
        $this->live();

        $first = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'ar', 'key' => 'alerts-one'])->assertOk();
        $second = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en', 'key' => 'alerts-one'])->assertOk();

        $this->assertSame('sent', $first->json('data.state'));
        $this->assertSame('sent', $second->json('data.state'));
        $this->assertCount(2, $this->wire());
    }

    /** Every template in the gallery, marked, on the wire. A new template joins by being in the gallery. */
    public function test_every_template_is_marked_when_it_leaves_as_a_test(): void
    {
        $this->live();

        foreach (['ar', 'en'] as $lang) {
            foreach (MailGallery::keys() as $key) {
                Mail::to('owner@campaignshub.test')->send(MailGallery::testMessage($key, $lang));
                $all = $this->wire();
                $message = end($all);
                $mark = $lang === 'ar' ? '[اختبار] ' : '[TEST] ';

                $this->assertStringStartsWith(MailSubject::PREFIX.$mark, (string) $message->getSubject(), "{$key}.{$lang}");
                $this->assertSame(1, substr_count((string) $message->getHtmlBody(), 'data-test-send-notice'), "{$key}.{$lang}");
            }
        }
    }

    /** What a customer would receive carries no mark: the marking belongs to the test, not to the template. */
    public function test_the_real_templates_carry_no_test_mark(): void
    {
        foreach (MailGallery::messages('en') as $key => $mail) {
            $this->assertStringNotContainsString('data-test-send-notice', $mail->render(), $key);
        }
    }

    /**
     * Fixture only, proved by what it reads rather than by the absence of one word: no template in the
     * gallery asks the database for anything, so there is nowhere a tenant's figures could come from.
     */
    public function test_a_test_message_reads_nothing_from_the_database_and_names_no_tenant(): void
    {
        foreach (['ar', 'en'] as $lang) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $html = (string) MailGallery::testMessage(null, $lang)->render();
            foreach (MailGallery::keys() as $key) {
                $html .= (string) MailGallery::testMessage($key, $lang)->render();
            }
            $this->assertSame([], DB::getQueryLog(), $lang);
            DB::disableQueryLog();

            $this->assertStringNotContainsString('RealCustomerCo', $html);
            $this->assertStringNotContainsString('real-customer-co', $html);
            $this->assertStringNotContainsString((string) $this->tenant->id, $html);
        }
    }

    /** A live unsubscribe token in a test would remove the admin from real mail. It has neither link nor header. */
    public function test_a_test_message_offers_no_working_way_out(): void
    {
        $this->live();

        $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en', 'key' => 'report-scheduled'])->assertOk();

        [$message] = $this->wire();
        $this->assertStringNotContainsString('/mail/unsubscribe/', (string) $message->getHtmlBody());
        $this->assertFalse($message->getHeaders()->has('List-Unsubscribe'));
    }

    /** `accepted` is what SMTP said. It is recorded with the transport's Message-ID and it is not delivery. */
    public function test_an_accepted_message_records_the_transports_answer_and_is_not_delivered(): void
    {
        $this->live();

        $data = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en', 'key' => 'alerts-one'])->assertOk()->json('data');

        $this->assertSame('sent', $data['state']);
        $this->assertSame('accepted', $data['delivery_state']);
        $this->assertNotEmpty($data['provider_message_id']);
        $row = DB::table('mail_deliveries')->where('id', $data['delivery_id'])->first();
        $this->assertSame($data['provider_message_id'], $row->provider_message_id);
        $this->assertNotNull($row->accepted_at);
        $this->assertNull($row->delivered_at);
        $this->assertNull($row->delivery_evidence);
    }

    public function test_a_failure_records_the_named_reason_and_never_the_login(): void
    {
        Mail::extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Failed to authenticate on SMTP server with username "operator-login" using 2 possible authenticators.');
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
        $this->live('refusing');

        $res = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en'])->assertOk();

        $this->assertSame('failed', $res->json('data.state'));
        $this->assertSame('failed', $res->json('data.delivery_state'));
        $this->assertSame('auth_failed', $res->json('data.failure_code'));
        $this->assertStringNotContainsString('operator-login', $res->getContent());
    }

    public function test_a_sandbox_or_unwired_transport_says_nothing_reached_anybody(): void
    {
        Mail::fake();
        config(['mail.default' => 'log', 'providers.channels.email' => NullEmailProvider::class]);

        $unwired = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en'])->assertOk()->json('data');
        $this->assertSame('suppressed', $unwired['delivery_state']);
        $this->assertSame('no_provider', $unwired['suppression_reason']);
        Mail::assertNothingSent();

        config(['providers.channels.email' => AlwaysConfiguredMailProvider::class]);
        $sandbox = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'ar'])->assertOk()->json('data');
        $this->assertSame('suppressed', $sandbox['delivery_state']);
        $this->assertSame('sandbox_transport', $sandbox['suppression_reason']);
    }

    public function test_every_send_is_audited_with_who_when_template_and_a_masked_recipient(): void
    {
        $this->live();

        $data = $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'ar', 'key' => 'alerts-one'])->assertOk()->json('data');

        $audit = DB::table('audit_logs')->where('action', 'mail.test_send')->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $this->admin->id, (int) $audit->user_id);
        $this->assertNotNull($audit->created_at);
        $this->assertNull($audit->tenant_id);
        $this->assertSame($data['delivery_id'], (string) $audit->entity_id);
        $after = json_decode((string) $audit->after, true);
        $this->assertSame('gallery.alerts-one', $after['template']);
        $this->assertSame('ar', $after['locale']);
        $this->assertSame('o***@campaignshub.test', $after['recipient']);
        $this->assertSame('accepted', $after['delivery_state']);
        $this->assertStringNotContainsString('owner@campaignshub.test', (string) json_encode($audit));
    }

    public function test_the_rate_limit_stops_the_fourth_in_a_minute_and_the_day_has_its_own_ceiling(): void
    {
        $this->live();
        $post = fn (string $lang, string $key) => $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => $lang, 'key' => $key]);

        $post('ar', 'alerts-one')->assertOk();
        $post('en', 'alerts-one')->assertOk();
        $post('ar', 'billing')->assertOk();
        $post('en', 'billing')->assertStatus(429);
        $this->assertCount(3, $this->wire());

        // Twenty a day in all, however they are spread over the minutes.
        for ($i = 0; $i < 17; $i++) {
            $this->travel(2)->minutes();
            $post('ar', 'approval')->assertOk();
        }
        $this->assertCount(20, $this->wire());
        $this->travel(2)->minutes();
        $post('ar', 'approval')->assertStatus(429);
        $this->assertCount(20, $this->wire());
    }

    /** An unnamed `throttle:3,1` shares one counter across routes: another route must not spend this allowance. */
    public function test_the_allowance_is_its_own_and_not_shared_with_other_routes(): void
    {
        $this->live();
        $limiter = app(RateLimiter::class);
        $this->assertNotNull($limiter->limiter('mail-test-send'));
    }

    /**
     * How it is separated: `kind = test`, `tenant_id` NULL, and every reader that a customer sees or
     * that decides something for them leaves such a row out.
     */
    public function test_it_counts_in_no_customer_ledger_no_allowance_and_no_verdict(): void
    {
        $this->live();
        config(['mail.daily_limit_per_tenant' => 1]);
        // The admin is ALSO a member of a workspace, which is the case a user-keyed reader gets wrong.
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id, 'portal' => 'agency', 'status' => 'active']);
        $manager = $this->member('manager@acme.test', true);

        $this->actingAs($this->admin, 'sanctum')->postJson(self::ROUTE, ['lang' => 'en', 'key' => 'report-scheduled'])->assertOk();

        $row = DB::table('mail_deliveries')->where('kind', TransactionalMailer::TEST_KIND)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->tenant_id);
        $this->assertSame(0, DB::table('mail_daily_usage')->count(), 'a test spent a tenant\'s allowance');

        // The workspace's own log and its team view.
        $log = $this->actingAs($manager, 'sanctum')->withHeader('X-Tenant-Id', (string) $this->tenant->id)
            ->getJson('/api/v1/settings/notifications/deliveries')->assertOk()->json('data');
        $this->assertSame([], $log);
        $team = $this->actingAs($manager, 'sanctum')->withHeader('X-Tenant-Id', (string) $this->tenant->id)
            ->getJson('/api/v1/settings/notifications/team')->assertOk()->getContent();
        $this->assertStringNotContainsString('"kind":"test"', (string) $team);

        // The verdict a public sign-in page reads: an accepted test does not prove customers' mail works...
        $this->assertSame('unknown', app(OperationalReadiness::class)->mailState());

        // ...and the operator's own console says what it was, counts it apart and masks the address.
        $console = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data');
        $this->assertSame([], $console['by_state']);
        $this->assertSame(1, $console['test_sends']);
        $this->assertNull($console['transport']['last_success_at']);
        $this->assertTrue($console['deliveries'][0]['is_test']);
        $this->assertSame('o***@campaignshub.test', $console['deliveries'][0]['recipient']);

        $real = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/deliveries?source=transactional')->json('data');
        $this->assertSame(0, $real['total']);
        $only = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/deliveries?source=test')->json('data');
        $this->assertSame(1, $only['total']);
    }

    public function test_a_failed_test_does_not_read_as_customers_mail_failing(): void
    {
        DB::table('mail_deliveries')->insert([
            'id' => (string) Uuid::uuid4(), 'kind' => TransactionalMailer::TEST_KIND, 'recipient' => 'owner@campaignshub.test', 'locale' => 'ar',
            'template' => 'gallery.alerts-one', 'status' => 'failed', 'transport' => 'live', 'attempts' => 1, 'failure_code' => 'auth_failed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('unknown', app(OperationalReadiness::class)->mailState());
        $console = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/deliveries')->assertOk()->json('data');
        $this->assertNull($console['transport']['last_failure']);
    }

    /** The mailer itself refuses to attribute a test to a tenant, so no later caller can reopen this. */
    public function test_the_mailer_refuses_a_test_attributed_to_a_tenant_or_queued(): void
    {
        $mailer = app(TransactionalMailer::class);
        $mail = MailGallery::testMessage(null, 'en');

        try {
            $mailer->send('x@example.test', $mail, TransactionalMailer::TEST_KIND, 'gallery.x', 'en', (string) $this->tenant->id);
            $this->fail('a test was attributed to a tenant');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('mail_deliveries')->count());
        }

        $this->expectException(InvalidArgumentException::class);
        $mailer->queue('x@example.test', $mail, TransactionalMailer::TEST_KIND, 'gallery.x');
    }

    /**
     * «Nothing runs by itself»: the only code that can compose a test message is the controller a person
     * posts to. Not a command that sends, not the scheduler, not a migration, not a deploy script.
     */
    public function test_nothing_but_the_authorised_route_can_send_one_and_reviewing_sends_nothing(): void
    {
        $senders = [];
        foreach ((new Finder)->files()->in([app_path(), base_path('routes'), base_path('database')])->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'TEST_KIND')) {
                $senders[] = $file->getRelativePathname();
            }
        }
        sort($senders);
        $this->assertSame([
            'Domains/Notifications/Http/Controllers/TeamNotificationsController.php',
            'Domains/Notifications/Services/TransactionalMailer.php',
            'Domains/Platform/Http/Controllers/PlatformEmailController.php',
            'Domains/Platform/Services/OperationalReadiness.php',
        ], $senders);

        Mail::fake();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/previews')->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/previews/alerts-one?locale=en')->assertOk();
        $this->artisan('notifications:preview', ['--out' => storage_path('framework/testing/mail-test-send'), '--as-test' => true, '--locale' => 'en'])->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('mail_deliveries')->count());

        $marked = (string) file_get_contents(storage_path('framework/testing/mail-test-send/alerts-one.en.html'));
        $this->assertStringContainsString('data-test-send-notice', $marked);
    }

    /** Review: what the inbox will show, the text part, and whether the reader could have switched it off. */
    public function test_the_review_shows_the_subject_the_text_part_and_who_may_send(): void
    {
        $preview = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/previews/account-password-reset?locale=ar')->assertOk()->json('data');
        $this->assertStringStartsWith(MailSubject::PREFIX, $preview['subject']);
        $this->assertStringStartsWith(MailSubject::PREFIX.'[اختبار] ', $preview['test_subject']);
        $this->assertStringContainsString('CampaignsHub', $preview['text']);
        $this->assertTrue($preview['mandatory']);

        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/previews')->assertOk()->json('data');
        $this->assertTrue($list['test_send']['address_proven']);
        $this->assertSame('owner@campaignshub.test', $list['test_send']['recipient']);

        $this->assertFalse($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/email/previews/report-ready?locale=en')->json('data.mandatory'));
    }
}

/** A channel that says yes, so the `sandbox` branch of the mailer can be reached with a `log` driver. */
final class AlwaysConfiguredMailProvider implements MessageProvider
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
        return ['status' => 'sent', 'provider_message_id' => null, 'error' => null];
    }
}
