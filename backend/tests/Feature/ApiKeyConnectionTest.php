<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * INTEG-APIKEY-001 — connecting a provider the advertiser holds their own key for.
 *
 * Every claim here is about a SECRET: where it ends up, where it must never appear, and what the
 * product is allowed to call «connected» after accepting one. None of these can be read off a screen,
 * which is why they are held in a test and not in a review note.
 */
final class ApiKeyConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ads-live-7f3c9a21b4e8d6f0';

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    /** The whole chain, proved by the provider rather than by the key being well-formed. */
    public function test_a_key_that_lists_an_account_opens_a_connection(): void
    {
        $this->providerReturnsAccount();

        $response = $this->connect()->assertCreated();

        $this->assertSame('openai_ads', $response->json('data.provider'));
        $this->assertSame(1, $response->json('data.accounts'));

        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'openai_ads')->sole();
        $this->assertSame('connected', $connection->status);

        $this->assertSame(
            'acct_live_1',
            ExternalAccount::withoutGlobalScopes()->where('provider', 'openai_ads')->sole()->external_id,
        );

        // The provider was actually called, with the key in an Authorization header and never in the
        // URL — the same rule `SecretNeverInLoggedUrlTest` holds for the OAuth providers.
        Http::assertSent(function ($request): bool {
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return $request->hasHeader('Authorization', 'Bearer '.self::KEY);
        });
    }

    /** **The claim that matters most.** The key goes in, and nothing hands it back. */
    public function test_the_key_never_comes_back_out(): void
    {
        $this->providerReturnsAccount();

        $response = $this->connect()->assertCreated();

        $this->assertStringNotContainsString(self::KEY, $response->getContent() ?: '');
        // Enough to recognise which key was pasted, useless to anybody who did not have it.
        $this->assertSame(mb_substr(self::KEY, -4), $response->json('data.key_hint'));

        // Nor onto the rows an operator or a support engineer can read.
        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'openai_ads')->sole();
        $this->assertStringNotContainsString(self::KEY, json_encode($connection->getAttributes(), JSON_THROW_ON_ERROR));

        $credential = IntegrationCredential::withoutGlobalScopes()->findOrFail($connection->credential_id);
        // The column that holds it is cast `encrypted`, so the stored bytes are not the key.
        $this->assertStringNotContainsString(self::KEY, (string) $credential->getRawOriginal('encrypted_payload'));
        // And it is still the key when the vault reads it back.
        $this->assertSame(self::KEY, app(TokenVault::class)->stored($connection)->accessToken);
    }

    /** Nor into the record of what happened, which outlives the connection. */
    public function test_the_audit_record_says_what_happened_and_not_what_was_pasted(): void
    {
        $this->providerReturnsAccount();
        $this->connect()->assertCreated();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'integration.connection.opened')->sole();

        $this->assertStringNotContainsString(self::KEY, json_encode($log->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertSame('api_key', $log->after['credential_type']);
        $this->assertSame(1, $log->after['ad_accounts']);
    }

    /**
     * **A key the provider accepts for an account we cannot read is not a connection.**
     *
     * The failure mode this refuses is a green light on a workspace that will never receive a
     * figure — and a stored secret backing it.
     */
    public function test_a_key_that_lists_nothing_stores_nothing(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response(['id' => '', 'object' => 'ad_account'])]);

        $this->connect()->assertStatus(422)->assertJsonPath('meta.status', 'rejected');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame(0, IntegrationCredential::withoutGlobalScopes()->count());
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
    }

    /** A refused key is refused the same way, and leaves nothing behind either. */
    public function test_a_refused_key_stores_nothing(): void
    {
        Http::fake(['api.ads.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->connect()->assertStatus(422);

        $this->assertSame(0, IntegrationCredential::withoutGlobalScopes()->count());
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    /**
     * The credential is stored as what it is.
     *
     * `expires_at` null is the load-bearing half: `TokenVault::fresh()` treats a null expiry as «not
     * expired», so nothing ever tries to refresh a key that has no authorisation server to refresh
     * against — which would fail every call for every customer at once.
     */
    public function test_the_credential_is_recorded_as_a_key_and_never_expires(): void
    {
        $this->providerReturnsAccount();
        $this->connect()->assertCreated();

        $credential = IntegrationCredential::withoutGlobalScopes()->sole();

        $this->assertSame('api_key', $credential->credential_type);
        $this->assertNull($credential->expires_at);
        $this->assertNull(ProviderConnection::withoutGlobalScopes()->sole()->token_expires_at);
        $this->assertNull(app(TokenVault::class)->stored(ProviderConnection::withoutGlobalScopes()->sole())->refreshToken);
    }

    /**
     * Rotating the key is the SAME connection.
     *
     * A second connection would discover the same ad account a second time, and `daily_metrics`
     * hangs off the account — so every figure that account reports would be counted twice across the
     * dashboard, the reports and the alerts, with nothing anywhere saying why.
     */
    public function test_rotating_the_key_re_credentials_the_same_connection(): void
    {
        $this->providerReturnsAccount();
        $this->connect()->assertCreated();

        $first = ProviderConnection::withoutGlobalScopes()->sole();

        $this->providerReturnsAccount();
        $this->connect('sk-ads-live-rotated-0000beef')->assertCreated();

        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame($first->id, ProviderConnection::withoutGlobalScopes()->sole()->id);
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(
            'sk-ads-live-rotated-0000beef',
            app(TokenVault::class)->stored(ProviderConnection::withoutGlobalScopes()->sole())->accessToken,
        );
    }

    /**
     * A provider with a consent screen may not be connected by pasting a key.
     *
     * A hand-pasted OAuth access token works until it expires and then fails with no refresh token
     * to recover from — a connection that breaks days later for a reason no screen can explain.
     */
    public function test_an_oauth_provider_refuses_a_pasted_key(): void
    {
        Http::fake();

        $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/integrations/meta/api-key/connect', ['api_key' => self::KEY])
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'wrong_auth_kind');

        $this->assertSame(0, IntegrationCredential::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    /** And a key is never accepted from somebody who may not connect integrations. */
    public function test_connecting_takes_the_connect_permission(): void
    {
        Http::fake();

        $member = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($member, $this->tenant);

        $this->actingAs($member, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/integrations/openai_ads/api-key/connect', ['api_key' => self::KEY])
            ->assertForbidden();

        $this->assertSame(0, IntegrationCredential::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    /** An empty box is refused before anything is called. */
    public function test_a_missing_key_is_refused_without_calling_the_provider(): void
    {
        Http::fake();

        $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/integrations/openai_ads/api-key/connect', ['api_key' => ''])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    private function providerReturnsAccount(): void
    {
        Http::fake([
            'api.ads.openai.com/*' => Http::response([
                'id' => 'acct_live_1',
                'object' => 'ad_account',
                'name' => 'Acme Riyadh',
                'currency' => 'SAR',
                'timezone' => 'Asia/Riyadh',
                'status' => 'ACTIVE',
            ]),
        ]);
    }

    private function connect(string $key = self::KEY): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/integrations/openai_ads/api-key/connect', ['api_key' => $key]);
    }
}
