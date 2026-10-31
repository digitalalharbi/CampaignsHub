<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * AUTH-LOGIN-UX-002 — the sign-in page offers only ways in that can work. The email code depends on mail, so it is withdrawn while
 * the mail transport's latest real attempt failed, and the endpoint says nothing else about the transport.
 */
final class SignInMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(string $status, int $minutesAgo): void
    {
        DB::table('mail_deliveries')->insert([
            'id' => (string) Uuid::uuid4(), 'kind' => 'password_reset', 'recipient' => 'sara@example.com', 'locale' => 'ar',
            'template' => 'credential', 'status' => $status, 'transport' => 'smtp', 'attempts' => 1, 'dedup_key' => (string) Uuid::uuid4(),
            'error' => $status === 'failed' ? 'Failed to authenticate on SMTP server with username "x@example.com"' : null,
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_a_fresh_environment_offers_the_email_code(): void
    {
        $this->getJson('/api/v1/auth/methods')->assertOk()
            ->assertJsonPath('data.email_code.available', true)
            ->assertJsonPath('data.password.available', true);
    }

    public function test_the_email_code_is_withdrawn_while_the_latest_mail_attempt_failed_and_returns_when_mail_works(): void
    {
        $this->delivery('sent', 30);
        $this->delivery('failed', 5);
        $res = $this->getJson('/api/v1/auth/methods')->assertOk()->assertJsonPath('data.email_code.available', false);
        $this->assertStringNotContainsString('SMTP', $res->getContent(), 'no transport detail leaves on a public route');
        $this->assertStringNotContainsString('example.com', $res->getContent());

        $this->delivery('sent', 1);
        $this->getJson('/api/v1/auth/methods')->assertOk()->assertJsonPath('data.email_code.available', true);
    }

    public function test_the_code_start_states_how_long_the_code_lives(): void
    {
        $this->postJson('/api/v1/auth/email-code/start', ['email' => 'nobody@example.com'])->assertOk()
            ->assertJsonPath('data.expires_in_minutes', (int) config('requests.verification.code_ttl_minutes', 10));
    }
}
