<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationVerification;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Models\PlatformInboxReply;
use App\Domains\Legal\Models\SupportTicket;
use App\Domains\Notifications\Mail\PlatformReplyMail;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestComment;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AppliesToRegister;
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

/**
 * PLATFORM-INBOX-ATTENTION-001 — what is addressed to the platform is read, counted and answered (ledger #169).
 *
 * Before: contact messages, support tickets and data requests had an API and no screen, nobody was told when one
 * arrived, the console's registrations badge counted two states `AccountState` does not have, and an application
 * held at a verification step could only be looked at. Each test below failed on `origin/main` `25a6a1981`.
 */
final class PlatformInboxAttentionTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;
    use VerifiesContact;

    private User $admin;

    private User $agencyOwner;

    private Tenant $agency;

    /** @var array<string, string> */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create(['name' => 'Platform Owner', 'email' => 'owner@campaignshub.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        $this->admin->refresh();

        $this->agency = Tenant::create(['name' => 'Subscriber Agency', 'slug' => 'subscriber-agency', 'status' => 'active', 'portal_enabled' => true]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->agencyOwner = User::create(['name' => 'Agency Owner', 'email' => 'owner@subscriber.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($this->agencyOwner, $this->agency, Portal::Agency);
        $this->agencyOwner->assignRole($role);
        app(TenantContext::class)->forget();
    }

    private function asAdmin(): self
    {
        app(TenantContext::class)->forget();

        return $this->actingAs($this->admin, 'sanctum');
    }

    private function asAgency(): self
    {
        app(TenantContext::class)->setTenantId($this->agency->id);

        return $this->actingAs($this->agencyOwner, 'sanctum');
    }

    private function mailConfigured(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        Mail::fake();
    }

    private function contact(string $email = 'visitor@example.test'): ContactMessage
    {
        $this->postJson('/api/v1/contact', [
            'name' => 'Visitor', 'email' => $email,
            'subject' => 'A question', 'message' => 'I would like to know more about the plans.',
        ])->assertOk();

        return ContactMessage::query()->where('email', $email)->sole();
    }

    private function ticket(): SupportTicket
    {
        $this->postJson('/api/v1/support/tickets', [
            'name' => 'Visitor', 'email' => 'ticket@example.test',
            'subject' => 'Cannot connect', 'message' => 'My Snapchat account will not connect at all.',
        ])->assertOk();

        return SupportTicket::query()->sole();
    }

    // ── 1. Told at receipt, counted until opened ─────────────────────────────────────────────

    public function test_each_public_queue_tells_every_platform_administrator_and_nobody_else(): void
    {
        $second = User::create(['name' => 'Second', 'email' => 'second@campaignshub.test', 'password' => 'secret1234']);
        $second->forceFill(['is_platform_admin' => true])->save();

        $message = $this->contact();
        $ticket = $this->ticket();
        $this->postJson('/api/v1/data-requests', [
            'type' => 'export', 'name' => 'Visitor', 'email' => 'data@example.test', 'details' => 'Please export my data.',
        ])->assertOk();
        $data = DataRequest::query()->sole();

        $rows = DB::table('mail_deliveries')->where('kind', 'client_request')->get();
        $this->assertCount(6, $rows, 'three arrivals × two administrators');
        $this->assertEqualsCanonicalizing([$this->admin->id, $second->id], $rows->pluck('user_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
        $this->assertSame([null], $rows->pluck('tenant_id')->unique()->values()->all());
        foreach (['contact:'.$message->id, 'support:'.$ticket->id, 'data:'.$data->id] as $key) {
            $this->assertSame(2, $rows->filter(fn ($r): bool => str_starts_with((string) $r->dedup_key, 'platform-inbox:'.$key.':'))->count(), $key);
        }

        // No subscriber, and not the visitor, is addressed about it.
        $this->assertSame(0, DB::table('mail_deliveries')->where('recipient', $this->agencyOwner->email)->count());
        $this->assertSame(0, DB::table('mail_deliveries')->whereIn('recipient', ['visitor@example.test', 'ticket@example.test'])->count());
    }

    public function test_a_burst_on_a_public_form_does_not_become_a_burst_of_mail(): void
    {
        config(['requests.platform_inbox.notify_per_hour' => 2]);

        foreach (['a', 'b', 'c'] as $who) {
            $this->contact("{$who}@example.test");
        }

        $this->assertSame(3, ContactMessage::query()->count(), 'every message is still stored');
        $this->assertSame(2, DB::table('mail_deliveries')->where('kind', 'client_request')->count());
    }

    public function test_the_counters_say_what_nobody_has_opened_and_opening_marks_it_read(): void
    {
        $message = $this->contact();
        $ticket = $this->ticket();

        $this->asAdmin()->getJson('/api/v1/admin/inbox/summary', $this->spa)->assertOk()
            ->assertJsonPath('data.contact.unread', 1)
            ->assertJsonPath('data.support.unread', 1)
            ->assertJsonPath('data.data.unread', 0)
            ->assertJsonPath('data.unread', 2);

        $attention = fn (): array => collect($this->asAdmin()->getJson('/api/v1/admin/overview', $this->spa)->assertOk()->json('data.attention'))
            ->keyBy('key')->all();
        $this->assertSame(2, (int) $attention()['platform_inbox_unread']['count']);
        $this->assertSame('/admin/inbox', $attention()['platform_inbox_unread']['to']);

        $this->asAdmin()->getJson('/api/v1/admin/legal/contact-messages/'.$message->id, $this->spa)->assertOk()
            ->assertJsonPath('data.message.status', 'read')
            ->assertJsonPath('data.replies', []);
        $this->assertNotNull($message->fresh()->read_at);
        $this->assertSame($this->admin->id, (int) $message->fresh()->read_by);

        $this->asAdmin()->getJson('/api/v1/admin/legal/support-tickets/'.$ticket->id, $this->spa)->assertOk();

        $this->asAdmin()->getJson('/api/v1/admin/inbox/summary', $this->spa)->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame(0, (int) $attention()['platform_inbox_unread']['count']);
        $this->asAdmin()->getJson('/api/v1/admin/legal/contact-messages', $this->spa)->assertOk()->assertJsonPath('data.unread', 0);
    }

    public function test_no_tenant_session_reaches_the_inbox_its_rows_or_a_reply(): void
    {
        $message = $this->contact();
        $ticket = $this->ticket();

        $this->getJson('/api/v1/admin/inbox/summary')->assertUnauthorized();
        $this->asAgency()->getJson('/api/v1/admin/inbox/summary')->assertForbidden();
        $this->asAgency()->getJson('/api/v1/admin/legal/contact-messages/'.$message->id)->assertForbidden();
        $this->asAgency()->postJson('/api/v1/admin/legal/contact-messages/'.$message->id.'/reply', ['body' => 'Hello there'])->assertForbidden();
        $this->asAgency()->postJson('/api/v1/admin/legal/support-tickets/'.$ticket->id.'/reply', ['body' => 'Hello there'])->assertForbidden();

        $this->assertNull($message->fresh()->read_at, 'a refused read marks nothing');
        $this->assertSame(0, PlatformInboxReply::query()->count());
    }

    // ── 2. The registrations badge counts what exists ───────────────────────────────────────

    public function test_the_registrations_badge_counts_pending_approval_and_names_the_unverified_separately(): void
    {
        $this->apply(['email' => 'waiting@example.test'])->assertStatus(202);
        $this->apply(['email' => 'unverified@example.test'])->assertStatus(202);
        RegistrationRequest::query()->where('email', 'waiting@example.test')->sole()->forceFill(['state' => 'pending_approval'])->save();

        $attention = collect($this->asAdmin()->getJson('/api/v1/admin/overview', $this->spa)->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertSame(1, (int) $attention['registrations_pending']['count']);
        $this->assertSame(1, (int) $attention['registrations_unverified']['count']);
    }

    // ── 3. Stuck at a verification step ─────────────────────────────────────────────────────

    private function heldAtMobile(): RegistrationRequest
    {
        $applied = $this->apply(['email' => 'stuck@example.test'])->assertStatus(202);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        $registration = RegistrationRequest::query()->where('email', 'stuck@example.test')->sole();
        $this->assertSame('mobile_verification_required', $registration->state->value);

        return $registration;
    }

    public function test_an_application_held_at_a_verification_step_shows_the_step_and_its_history(): void
    {
        $registration = $this->heldAtMobile();

        $row = collect($this->asAdmin()->getJson('/api/v1/admin/registrations', $this->spa)->assertOk()->json('data.registrations'))
            ->firstWhere('id', (string) $registration->id);

        $this->assertSame('mobile_verification_required', $row['state']);
        $this->assertSame('mobile', $row['verification_stage']['channel']);
        $this->assertNotNull($row['verification_stage']['since']);
        $this->assertIsInt($row['verification_stage']['sends']);
    }

    public function test_an_administrator_resends_the_challenge_and_nothing_is_verified_or_created(): void
    {
        $registration = $this->heldAtMobile();
        $users = User::query()->count();
        config(['accounts.mobile_resend_cooldown_seconds' => 0]);

        $res = $this->asAdmin()->postJson('/api/v1/admin/registrations/'.$registration->id.'/resend-verification', [], $this->spa)->assertOk();

        $res->assertJsonPath('data.verification.channel', 'mobile');
        $this->assertArrayNotHasKey('dev_code', (array) $res->json('data.verification'));
        $this->assertArrayNotHasKey('dev_link', (array) $res->json('data.verification'));
        $this->assertStringNotContainsString('dev_code', (string) $res->getContent());

        $registration->refresh();
        $this->assertSame('mobile_verification_required', $registration->state->value, 'still waiting on the applicant');
        $this->assertFalse($registration->mobileIsVerified());
        $this->assertFalse($registration->isProvisioned());
        $this->assertSame($users, User::query()->count(), 'no account was created');
        $this->assertSame(0, Membership::query()->where('tenant_id', '!=', $this->agency->id)->count());
        $this->assertSame(1, RegistrationVerification::query()->where('registration_request_id', $registration->id)->where('channel', 'mobile')->whereNull('consumed_at')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'registration.verification_resent')->where('entity_id', (string) $registration->id)->count());
    }

    public function test_the_resend_is_refused_where_there_is_nothing_to_verify_and_to_a_tenant(): void
    {
        $this->apply(['email' => 'review@example.test'])->assertStatus(202);
        $review = RegistrationRequest::query()->where('email', 'review@example.test')->sole();
        $review->forceFill(['state' => 'pending_approval'])->save();

        $this->asAdmin()->postJson('/api/v1/admin/registrations/'.$review->id.'/resend-verification', [], $this->spa)->assertStatus(422);

        $review->forceFill(['state' => 'rejected'])->save();
        $this->asAdmin()->postJson('/api/v1/admin/registrations/'.$review->id.'/resend-verification', [], $this->spa)->assertStatus(422);

        $stuck = $this->heldAtMobile();
        $this->asAgency()->postJson('/api/v1/admin/registrations/'.$stuck->id.'/resend-verification')->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'registration.verification_resent')->count());
    }

    // ── 4. Replying to the visitor ──────────────────────────────────────────────────────────

    public function test_an_administrator_replies_to_a_contact_message_by_email_to_the_address_on_record(): void
    {
        $this->mailConfigured();
        $message = $this->contact();

        $res = $this->asAdmin()->postJson('/api/v1/admin/legal/contact-messages/'.$message->id.'/reply', [
            'body' => "Thank you for writing.\nThe Team plan suits you.",
            // Not a field: an address in the payload is ignored, never used as the recipient.
            'email' => 'someone-else@example.test',
        ], $this->spa)->assertOk();

        Mail::assertSent(PlatformReplyMail::class, fn (PlatformReplyMail $m): bool => $m->hasTo('visitor@example.test') && str_contains($m->body, 'Team plan'));
        Mail::assertNotSent(PlatformReplyMail::class, fn (PlatformReplyMail $m): bool => $m->hasTo('someone-else@example.test'));

        $reply = PlatformInboxReply::query()->sole();
        $this->assertSame('contact_message', $reply->subject_type);
        $this->assertSame('visitor@example.test', $reply->recipient);
        $this->assertSame((int) $this->admin->id, (int) $reply->author_id);
        $this->assertSame('sent', $reply->delivery_status);
        $this->assertSame(1, DB::table('mail_deliveries')->where('dedup_key', $reply->mail_dedup_key)->where('recipient', 'visitor@example.test')->count());

        $res->assertJsonPath('data.reply.delivery_status', 'sent')->assertJsonPath('data.message.status', 'answered');
        $this->assertCount(1, $res->json('data.replies'));
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.inbox.replied')->where('entity_id', (string) $message->id)->count());
        $this->assertStringNotContainsString('Team plan', (string) json_encode(AuditLog::query()->where('action', 'platform.inbox.replied')->sole()->after), 'the audit names the record, not the words');
    }

    public function test_a_reply_without_a_mail_provider_says_so_rather_than_sent(): void
    {
        $ticket = $this->ticket();

        $res = $this->asAdmin()->postJson('/api/v1/admin/legal/support-tickets/'.$ticket->id.'/reply', ['body' => 'We are looking at it.'], $this->spa)->assertOk();

        $this->assertNotSame('sent', $res->json('data.reply.delivery_status'));
        $this->assertSame('waiting_on_customer', $ticket->fresh()->status);
        $this->assertSame((int) $this->admin->id, (int) $ticket->fresh()->assigned_to);
    }

    public function test_replies_to_one_visitor_are_rate_limited(): void
    {
        $this->mailConfigured();
        config(['requests.platform_inbox.replies_per_record_per_hour' => 2]);
        $message = $this->contact();
        $url = '/api/v1/admin/legal/contact-messages/'.$message->id.'/reply';

        $this->asAdmin()->postJson($url, ['body' => 'First answer'], $this->spa)->assertOk();
        $this->asAdmin()->postJson($url, ['body' => 'Second answer'], $this->spa)->assertOk();
        $this->asAdmin()->postJson($url, ['body' => 'Third answer'], $this->spa)->assertStatus(429);

        $this->assertSame(2, PlatformInboxReply::query()->count());
        Mail::assertSent(PlatformReplyMail::class, 2);
    }

    public function test_a_platform_service_request_is_answered_on_the_tracking_page_and_by_email_and_a_subscribers_is_not_reachable(): void
    {
        $this->seed(RequestCatalogSeeder::class);
        $this->mailConfigured();

        $order = function (string $host, string $email): ExternalRequest {
            app(TenantContext::class)->forget();
            $this->postJson('http://'.$host.'/api/v1/requests', $this->withVerifiedContact([
                'type' => 'paid_campaign_launch', 'contact_name' => 'Visitor', 'contact_email' => $email, 'company_name' => 'Visitor Co',
            ]))->assertCreated();

            return ExternalRequest::query()->whereRaw('lower(contact_email) = ?', [$email])->sole();
        };

        $this->agency->forceFill(['is_default_portal' => true])->save();
        $mine = $order('campaignshub.io', 'platform-visitor@example.test');
        $theirs = $order('localhost', 'agency-visitor@example.test');

        $res = $this->asAdmin()->postJson('/api/v1/admin/service-requests/'.$mine->id.'/reply', ['body' => 'We can start next week.'], $this->spa)->assertOk();
        $this->assertCount(1, $res->json('data.request.replies'));
        $this->assertContains('We can start next week.', array_column($res->json('data.request.messages'), 'body'));

        $comment = RequestComment::query()->where('request_id', $mine->id)->sole();
        $this->assertSame('client', $comment->visibility);
        Mail::assertSent(PlatformReplyMail::class, fn (PlatformReplyMail $m): bool => $m->hasTo('platform-visitor@example.test'));

        $this->asAdmin()->postJson('/api/v1/admin/service-requests/'.$theirs->id.'/reply', ['body' => 'Hello from the platform'], $this->spa)->assertNotFound();
        $this->assertSame(0, RequestComment::query()->where('request_id', $theirs->id)->count());
        Mail::assertNotSent(PlatformReplyMail::class, fn (PlatformReplyMail $m): bool => $m->hasTo('agency-visitor@example.test'));
    }
}
