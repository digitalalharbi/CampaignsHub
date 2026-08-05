<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-OAUTH-001 — the two halves of connecting an ad account, and the boundary between them.
 *
 * The callback is a PUBLIC route by necessity: a platform redirects a browser to it from an external
 * origin, and no session cookie or tenant header survives that. Everything interesting about this test
 * file is therefore about one question — **what does that public route trust?**
 *
 * The answer has to be "only a state we issued". If the tenant could be named in the query string,
 * anybody who can reach the URL could attach a live platform credential to somebody else's workspace.
 */
final class AdPlatformOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    // ── start ─────────────────────────────────────────────────────────────────────────────────

    /** The honest state of this install: no keys, so no authorise URL — and it says what is missing. */
    public function test_starting_an_unconfigured_platform_says_awaiting_credentials_and_what_is_missing(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/snapchat/oauth/start')
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'awaiting_credentials')
            ->assertJsonPath('errors.missing', ['client_id', 'client_secret', 'organization_id']);
    }

    public function test_a_configured_platform_issues_a_single_use_authorization_url(): void
    {
        $this->configure('meta');

        $data = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start')
            ->assertOk()
            ->json('data');

        $this->assertStringStartsWith('https://www.facebook.com/', $data['authorization_url']);

        parse_str((string) parse_url($data['authorization_url'], PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('state', $query);

        // The state resolves once, to THIS tenant, and never a second time.
        $claim = AuthorizationState::claim($query['state'], 'meta');
        $this->assertSame($this->tenant->id, $claim['tenant_id']);
        $this->assertNull(AuthorizationState::claim($query['state'], 'meta'));
    }

    public function test_connecting_requires_the_connect_permission(): void
    {
        $this->configure('meta');

        $bystander = User::create(['name' => 'B', 'email' => 'b@agency.test', 'password' => 'secret123']);
        $this->grantMembership($bystander, $this->tenant);

        $this->actingAs($bystander, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start')
            ->assertForbidden();
    }

    public function test_a_platform_this_product_does_not_carry_is_a_404(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/pinterest/oauth/start')
            ->assertNotFound();
    }

    // ── callback ──────────────────────────────────────────────────────────────────────────────

    public function test_the_whole_flow_opens_a_connection_and_discovers_real_accounts(): void
    {
        $this->configure('meta');
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'AT', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_1', 'name' => 'Main', 'currency' => 'SAR', 'timezone_name' => 'Asia/Riyadh', 'account_status' => 1],
                ['id' => 'act_2', 'name' => 'Second', 'currency' => 'SAR', 'timezone_name' => 'Asia/Riyadh', 'account_status' => 1],
            ]]),
        ]);

        $state = $this->startAndTakeState('meta');

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")
            ->assertRedirectContains('outcome=connected')
            ->assertRedirectContains('accounts=2');

        $connection = ProviderConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->tenant->id, $connection->tenant_id);
        $this->assertSame('connected', $connection->status);
        $this->assertSame(2, ExternalAccount::withoutGlobalScopes()->count());
    }

    /** Re-authorising updates the accounts already known rather than duplicating every one. */
    public function test_authorising_again_does_not_duplicate_the_discovered_accounts(): void
    {
        $this->configure('meta');
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'AT', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_1', 'name' => 'Renamed', 'currency' => 'SAR', 'account_status' => 1],
            ]]),
        ]);

        foreach ([1, 2] as $_) {
            $state = $this->startAndTakeState('meta');
            $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}");
        }

        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('external_id', 'act_1')->count());
        $this->assertSame('Renamed', ExternalAccount::withoutGlobalScopes()->firstOrFail()->name);
    }

    /**
     * The security claim, stated as a test: a callback we did not start opens nothing.
     *
     * No token is exchanged, so a fabricated code cannot even reach the platform — and no connection
     * appears against any tenant.
     */
    public function test_a_callback_with_a_state_we_never_issued_opens_nothing(): void
    {
        $this->configure('meta');
        Http::preventStrayRequests();
        Http::fake();

        $this->get('/api/v1/oauth/ads/meta/callback?code=abc&state=forged-state')
            ->assertRedirectContains('outcome=invalid_state');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    /** A state minted for one platform cannot be spent on another. */
    public function test_a_state_cannot_be_spent_on_a_different_platform(): void
    {
        $this->configure('meta');
        $this->configure('tiktok');
        Http::preventStrayRequests();
        Http::fake();

        $state = $this->startAndTakeState('meta');

        $this->get("/api/v1/oauth/ads/tiktok/callback?code=abc&state={$state}")
            ->assertRedirectContains('outcome=invalid_state');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    /** The same authorisation cannot be replayed out of a browser history. */
    public function test_a_state_is_spent_the_first_time_it_is_used(): void
    {
        $this->configure('meta');
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'AT', 'expires_in' => 100]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => []]),
        ]);

        $state = $this->startAndTakeState('meta');

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=connected');
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=invalid_state');

        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->count());
    }

    /** The customer pressing "cancel" is a normal outcome, and it says so rather than erroring. */
    public function test_a_refusal_by_the_customer_is_reported_as_denied(): void
    {
        $this->configure('meta');

        $this->get('/api/v1/oauth/ads/meta/callback?error=access_denied&error_description=The+user+said+no&state=x')
            ->assertRedirectContains('outcome=denied');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    /**
     * A token that exchanges and then cannot list an account is NOT a connection.
     *
     * This is the case that would otherwise put a green light on a workspace which will never receive
     * a single figure: the app is misconfigured, not linked.
     */
    public function test_a_token_that_cannot_list_an_account_does_not_become_a_connected_state(): void
    {
        $this->configure('meta');
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'AT', 'expires_in' => 100]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['error' => ['message' => 'Insufficient permission']], 403),
        ]);

        $this->get('/api/v1/oauth/ads/meta/callback?code=abc&state='.$this->startAndTakeState('meta'))
            ->assertRedirectContains('outcome=failed');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    /** Run the authenticated half and return the state it minted. */
    private function startAndTakeState(string $platform): string
    {
        $url = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/integrations/{$platform}/oauth/start")
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }
}
