<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LOGIN-NO-EMAIL-CODE-001 — the credential properties of the code that now lives only on the client contact's door.
 *
 * These were proven on `/auth/email-code/*`, removed by #943, and the portal engine held fewer of them: its code reached no inbox, could
 * be spent repeatedly, and ignored a suspended account. Each case here is the portal's own.
 */
final class PortalEmailSignInTest extends TestCase
{
    use RefreshDatabase;

    private const CONTACT = 'client@example.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active', 'is_default_portal' => true]);
        $this->nameOnARequest(self::CONTACT);
    }

    private function nameOnARequest(string $email): void
    {
        ExternalRequest::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'REQ-'.uniqid(),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'new')->firstOrFail()->id,
            'contact_name' => 'Contact', 'contact_email' => $email, 'contact_phone' => '+966500000000', 'submitted_at' => now(),
        ]);
    }

    /** @return array{id: string, code: string} */
    private function codeFor(string $email = self::CONTACT): array
    {
        $res = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();

        return ['id' => (string) $res->json('data.verification_id'), 'code' => (string) $res->json('data.dev_code')];
    }

    private function verify(string $id, string $code): TestResponse
    {
        return $this->postJson('/api/v1/client/login/verify', ['verification_id' => $id, 'code' => $code]);
    }

    private function mailOn(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'services.notifications.email_enabled' => true]);
    }

    public function test_a_contact_is_actually_sent_the_code(): void
    {
        $this->mailOn();

        $res = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => self::CONTACT])->assertCreated();

        $this->assertNotNull(DB::table('mail_deliveries')->where('recipient', self::CONTACT)->first(), 'nothing tried to send the sign-in code');
        $this->assertContains($res->json('data.delivery_status'), ['sent', 'sandbox', 'awaiting_credentials', 'failed']);
        $this->assertGreaterThan(0, $res->json('data.expires_in_minutes'));
    }

    public function test_a_client_portal_member_is_sent_the_code(): void
    {
        $this->mailOn();
        $user = User::factory()->create(['email' => 'member@example.test']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'portal' => Portal::ClientPortal->value, 'status' => 'active']);

        $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'member@example.test'])->assertCreated();

        $this->assertNotNull(DB::table('mail_deliveries')->where('recipient', 'member@example.test')->first());
    }

    public function test_a_stranger_is_answered_identically_and_mailed_nothing(): void
    {
        $this->mailOn();

        $known = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => self::CONTACT])->assertCreated();
        $unknown = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'nobody@example.test'])->assertCreated();

        $this->assertSame(array_keys((array) $known->json('data')), array_keys((array) $unknown->json('data')));
        $this->assertNull(DB::table('mail_deliveries')->where('recipient', 'nobody@example.test')->first());
        Mail::assertNotSent(CredentialMail::class, fn (CredentialMail $m) => $m->hasTo('nobody@example.test'));

        // The cooldown answers the same too, so a second request cannot tell them apart either.
        $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'nobody@example.test'])->assertStatus(422);
    }

    public function test_a_platform_user_who_is_no_contact_is_mailed_nothing(): void
    {
        $this->mailOn();
        User::factory()->create(['email' => 'operator@example.test']);

        $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'operator@example.test'])->assertCreated();

        $this->assertNull(DB::table('mail_deliveries')->where('recipient', 'operator@example.test')->first());
    }

    public function test_with_no_provider_nothing_is_sent_and_the_answer_says_so(): void
    {
        Mail::fake();
        config(['requests.verification.providers.email' => false]);

        $res = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => self::CONTACT])->assertCreated();

        $this->assertNotContains($res->json('data.delivery_status'), ['sent', 'delivered']);
        Mail::assertNothingOutgoing();
    }

    public function test_a_correct_code_opens_a_portal_session(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor();

        $this->verify($id, $code)->assertOk()->assertJsonPath('data.authenticated', true);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor();

        $this->verify($id, $code === '000000' ? '111111' : '000000')->assertStatus(422);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor();

        $this->verify($id, $code)->assertOk();
        $this->verify($id, $code)->assertStatus(422);
    }

    public function test_an_expired_code_is_refused(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor();
        ContactVerification::query()->whereKey($id)->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->verify($id, $code)->assertStatus(422);
    }

    public function test_a_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->codeFor();
        ContactVerification::query()->update(['last_sent_at' => Carbon::now()->subMinutes(5)]);
        $second = $this->codeFor();

        $this->verify($first['id'], $first['code'])->assertStatus(422);
        $this->verify($second['id'], $second['code'])->assertOk();
    }

    public function test_a_second_code_inside_the_cooldown_is_refused(): void
    {
        $this->codeFor();

        $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => self::CONTACT])->assertStatus(422);
    }

    public function test_a_code_from_another_purpose_is_not_a_portal_credential(): void
    {
        $start = $this->postJson('/api/v1/requests/verify/start', ['channel' => 'email', 'destination' => self::CONTACT])->assertCreated();

        $this->verify((string) $start->json('data.verification_id'), (string) $start->json('data.dev_code'))->assertStatus(422);
    }

    public function test_a_disabled_account_cannot_sign_in_with_a_code(): void
    {
        $user = User::factory()->create(['email' => self::CONTACT]);
        $user->forceFill(['disabled_at' => now()])->save();
        ['id' => $id, 'code' => $code] = $this->codeFor();

        $this->verify($id, $code)->assertForbidden();
    }

    public function test_the_code_is_never_stored_in_the_clear_nor_in_the_audit_trail(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor();

        $row = ContactVerification::query()->findOrFail($id);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(hash('sha256', $code), $row->code_hash);

        $this->verify($id, $code)->assertOk();
        foreach (DB::table('audit_logs')->where('action', 'like', 'auth.portal_code.%')->get() as $entry) {
            $this->assertStringNotContainsString($code, (string) $entry->after);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'auth.portal_code.signed_in')->count());
    }

    public function test_production_never_returns_the_code_to_the_browser(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $res = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => self::CONTACT]);

        $this->assertNull($res->json('data.dev_code'));
    }
}
