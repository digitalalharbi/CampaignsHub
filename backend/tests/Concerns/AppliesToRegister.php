<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Walking the gated registration path from a test (SIGNUP-002).
 *
 * Registering and having an account are two events now, and a dozen tests were written when they
 * were one. The point of a shared helper is that those tests keep asserting what they were about —
 * the membership, the portal, the menu — instead of each one growing its own copy of the journey and
 * quietly drifting from it.
 *
 * `apply()` returns the raw response so tests about the APPLICATION can inspect it. `applyAndVerify()`
 * walks it to a workspace, and deliberately fails loudly if it does not get one: a test that expects
 * an active account must not silently continue against an applicant who is still in a queue.
 */
trait AppliesToRegister
{
    /** @var array<string, string> */
    protected array $spaOrigin = ['Origin' => 'http://localhost:5173'];

    /** @param array<string, mixed> $overrides */
    protected function apply(array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/register', array_merge([
            'tenant_name' => 'New Workspace',
            'name' => 'New Owner',
            'email' => 'new@owner.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ], $overrides));
    }

    /**
     * The dev verification token for an application. Exposed outside production only — see
     * RegistrationVerificationService — which is exactly the condition tests run under.
     */
    protected function verificationTokenFrom(TestResponse $response): string
    {
        $link = (string) $response->json('data.verification.dev_link');

        $this->assertNotSame('', $link, 'no dev verification link was issued for this application');

        return explode('token=', $link)[1];
    }

    /**
     * Apply, prove the email, and come back with the workspace it produced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{user: User, registration: RegistrationRequest, verify: TestResponse}
     */
    protected function applyAndVerify(array $overrides = []): array
    {
        $applied = $this->apply($overrides)->assertStatus(202);
        $email = (string) ($overrides['email'] ?? 'new@owner.test');

        $verify = $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        $registration = RegistrationRequest::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->latest('created_at')->firstOrFail();

        $this->assertTrue(
            $registration->isProvisioned(),
            'the application did not become a workspace — the registration policy still has a gate open',
        );

        return [
            'user' => User::where('email', $email)->firstOrFail(),
            'registration' => $registration,
            'verify' => $verify,
        ];
    }
}
