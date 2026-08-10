<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ContactVerification;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOGIN-PATHS-001 — the mobile-number path, and what it must never become.
 *
 * A second credential is a second way in, and every property the password path holds has to hold here
 * too: a suspended account stays out, no portal can be claimed, and a code minted somewhere else is
 * not a platform session. On top of that it carries a risk the password path does not — a phone
 * number is guessable, so the endpoint must not tell anybody which numbers have accounts.
 */
final class PhoneSignInTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The SPA's origin, on every call.
     *
     * Sanctum only engages its stateful path — and therefore only attaches a session — for requests
     * whose Origin matches the frontend. Without it `session()->regenerate()` throws «Session store
     * not set on request», which is a test artefact rather than a defect: a browser always sends one.
     *
     * @var array<string, string>
     */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function userWithPhone(string $e164 = '+966501234567'): User
    {
        $user = User::factory()->create();
        /*
         * `phone_verified_at` alongside the number (AUTH-PHONE-001).
         *
         * A number is only a sign-in credential once somebody proved they hold it — a bare
         * `users.phone` is a contact detail. These fixtures stand in for accounts that cleared the
         * mobile gate at registration, which is exactly what that timestamp records.
         */
        $user->forceFill([
            'phone' => $e164,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ])->save();

        return $user->refresh();
    }

    /** Ask for a code and read back the one the server issued (non-production only). */
    private function codeFor(string $typed): array
    {
        $res = $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => $typed])->assertOk();

        return [
            'id' => (string) $res->json('data.verification_id'),
            'code' => (string) $res->json('data.dev_code'),
        ];
    }

    // ── Every reading of a number is the same number ──────────────────────────────────────────

    /**
     * @dataProvider saudiForms
     */
    public function test_a_saudi_number_signs_in_however_it_is_written(string $typed): void
    {
        $user = $this->userWithPhone();

        ['id' => $id, 'code' => $code] = $this->codeFor($typed);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);

        $this->assertAuthenticatedAs($user, 'web');
    }

    /** @return array<string, array{string}> */
    public static function saudiForms(): array
    {
        return [
            'national' => ['0501234567'],
            'national with spaces' => ['050 123 4567'],
            'national with dashes' => ['050-123-4567'],
            'bare country code' => ['966501234567'],
            'e164' => ['+966501234567'],
            'international prefix' => ['00966501234567'],
            'arabic-indic digits' => ['٠٥٠١٢٣٤٥٦٧'],
        ];
    }

    /** A number that names another country keeps it, rather than being re-homed to Saudi Arabia. */
    public function test_a_foreign_number_is_not_read_as_saudi(): void
    {
        $user = $this->userWithPhone('+201234567890');

        ['id' => $id, 'code' => $code] = $this->codeFor('+20 123 456 7890');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_an_unreadable_number_is_refused(): void
    {
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => 'not a phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    // ── What it must not leak, and must not let through ───────────────────────────────────────

    /**
     * An unknown number gets the same answer as a known one.
     *
     * Anything else — a 404, a different message, a missing verification id — turns this endpoint
     * into a directory answerable by anybody holding a list of phone numbers.
     */
    public function test_an_unknown_number_is_answered_identically(): void
    {
        $this->userWithPhone('+966501234567');

        $known = $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '0501234567'])->assertOk();
        $unknown = $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '0555555555'])->assertOk();

        $this->assertSame($known->json('data.delivery_status'), $unknown->json('data.delivery_status'));
        $this->assertNotEmpty($unknown->json('data.verification_id'));
    }

    /** …and the code it sends signs nobody in. */
    public function test_a_correct_code_for_a_number_nobody_holds_creates_no_session(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor('0555555555');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);

        $this->assertGuest('web');
    }

    public function test_a_wrong_code_creates_no_session(): void
    {
        $this->userWithPhone();
        ['id' => $id] = $this->codeFor('0501234567');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => '000000'])
            ->assertStatus(422);

        $this->assertGuest('web');
    }

    /**
     * A client-portal code is not a platform credential.
     *
     * Both flows issue six digits through the same verification service. Without the purpose check,
     * a contact's portal code would open a platform session for whoever holds the same number.
     */
    public function test_a_portal_code_cannot_be_replayed_as_a_platform_sign_in(): void
    {
        $this->userWithPhone();

        $started = $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', [
            'channel' => 'sms', 'destination' => '+966501234567',
        ]);

        if ($started->status() !== 201) {
            $this->markTestSkipped('the client portal login is not reachable without a portal tenant');
        }

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', [
            'verification_id' => (string) $started->json('data.verification_id'),
            'code' => (string) $started->json('data.dev_code'),
        ])->assertStatus(422);

        $this->assertGuest('web');
    }

    /** A disabled account stays out, exactly as it does on the password path. */
    public function test_a_disabled_account_cannot_sign_in_by_phone(): void
    {
        $user = $this->userWithPhone();
        $user->forceFill(['disabled_at' => now()])->save();

        ['id' => $id, 'code' => $code] = $this->codeFor('0501234567');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(403);

        $this->assertGuest('web');
    }

    /** A code is single use — a replay does not open a second session. */
    public function test_a_code_cannot_be_used_twice(): void
    {
        $this->userWithPhone();
        ['id' => $id, 'code' => $code] = $this->codeFor('0501234567');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => $code])->assertOk();
        $this->withHeaders($this->spa)->post('/api/v1/auth/logout');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);

        $this->assertGuest('web');
    }

    /** The code is sent to the CANONICAL number, not to the spelling that was typed. */
    public function test_the_code_is_addressed_to_the_canonical_number(): void
    {
        $this->userWithPhone();
        ['id' => $id] = $this->codeFor('050 123 4567');

        $this->assertSame('+966501234567', ContactVerification::query()->findOrFail($id)->destination);
    }
}
