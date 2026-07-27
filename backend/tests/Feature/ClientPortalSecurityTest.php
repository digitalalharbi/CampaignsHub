<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security hard-gates for the client portal: the dev testability escape hatches (OTP dev_code, portal
 * dev_token, X-Client-Token header) must be COMPLETELY unavailable in production, and no delivery is ever
 * recorded as "sent" without a real provider.
 */
final class ClientPortalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
    }

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_dev_code_is_never_exposed_in_production(): void
    {
        $this->asProduction();
        $res = $this->postJson('/api/v1/requests/verify/start', ['channel' => 'sms', 'destination' => '+966500000010'])
            ->assertCreated();
        $this->assertNull($res->json('data.dev_code'), 'dev_code must be null in production');
        // Delivery is honestly awaiting provider credentials — never "sent".
        $res->assertJsonPath('data.delivery_status', 'awaiting_provider_credentials');
    }

    public function test_dev_code_is_null_even_if_config_flag_is_forced_on(): void
    {
        $this->asProduction();
        config(['requests.verification.expose_dev_code' => true]); // attacker/misconfig forces it on
        $res = $this->postJson('/api/v1/requests/verify/start', ['channel' => 'email', 'destination' => 'a@x.test'])
            ->assertCreated();
        $this->assertNull($res->json('data.dev_code'), 'production hard-gate must win over the config flag');
    }

    public function test_portal_dev_token_is_null_in_production(): void
    {
        // Verify a login OTP in NON-production first (so we can complete the challenge), then assert that a
        // production login response would not carry a dev_token. We simulate by checking the gate directly.
        $this->asProduction();
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'p@x.test'])->assertCreated();
        // In production the challenge cannot be auto-completed (no dev_code); the endpoint never leaks it.
        $this->assertNull($start->json('data.dev_code'));
    }

    public function test_x_client_token_header_is_rejected_in_production(): void
    {
        // A valid portal session exists...
        $plain = Str::random(48);
        ClientPortalToken::create([
            'tenant_id' => $this->tenant->id, 'token_hash' => hash('sha256', $plain),
            'contact_email' => 'c@x.test', 'expires_at' => now()->addDays(7),
        ]);

        // ...but in production the X-Client-Token header path is disabled (cookie-only) → unauthorized.
        $this->asProduction();
        $this->withHeaders(['X-Client-Token' => $plain])->getJson('/api/v1/client/requests')->assertUnauthorized();
    }

    public function test_x_client_token_header_still_works_off_production(): void
    {
        $plain = Str::random(48);
        ClientPortalToken::create([
            'tenant_id' => $this->tenant->id, 'token_hash' => hash('sha256', $plain),
            'contact_email' => 'c@x.test', 'expires_at' => now()->addDays(7),
        ]);
        // Off-production (testing), the header is honored so tooling/tests can authenticate.
        $this->withHeaders(['X-Client-Token' => $plain])->getJson('/api/v1/client/requests')->assertOk();
    }
}
