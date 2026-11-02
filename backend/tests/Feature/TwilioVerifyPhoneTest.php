<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ContactVerification;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TwilioVerifyPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'test-token',
            'services.twilio.verify_service_sid' => 'VA_test',
            'services.twilio.verify_channel' => 'sms',
        ]);
    }

    public function test_twilio_sends_and_only_approved_confirms_the_authenticated_users_number(): void
    {
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/Verifications')
            ? Http::response(['status' => 'pending'], 201)
            : Http::response(['status' => 'approved'], 200));

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $started = $this->postJson('/api/v1/me/phone/start', [
            'phone' => '050 123 4567',
            'channel' => 'sms',
        ])->assertOk()
            ->assertJsonPath('data.delivery_status', 'sent')
            ->assertJsonPath('data.dev_code', null);

        $id = (string) $started->json('data.verification_id');
        $this->postJson('/api/v1/me/phone/confirm', [
            'verification_id' => $id,
            'code' => '123456',
        ])->assertOk();

        $this->assertSame('+966501234567', $user->refresh()->phone);
        $this->assertNotNull($user->phone_verified_at);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Verifications')
            && $request['To'] === '+966501234567'
            && $request['Channel'] === 'sms');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/VerificationCheck')
            && $request['To'] === '+966501234567'
            && $request['Code'] === '123456');
    }

    public function test_pending_or_provider_failure_never_marks_the_phone_verified(): void
    {
        Http::fake([
            '*/Verifications' => Http::response(['status' => 'pending'], 201),
            '*/VerificationCheck' => Http::response(['status' => 'pending'], 200),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $id = (string) $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])
            ->json('data.verification_id');

        $this->postJson('/api/v1/me/phone/confirm', [
            'verification_id' => $id,
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertNull($user->refresh()->phone_verified_at);
        $this->assertNull(ContactVerification::findOrFail($id)->verified_at);

        Http::fake(['*/VerificationCheck' => Http::response(['code' => 20500], 500)]);
        $this->postJson('/api/v1/me/phone/confirm', [
            'verification_id' => $id,
            'code' => '111111',
        ])->assertStatus(422);
        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_a_challenge_cannot_be_verified_by_another_account(): void
    {
        Http::fake([
            '*/Verifications' => Http::response(['status' => 'pending'], 201),
            '*/VerificationCheck' => Http::response(['status' => 'approved'], 200),
        ]);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner, 'sanctum');
        $id = (string) $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])
            ->json('data.verification_id');

        $this->actingAs($other, 'sanctum')->postJson('/api/v1/me/phone/confirm', [
            'verification_id' => $id,
            'code' => '123456',
        ])->assertStatus(422);

        $this->assertNull($other->refresh()->phone_verified_at);
        Http::assertSentCount(1); // send only; ownership fails before a provider check
    }

    public function test_an_anonymous_challenge_cannot_cross_browser_bindings(): void
    {
        Http::fake([
            '*/Verifications' => Http::response(['status' => 'pending'], 201),
            '*/VerificationCheck' => Http::response(['status' => 'approved'], 200),
        ]);

        User::factory()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
        ]);

        $started = $this->withHeader('User-Agent', 'first-browser')
            ->postJson('/api/v1/auth/phone/start', ['phone' => '0501234567'])
            ->assertOk();

        $this->withHeader('User-Agent', 'different-browser')
            ->postJson('/api/v1/auth/phone/verify', [
                'verification_id' => (string) $started->json('data.verification_id'),
                'code' => '123456',
            ])->assertStatus(422);

        Http::assertSentCount(1); // ownership is rejected before Twilio receives the code
    }

    public function test_resend_is_server_side_and_invalidates_the_previous_challenge(): void
    {
        Http::fake(['*' => Http::response(['status' => 'pending'], 201)]);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $first = (string) $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])
            ->assertOk()->json('data.verification_id');
        $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])->assertStatus(422);

        ContactVerification::whereKey($first)->update(['last_sent_at' => now()->subSeconds(61)]);
        $this->postJson('/api/v1/me/phone/start', ['phone' => '0501234567'])->assertOk();
        $this->assertTrue(ContactVerification::findOrFail($first)->isExpired());
    }
}
