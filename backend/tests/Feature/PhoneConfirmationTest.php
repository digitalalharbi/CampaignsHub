<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AUTH-PHONE-001 — a number is a credential only once somebody proved they hold it.
 *
 * ## The hole
 *
 * `PhoneSignInController` signs in whoever answers a code sent to `users.phone`, and
 * `PATCH /me/profile` wrote that column straight from a payload with a format rule and nothing else.
 * A number nobody had proved control of was therefore a way into an account.
 *
 * It is not only an attack. Somebody correcting a typo could put a stranger's number on their
 * account, and that stranger — holding their own phone, doing nothing wrong — could sign in as them.
 */
final class PhoneConfirmationTest extends TestCase
{
    /** @var array<string, string> */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function user(string $email = 'owner@example.test', ?string $phone = null, bool $confirmed = false): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill([
            'email_verified_at' => now(),
            'phone' => $phone,
            'phone_verified_at' => $phone !== null && $confirmed ? now() : null,
        ])->save();

        return $user->refresh();
    }

    /** @return array{id: string, code: string} */
    private function requestCode(string $phone, string $channel = 'sms'): array
    {
        $res = $this->postJson('/api/v1/me/phone/start', ['phone' => $phone, 'channel' => $channel])->assertOk();

        return ['id' => (string) $res->json('data.verification_id'), 'code' => (string) $res->json('data.dev_code')];
    }

    // ── the hole itself ───────────────────────────────────────────────────────────────────────

    /**
     * A number typed into a profile does NOT become a way in.
     *
     * The whole defect in one test: set a number, ask for a sign-in code, answer it correctly, and
     * get nowhere — because nobody proved the number belongs to this account.
     */
    public function test_a_number_set_from_a_profile_cannot_sign_anybody_in(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/me/profile', ['phone' => '0501234567'])
            ->assertOk();

        $this->assertNull($user->refresh()->phone_verified_at);
        $this->assertSame('+966501234567', $user->phone, 'the number is still kept as a contact detail');

        $start = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/phone/start', ['phone' => '0501234567'])->assertOk();

        /*
         * The 422 IS the refusal, and it is what this test rests on.
         *
         * `assertGuest()` cannot follow: the profile edit above went through `actingAs`, which sets
         * the guard in the TEST process, and Laravel's client shares that across the requests in a
         * test rather than doing a cookie round trip. Asserting guest here would be asserting that
         * the test cleared its own state — true, and nothing to do with the product. The browser-level
         * version of this claim lives in the E2E suite, where cookies are real.
         */
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', [
            'verification_id' => (string) $start->json('data.verification_id'),
            'code' => (string) $start->json('data.dev_code'),
        ])->assertStatus(422);
    }

    /** Changing a CONFIRMED number withdraws the proof — the new one has none. */
    public function test_changing_a_confirmed_number_withdraws_the_proof(): void
    {
        $user = $this->user('owner@example.test', '+966500000001', confirmed: true);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/me/profile', ['phone' => '0500000002'])
            ->assertOk();

        $this->assertNull($user->refresh()->phone_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.phone_unverified']);
    }

    /**
     * Re-saving the SAME number, written differently, does not withdraw anything.
     *
     * `05…` and `+9665…` are one number. Treating a re-spelling as a change would quietly disable
     * somebody's sign-in method every time they touched an unrelated profile field.
     */
    public function test_rewriting_the_same_number_keeps_the_proof(): void
    {
        $user = $this->user('owner@example.test', '+966500000001', confirmed: true);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/me/profile', ['phone' => '0500000001'])
            ->assertOk();

        $this->assertNotNull($user->refresh()->phone_verified_at);
    }

    /** A confirmed number still signs in — the credential works, it just has to be earned. */
    public function test_a_confirmed_number_still_signs_in(): void
    {
        $user = $this->user('owner@example.test', '+966501234567', confirmed: true);

        $start = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/phone/start', ['phone' => '0501234567'])->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', [
            'verification_id' => (string) $start->json('data.verification_id'),
            'code' => (string) $start->json('data.dev_code'),
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Two accounts on one number: the EARLIEST proof wins, not whichever row came back first.
     *
     * «Whatever the database returned» is not an acceptable answer to «whose account is this».
     */
    public function test_when_two_accounts_share_a_number_the_earliest_proof_wins(): void
    {
        $first = $this->user('first@example.test', '+966509999999', confirmed: true);
        $first->forceFill(['phone_verified_at' => Carbon::now()->subYear()])->save();

        $this->user('second@example.test', '+966509999999', confirmed: true);

        $start = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/phone/start', ['phone' => '0509999999'])->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', [
            'verification_id' => (string) $start->json('data.verification_id'),
            'code' => (string) $start->json('data.dev_code'),
        ])->assertOk();

        $this->assertAuthenticatedAs($first->refresh());
    }

    // ── proving it, from Account security ─────────────────────────────────────────────────────

    public function test_a_code_confirms_the_number_and_makes_it_a_credential(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');

        ['id' => $id, 'code' => $code] = $this->requestCode('0501234567');

        $this->postJson('/api/v1/me/phone/confirm', ['verification_id' => $id, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.confirmed', true);

        $this->assertSame('+966501234567', $user->refresh()->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.phone_confirmed']);
    }

    public function test_a_wrong_code_confirms_nothing(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');

        ['id' => $id, 'code' => $code] = $this->requestCode('0501234567');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/me/phone/confirm', ['verification_id' => $id, 'code' => $wrong])
            ->assertStatus(422);

        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_an_expired_code_confirms_nothing(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');

        ['id' => $id, 'code' => $code] = $this->requestCode('0501234567');
        ContactVerification::query()->whereKey($id)->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/v1/me/phone/confirm', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);

        $this->assertNull($user->refresh()->phone_verified_at);
    }

    /** Single use: the same code cannot go on to prove a second number. */
    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum');

        ['id' => $id, 'code' => $code] = $this->requestCode('0501234567');
        $this->postJson('/api/v1/me/phone/confirm', ['verification_id' => $id, 'code' => $code])->assertOk();

        $this->postJson('/api/v1/me/phone/confirm', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);
    }

    /** A sign-in code is not proof of a number, whatever it verifies. */
    public function test_a_code_from_another_purpose_cannot_prove_a_number(): void
    {
        $user = $this->user();

        $issued = app(ContactVerificationService::class)
            ->start('sms', '+966501234567', 'phone_sign_in');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/phone/confirm', [
            'verification_id' => $issued['id'],
            'code' => (string) $issued['dev_code'],
        ])->assertStatus(422);

        $this->assertNull($user->refresh()->phone_verified_at);
    }

    /** The resend window is enforced on the server, exactly as it is for a sign-in code. */
    public function test_a_second_code_inside_the_cooldown_is_refused(): void
    {
        $this->actingAs($this->user(), 'sanctum');

        $this->requestCode('0501234567');

        $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])->assertStatus(422);
    }

    /** Withdrawing the number as a sign-in method keeps it as a way to reach somebody. */
    public function test_revoking_keeps_the_number_and_drops_the_credential(): void
    {
        $user = $this->user('owner@example.test', '+966501234567', confirmed: true);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me/phone')->assertOk();

        $this->assertNull($user->refresh()->phone_verified_at);
        $this->assertSame('+966501234567', $user->phone);
    }

    /**
     * The channel states are reported honestly, because they decide what the UI may OFFER.
     *
     * With no WhatsApp provider wired, a page that showed «sign in with WhatsApp» would be a button
     * that cannot work. READY_FOR_CREDENTIALS is a fact on this response, not a claim in a document.
     */
    public function test_the_channel_states_are_reported_honestly(): void
    {
        config(['requests.verification.providers.whatsapp' => false]);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/me/phone')
            ->assertOk()
            ->assertJsonPath('data.channels.whatsapp', false)
            ->assertJsonPath('data.confirmed', false);
    }

    /** None of this is reachable without a session — it changes a credential. */
    public function test_the_confirmation_endpoints_require_a_session(): void
    {
        $this->getJson('/api/v1/me/phone')->assertUnauthorized();
        $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])->assertUnauthorized();
        $this->postJson('/api/v1/me/phone/confirm', ['verification_id' => 'x', 'code' => '000000'])->assertUnauthorized();
        $this->deleteJson('/api/v1/me/phone')->assertUnauthorized();
    }
}
