<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Providers\NullWhatsAppProvider;
use App\Domains\Notifications\Providers\TwilioWhatsAppProvider;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Services\WhatsAppChannel;
use App\Domains\Notifications\Services\WhatsAppOptOut;
use App\Domains\Notifications\Support\WhatsAppTemplates;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NOTIFICATION-CHANNELS-001 — WhatsApp is prepared and refuses everything it should. Nothing here can
 * reach a vendor: `Http::fake()` stands in for Twilio, and every refusal asserts that nothing was sent.
 */
final class NotificationWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const SID_AR = 'HX00000000000000000000000000000001';

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM0123456789abcdef0123456789abcdef', 'status' => 'queued'], 201)]);
        $this->seed(PermissionSeeder::class);
        $this->configureProvider();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.Str::random(5), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.Str::random(5), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->user = $this->member();
        RateLimiter::clear('whatsapp:'.$this->user->id);
    }

    private function configureProvider(): void
    {
        config([
            'providers.channels.whatsapp' => TwilioWhatsAppProvider::class,
            'providers.channels.email' => LaravelMailProvider::class,
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.provider.test', 'mail.mailers.smtp.username' => 'operator-login',
            'services.twilio.account_sid' => 'ACtest00000000000000000000000000',
            'services.twilio.auth_token' => 'test-token',
            'services.twilio.whatsapp.from' => '+14155550100',
            'services.twilio.whatsapp.messaging_service_sid' => null,
            'services.twilio.whatsapp.content_sids' => ['ch_automation_rule.ar' => self::SID_AR],
        ]);
    }

    /** @param list<string> $denied */
    private function member(array $denied = [], bool $optedIn = true): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.Str::random(5)]);
        $role->givePermissionTo('projects.view', 'campaigns.view', 'analytics.view');
        $user = User::create(['name' => 'M', 'email' => 'm-'.Str::random(6).'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now(), 'locale' => 'ar']);
        $user->forceFill($optedIn ? ['whatsapp_number' => '+966501234567', 'whatsapp_opt_in_at' => now()->subDay(), 'whatsapp_opt_in_source' => 'settings_checkbox'] : [])->save();
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => ProjectRole::MEDIA_BUYER, 'status' => 'active', 'denied' => $denied,
        ]);

        return $user;
    }

    private function chooseWhatsApp(User $user, string $type = 'automation_rule', array $extra = []): void
    {
        DB::table('notification_preferences')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'client_workspace_id' => null],
            $extra + [
                'id' => (string) Str::uuid(), 'channels' => json_encode(['in_app' => true, 'email' => true]), 'categories' => json_encode([]),
                'types' => json_encode([$type => ['whatsapp' => true]]), 'frequency' => 'realtime', 'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    /** @param array<string,mixed> $over */
    private function raise(User $user, array $over = []): ?string
    {
        $n = app(NotificationDispatcher::class)->dispatch($over + [
            'tenant_id' => (string) $this->tenant->id, 'user_id' => $user->id, 'project_id' => (string) $this->project->id,
            'type' => 'automation_rule', 'severity' => 'warning', 'title' => "Rule «A» matched:\nEid sale", 'message' => 'cost_per_result = 41.2',
            'source' => 'automation', 'entity_type' => 'automation_rule', 'entity_id' => 'r1', 'action_url' => '/automation', 'dedup_extra' => Str::random(6),
        ]);

        return $n === null ? null : (string) DB::table('notification_deliveries')->where('notification_id', $n->id)->where('channel', 'whatsapp')->value('status');
    }

    private function nothingReachedTheVendor(): void
    {
        Http::assertNothingSent();
    }

    public function test_the_request_to_twilio_has_exactly_the_documented_shape(): void
    {
        $result = (new TwilioWhatsAppProvider)->send('+966501234567', ['content_sid' => self::SID_AR, 'variables' => ['1' => 'عنوان', '2' => 'تفاصيل']]);

        $this->assertSame('sent', $result['status']);
        $this->assertSame('SM0123456789abcdef0123456789abcdef', $result['provider_message_id']);
        Http::assertSent(function (HttpRequest $r): bool {
            $form = $r->data();

            return $r->method() === 'POST'
                && $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACtest00000000000000000000000000/Messages.json'
                && $r->hasHeader('Authorization', 'Basic '.base64_encode('ACtest00000000000000000000000000:test-token'))
                && $form['To'] === 'whatsapp:+966501234567'
                && $form['From'] === 'whatsapp:+14155550100'
                && $form['ContentSid'] === self::SID_AR
                && json_decode((string) $form['ContentVariables'], true) === ['1' => 'عنوان', '2' => 'تفاصيل']
                && ! array_key_exists('Body', $form) && ! array_key_exists('MessagingServiceSid', $form);
        });
    }

    public function test_a_messaging_service_replaces_the_sender_and_free_text_cannot_be_sent_at_all(): void
    {
        config(['services.twilio.whatsapp.from' => null, 'services.twilio.whatsapp.messaging_service_sid' => 'MG00000000000000000000000000000001']);

        (new TwilioWhatsAppProvider)->send('+966501234567', ['content_sid' => self::SID_AR, 'variables' => [], 'body' => 'free text']);

        Http::assertSent(fn (HttpRequest $r): bool => $r->data()['MessagingServiceSid'] === 'MG00000000000000000000000000000001' && ! array_key_exists('From', $r->data()) && ! array_key_exists('Body', $r->data()));
    }

    public function test_without_credentials_the_provider_reports_not_configured_and_opens_no_connection(): void
    {
        foreach ([['services.twilio.account_sid', ''], ['services.twilio.auth_token', ''], ['services.twilio.whatsapp.from', '']] as [$key, $empty]) {
            $this->configureProvider();
            config([$key => $empty]);
            $provider = new TwilioWhatsAppProvider;

            $this->assertFalse($provider->isConfigured(), "{$key} missing must mean not configured");
            $this->assertSame('awaiting_credentials', $provider->send('+966501234567', ['content_sid' => self::SID_AR])['status']);
        }
        $this->nothingReachedTheVendor();
    }

    public function test_a_vendor_refusal_is_failed_and_a_vendor_outage_is_retried_without_leaking_a_body(): void
    {
        // A fresh factory: stubs merge and the FIRST match wins, so the success stub from setUp would answer instead.
        Http::swap(new Factory);
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['code' => 63016, 'message' => 'Failed to send freeform message', 'more_info' => 'x'], 400)
            ->push('boom', 503)
            ->push(['code' => 20429], 429)]);
        $provider = new TwilioWhatsAppProvider;
        $payload = ['content_sid' => self::SID_AR, 'variables' => ['1' => 'a']];

        $this->assertSame(['status' => 'failed', 'provider_message_id' => null, 'error' => '63016'], $provider->send('+966501234567', $payload));
        $this->assertSame('retrying', $provider->send('+966501234567', $payload)['status']);
        $this->assertSame('retrying', $provider->send('+966501234567', $payload)['status']);
    }

    public function test_a_type_nobody_switched_on_for_whatsapp_leaves_no_whatsapp_row(): void
    {
        $this->assertSame('', (string) $this->raise($this->user));
        $this->assertSame(0, DB::table('notification_deliveries')->where('channel', 'whatsapp')->count());
        $this->nothingReachedTheVendor();
    }

    public function test_a_chosen_type_with_an_opted_in_number_goes_out_through_its_template(): void
    {
        $this->chooseWhatsApp($this->user);

        $this->assertSame('sent', $this->raise($this->user));
        Http::assertSent(function (HttpRequest $r): bool {
            $vars = json_decode((string) $r->data()['ContentVariables'], true);

            return $r->data()['ContentSid'] === self::SID_AR
                && $vars['1'] === 'Rule «A» matched: Eid sale'   // the newline was collapsed: the vendor rejects one inside a variable
                && $vars['2'] === 'cost_per_result = 41.2';
        });
    }

    public function test_no_number(): void
    {
        $user = $this->member(optedIn: false);
        $this->chooseWhatsApp($user);

        $this->assertSame('no_number', $this->raise($user));
        $this->nothingReachedTheVendor();
    }

    public function test_a_number_without_a_proved_opt_in(): void
    {
        $this->chooseWhatsApp($this->user);
        $this->user->forceFill(['whatsapp_opt_in_at' => null])->save();

        $this->assertSame('no_opt_in', $this->raise($this->user));
        $this->nothingReachedTheVendor();
    }

    public function test_an_opt_out_after_the_opt_in_wins_and_a_fresh_opt_in_wins_back(): void
    {
        $this->chooseWhatsApp($this->user);
        $this->user->forceFill(['whatsapp_opted_out_at' => now()])->save();
        $this->assertSame('opted_out', $this->raise($this->user));
        $this->nothingReachedTheVendor();

        $this->user->forceFill(['whatsapp_opt_in_at' => now()->addMinute()])->save();
        $this->assertSame('sent', $this->raise($this->user));
    }

    public function test_no_credentials_means_awaiting_credentials_and_no_request(): void
    {
        $this->chooseWhatsApp($this->user);
        config(['providers.channels.whatsapp' => NullWhatsAppProvider::class]);

        $this->assertSame('awaiting_credentials', $this->raise($this->user));
        $this->nothingReachedTheVendor();
    }

    public function test_quiet_hours_hold_it(): void
    {
        Carbon::setTestNow('2026-11-06 23:30:00');
        $this->chooseWhatsApp($this->user, extra: ['timezone' => 'UTC', 'quiet_hours' => json_encode(['enabled' => true, 'start' => '22:00', 'end' => '08:00'])]);

        $state = $this->raise($this->user);
        Carbon::setTestNow();

        $this->assertSame('suppressed_by_quiet_hours', $state);
        $this->nothingReachedTheVendor();
    }

    public function test_a_missing_template_never_falls_back_to_free_text(): void
    {
        $this->chooseWhatsApp($this->user);
        config(['services.twilio.whatsapp.content_sids' => []]);

        $this->assertSame('template_missing', $this->raise($this->user));
        $this->nothingReachedTheVendor();
    }

    public function test_a_person_is_messaged_at_most_ten_times_an_hour(): void
    {
        $this->chooseWhatsApp($this->user);
        $states = [];
        for ($i = 0; $i < WhatsAppChannel::PER_HOUR + 2; $i++) {
            $states[] = $this->raise($this->user);
        }

        $this->assertSame(WhatsAppChannel::PER_HOUR, count(array_filter($states, fn ($s) => $s === 'sent')));
        $this->assertSame(['rate_limited', 'rate_limited'], array_slice($states, -2));
    }

    public function test_a_member_whose_membership_withholds_money_gets_no_spend_bearing_whatsapp(): void
    {
        $buyer = $this->member([ProjectCapability::MONEY_VIEW]);
        $this->chooseWhatsApp($buyer);

        $this->assertSame('suppressed_client_audience', $this->raise($buyer));
        $this->nothingReachedTheVendor();
    }

    public function test_a_stored_switch_cannot_put_a_type_the_policy_never_sends_on_whatsapp(): void
    {
        $this->chooseWhatsApp($this->user, 'journey_transition');

        $state = $this->raise($this->user, ['type' => 'request.journey.paid', 'dedup_extra' => 'x']);

        $this->assertSame('not_offered', $state);
        $this->nothingReachedTheVendor();
    }

    public function test_stop_withdraws_consent_for_that_number_only(): void
    {
        $other = $this->member();
        $other->forceFill(['whatsapp_number' => '+966555000111'])->save();

        $hook = new WhatsAppOptOut;
        $this->assertSame(0, $hook->handleInbound('whatsapp:+966501234567', 'thanks!'));
        $this->assertSame(1, $hook->handleInbound('whatsapp:+966501234567', ' Stop '));
        $this->assertSame(0, $hook->handleInbound('whatsapp:+966599999999', 'STOP'));

        $this->assertNotNull($this->user->refresh()->whatsapp_opted_out_at);
        $this->assertNull($other->refresh()->whatsapp_opted_out_at);
        $this->assertTrue(WhatsAppOptOut::isStop('إيقاف'));
    }

    public function test_template_values_are_clean_and_short(): void
    {
        $v = WhatsAppTemplates::variables(['title' => "a\n\n  b\t c", 'detail' => str_repeat('س', 400)]);

        $this->assertSame('a b c', $v['1']);
        $this->assertSame(WhatsAppTemplates::MAX_VALUE, mb_strlen($v['2']));
        $this->assertSame('-', WhatsAppTemplates::variables([])['1'], 'an empty value is rejected by the vendor, so a dash stands in');
    }
}
