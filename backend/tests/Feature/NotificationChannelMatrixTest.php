<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Providers\TwilioWhatsAppProvider;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Notifications\Support\NotificationChannelPolicy;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * NOTIFICATION-CHANNELS-001 — the matrix a person edits is the matrix the product runs.
 *
 * Two halves. The first walks every type the policy says the bell carries, raises it through the real
 * dispatcher, and asserts the email ledger row says what the policy says. The second round-trips the
 * preferences API: the per-type chips, the locks, and the WhatsApp refusals.
 */
final class NotificationChannelMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        config([
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.provider.test', 'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.Str::random(5), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.Str::random(5), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.Str::random(5)]);
        $role->givePermissionTo('projects.view', 'campaigns.view', 'analytics.view');
        $this->user = User::create(['name' => 'M', 'email' => 'm-'.Str::random(6).'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now(), 'locale' => 'ar']);
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
        ProjectMembership::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $this->user->id, 'role' => ProjectRole::MEDIA_BUYER, 'status' => 'active', 'denied' => []]);
    }

    private function read(): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/settings/notifications');
    }

    /** @param array<string,mixed> $body */
    private function save(array $body): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/settings/notifications', $body);
    }

    private function optIn(): void
    {
        $this->user->forceFill(['whatsapp_number' => '+966501234567', 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'settings_checkbox'])->save();
    }

    // ── half one: the dispatcher does what the policy says ─────────────────────────────────────

    public function test_every_type_the_bell_carries_reaches_the_email_state_the_policy_states(): void
    {
        $dispatcher = app(NotificationDispatcher::class);
        $checked = 0;

        foreach (NotificationChannelPolicy::types() as $key => $entry) {
            if (! $entry['in_app']) {
                continue;
            }

            $dispatched = ['request' => 'request.assigned', 'journey_transition' => 'request.journey.paid'][$key] ?? $key;
            $source = in_array($key, ['budget_risk', 'sync_failed', 'token_expiring', 'lead_sla', 'performance', 'security'], true) ? 'alerts' : 'x';
            $n = $dispatcher->dispatch([
                'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id, 'project_id' => (string) $this->project->id,
                'type' => $dispatched, 'severity' => 'warning', 'title' => 'T '.$key, 'source' => $source,
                'entity_type' => 'x', 'entity_id' => $key, 'action_url' => '/x',
            ]);
            $this->assertNotNull($n, $key);
            $status = (string) DB::table('notification_deliveries')->where('notification_id', $n->id)->where('channel', 'email')->value('status');

            $route = NotificationChannelPolicy::routeFor($dispatched, $source);
            // A catalogued type answers to its default; one nobody can switch (`performance`, `security`, the test) is wanted.
            $wanted = ! MessageCatalogue::has($key) || $entry['default_email'];
            $expected = ! $wanted ? 'suppressed_by_preference' : match ($route) {
                'dispatcher' => 'mailed',
                'elsewhere' => 'handled_elsewhere',
                default => 'in_app_only',
            };

            if ($expected === 'mailed') {
                $this->assertContains($status, ['queued', 'sent', 'sandbox'], "«{$key}» is policy via=dispatcher but its ledger says {$status}");
            } else {
                $this->assertSame($expected, $status, "«{$key}»");
            }
            $this->assertSame(1, DB::table('notification_deliveries')->where('notification_id', $n->id)->where('channel', 'in_app')->where('status', 'sent')->count(), "«{$key}» in-app");
            $checked++;
        }

        $this->assertGreaterThan(12, $checked, 'the parity walk saw almost nothing');
    }

    // ── half two: the preferences API ──────────────────────────────────────────────────────────

    public function test_the_screen_is_told_what_each_channel_does_for_every_type(): void
    {
        $doc = $this->read()->assertOk()->json('data');
        $byKey = [];
        foreach ($doc['catalogue'] as $group) {
            foreach ($group['types'] as $t) {
                $byKey[$t['key']] = $t;
            }
        }

        $this->assertSame(['in_app' => true, 'email' => 'immediate', 'whatsapp' => 'planned'], $byKey['automation_rule']['channels']);
        $this->assertSame(['in_app' => false, 'email' => 'mandatory', 'whatsapp' => 'never'], $byKey['password_reset']['channels']);
        $this->assertSame('digest', $byKey['daily_digest']['channels']['email']);
        $this->assertTrue($doc['types']['automation_rule']['email'], 'the default is on for the rule the person wrote');
        $this->assertFalse($doc['types']['automation_rule']['whatsapp']);
        $this->assertFalse($doc['whatsapp']['provider_configured']);
        $this->assertFalse($doc['whatsapp']['opted_in']);
    }

    public function test_a_chip_round_trips(): void
    {
        $this->save(['types' => ['automation_rule' => ['email' => false, 'in_app' => true]]])->assertOk();

        $types = $this->read()->json('data.types');
        $this->assertFalse($types['automation_rule']['email']);
        $this->assertTrue($types['automation_rule']['in_app']);
    }

    public function test_reading_the_document_back_and_saving_it_changes_nothing_and_never_422s(): void
    {
        $doc = $this->read()->json('data');

        $this->save(['types' => $doc['types'], 'channels' => $doc['channels']])->assertOk();

        $this->assertSame($doc['types'], $this->read()->json('data.types'));
    }

    public function test_a_locked_type_cannot_be_switched_off_and_the_refusal_says_why(): void
    {
        $this->save(['types' => ['password_reset' => ['email' => false]]])->assertStatus(422);
        $this->save(['types' => ['security_alert' => ['in_app' => false]]])->assertStatus(422);
        $this->assertTrue($this->read()->json('data.types.password_reset.email'));
    }

    public function test_whatsapp_is_refused_with_no_provider_then_with_no_opt_in_and_then_for_a_type_that_never_goes(): void
    {
        $this->save(['types' => ['automation_rule' => ['whatsapp' => true]]])->assertStatus(422);

        config(['providers.channels.whatsapp' => TwilioWhatsAppProvider::class, 'services.twilio.account_sid' => 'ACx', 'services.twilio.auth_token' => 't', 'services.twilio.whatsapp.from' => '+14155550100']);
        $this->save(['types' => ['automation_rule' => ['whatsapp' => true]]])->assertStatus(422);

        $this->optIn();
        $this->save(['types' => ['daily_digest' => ['whatsapp' => true]]])->assertStatus(422);
        $this->save(['types' => ['password_reset' => ['whatsapp' => true]]])->assertStatus(422);
        $this->save(['types' => ['automation_rule' => ['whatsapp' => true]]])->assertOk();

        $this->assertTrue($this->read()->json('data.types.automation_rule.whatsapp'));
        $this->assertTrue($this->read()->json('data.whatsapp.opted_in'));
        $this->assertSame('•••••••••4567', $this->read()->json('data.whatsapp.number_hint'));
        Http::assertNothingSent();
    }

    public function test_a_locked_security_type_can_still_gain_whatsapp_and_keeps_its_email(): void
    {
        config(['providers.channels.whatsapp' => TwilioWhatsAppProvider::class, 'services.twilio.account_sid' => 'ACx', 'services.twilio.auth_token' => 't', 'services.twilio.whatsapp.from' => '+14155550100']);
        $this->optIn();

        $this->save(['types' => ['security_alert' => ['whatsapp' => true]]])->assertOk();

        $types = $this->read()->json('data.types');
        $this->assertTrue($types['security_alert']['whatsapp']);
        $this->assertTrue($types['security_alert']['email'], 'a locked email cannot be traded for the new channel');
    }

    public function test_withdrawing_consent_is_dated_and_keeps_the_number(): void
    {
        $this->optIn();

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/settings/notifications/whatsapp')->assertOk();

        $fresh = $this->user->refresh();
        $this->assertNotNull($fresh->whatsapp_opted_out_at);
        $this->assertSame('+966501234567', $fresh->whatsapp_number);
        $this->assertTrue($this->read()->json('data.whatsapp.opted_out'));
    }

    public function test_the_opt_in_needs_the_consent_box_and_a_code_and_never_completes_by_itself(): void
    {
        $actor = $this->actingAs($this->user, 'sanctum');

        $actor->postJson('/api/v1/settings/notifications/whatsapp/start', ['phone' => '0501234567'])->assertStatus(422);

        $start = $actor->postJson('/api/v1/settings/notifications/whatsapp/start', ['phone' => '0501234567', 'consent' => true])->assertOk();
        $this->assertSame('awaiting_provider_credentials', $start->json('data.delivery_status'), 'nothing was sent, and the answer says so');
        $this->assertNull($this->user->refresh()->whatsapp_opt_in_at);

        $actor->postJson('/api/v1/settings/notifications/whatsapp/confirm', ['verification_id' => $start->json('data.verification_id'), 'code' => '000000', 'consent' => true])->assertStatus(422);
        $this->assertNull($this->user->refresh()->whatsapp_opt_in_at, 'a wrong code opts nobody in');

        $actor->postJson('/api/v1/settings/notifications/whatsapp/confirm', ['verification_id' => $start->json('data.verification_id'), 'code' => (string) $start->json('data.dev_code'), 'consent' => false])->assertStatus(422);
        $this->assertNull($this->user->refresh()->whatsapp_opt_in_at, 'the code alone is not consent');

        $actor->postJson('/api/v1/settings/notifications/whatsapp/confirm', ['verification_id' => $start->json('data.verification_id'), 'code' => (string) $start->json('data.dev_code'), 'consent' => true])->assertOk();

        $fresh = $this->user->refresh();
        $this->assertSame('+966501234567', $fresh->whatsapp_number);
        $this->assertNotNull($fresh->whatsapp_opt_in_at);
        $this->assertSame('settings_checkbox', $fresh->whatsapp_opt_in_source);
        $this->assertNull($fresh->phone_verified_at ?? null, 'a WhatsApp opt-in is not a sign-in proof');
    }

    // ── the test buttons ───────────────────────────────────────────────────────────────────────

    public function test_the_in_app_test_creates_one_bell_item_for_the_signed_in_person_only(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/settings/notifications/channel-test', ['channel' => 'in_app'])
            ->assertOk()->assertJsonPath('data.state', 'sent');

        $this->assertSame(1, DB::table('app_notifications')->where('type', 'test_notification')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('mail_deliveries')->count(), 'the in-app test sends no mail');
    }

    public function test_the_email_test_goes_to_the_proved_address_marked_as_a_test_and_through_the_ledger(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/settings/notifications/channel-test', ['channel' => 'email']);
        $this->assertSame(200, $res->status(), $res->getContent());

        $row = DB::table('mail_deliveries')->where('kind', 'test')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->user->email, $row->recipient);
        $this->assertNull($row->tenant_id, 'a test belongs to no tenant');
    }

    public function test_the_email_test_refuses_an_address_that_was_never_proved(): void
    {
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/settings/notifications/channel-test', ['channel' => 'email'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'address_not_proven');
        $this->assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_the_whatsapp_test_is_refused_while_unavailable_and_never_reaches_a_vendor(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/settings/notifications/channel-test', ['channel' => 'whatsapp'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'whatsapp_not_available');

        config(['providers.channels.whatsapp' => TwilioWhatsAppProvider::class, 'services.twilio.account_sid' => 'ACx', 'services.twilio.auth_token' => 't', 'services.twilio.whatsapp.from' => '+14155550100']);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/settings/notifications/channel-test', ['channel' => 'whatsapp'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'whatsapp_no_opt_in');
        Http::assertNothingSent();
    }

    public function test_a_channel_outside_the_three_is_refused(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/settings/notifications/channel-test', ['channel' => 'sms'])->assertStatus(422);
    }
}
