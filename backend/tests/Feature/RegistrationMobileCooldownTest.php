<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\RegistrationRequest;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * The mobile resend cooldown protects the SMS provider, so it applies only to a challenge a provider actually sent.
 *
 * Confirming the email issues the first mobile challenge automatically. With no provider configured that challenge sent nothing,
 * and a cooldown on it blocked the only way a non-production applicant receives the code — 109 suites failed on exactly that.
 * With Twilio configured, an immediate second request is refused, so one tap cannot buy two SMS.
 */
final class RegistrationMobileCooldownTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function confirmedEmail(): RegistrationRequest
    {
        $applied = $this->apply()->assertStatus(202);
        $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        return RegistrationRequest::query()->latest('created_at')->firstOrFail();
    }

    public function test_a_challenge_no_provider_sent_can_be_reissued_at_once(): void
    {
        $registration = $this->confirmedEmail();

        $this->assertSame(0, $registration->mobileResendAfter());
        $this->withHeaders($this->spaOrigin)
            ->postJson("/api/v1/auth/registration/{$registration->getKey()}/resend", ['channel' => 'mobile'])
            ->assertOk()
            ->assertJsonPath('data.verification.resend_after', 0);
    }

    public function test_a_challenge_twilio_sent_holds_the_cooldown(): void
    {
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'test-token',
            'services.twilio.verify_service_sid' => 'VA_test',
            'services.twilio.verify_channel' => 'sms',
        ]);
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/Verifications')
            ? Http::response(['status' => 'pending'], 201)
            : Http::response([], 200));

        $registration = $this->confirmedEmail();

        $this->assertGreaterThan(0, $registration->mobileResendAfter());
        $this->withHeaders($this->spaOrigin)
            ->postJson("/api/v1/auth/registration/{$registration->getKey()}/resend", ['channel' => 'mobile'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/Verifications')));
    }
}
