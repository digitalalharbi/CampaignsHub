<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** A suspended/disabled account (or suspended workspace) cannot log in, mint a token, or use any API. */
final class SuspendedAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'WS', 'slug' => 'ws', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function user(array $over = []): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);
        // disabled_at is intentionally NOT mass-assignable — set it explicitly (as an admin action would).
        if (array_key_exists('disabled_at', $over)) {
            $user->forceFill(['disabled_at' => $over['disabled_at']])->save();
        }

        return $user;
    }

    public function test_active_user_can_log_in(): void
    {
        $this->user();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'u@a.test', 'password' => 'secret1234'])
            ->assertOk();
    }

    public function test_disabled_user_cannot_log_in_and_gets_a_generic_message(): void
    {
        $this->user(['disabled_at' => now()]);
        $res = $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'u@a.test', 'password' => 'secret1234'])
            ->assertForbidden();
        // Non-revealing: does not say "suspended" vs "wrong password".
        $this->assertStringContainsString('not available', $res->json('message'));
    }

    public function test_disabled_user_cannot_mint_a_token(): void
    {
        $this->user(['disabled_at' => now()]);
        $this->postJson('/api/v1/auth/tokens', ['email' => 'u@a.test', 'password' => 'secret1234', 'device_name' => 'cli'])
            ->assertForbidden();
    }

    public function test_disabled_user_is_denied_on_every_api_request(): void
    {
        $user = $this->user(['disabled_at' => now()]);
        // Even with a valid auth context, the account-active middleware denies the request.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.blocked_suspended', 'entity_id' => (string) $user->id]);
    }

    public function test_suspended_workspace_blocks_its_members(): void
    {
        $user = $this->user();
        $this->tenant->forceFill(['status' => 'suspended'])->save();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'u@a.test', 'password' => 'secret1234'])->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_reactivation_restores_access(): void
    {
        $user = $this->user(['disabled_at' => now()]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertForbidden();

        // An authorized action clears disabled_at → access is restored.
        $user->forceFill(['disabled_at' => null])->save();
        $this->actingAs($user->refresh(), 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }
}
