<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * COMMERCE-001 — the merchant-facing half of a store integration.
 *
 * The architectural rule under test is the one the owner made binding: `/admin` holds the system's
 * provider keys, `/app` and `/agency` hold nothing but the merchant's own consent, and the second must
 * never be able to see or enter the first. So most of what follows is a REFUSAL, and the refusals are
 * checked for what they DO NOT say as much as for their status code.
 */
final class CommerceOAuthAndBoardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Merch', 'slug' => 'merch-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@merch.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    // ── Starting the flow ─────────────────────────────────────────────────────────────────────

    /**
     * An unconfigured provider refuses, and the refusal names no system credential.
     *
     * `missing: ['client_secret']` would be an instruction for the console at `/admin` addressed to a
     * merchant who cannot act on it — and it describes the shape of our provider registration to
     * somebody who has no business knowing it.
     */
    public function test_starting_an_unconfigured_store_flow_is_refused_without_naming_a_system_key(): void
    {
        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/integrations/commerce/salla/oauth/start')
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'awaiting_credentials');

        $body = $response->getContent();
        $this->assertStringNotContainsString('client_secret', $body);
        $this->assertStringNotContainsString('webhook_secret', $body);
    }

    public function test_a_configured_provider_issues_an_authorisation_url_carrying_a_single_use_state(): void
    {
        $this->configure('salla');

        $response = $this->actingAs($this->operator)
            ->postJson('/api/v1/integrations/commerce/salla/oauth/start')
            ->assertOk();

        $url = (string) $response->json('data.authorization_url');

        $this->assertStringStartsWith('https://accounts.salla.sa/oauth2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('test-client_id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertNotEmpty($query['state']);
        // The redirect the operator pastes into Salla's console, built by the SAME method the setup
        // page shows them — one construction, so it cannot drift into a mismatch.
        $this->assertStringEndsWith('/api/v1/oauth/commerce/salla/callback', $query['redirect_uri']);
        // The secret is used to sign the exchange, never to build a URL a browser will carry.
        $this->assertStringNotContainsString('test-client_secret', $url);
    }

    /** A provider the platform operator suspended is refused at the START, not merely hidden. */
    public function test_a_disabled_provider_cannot_be_connected_even_by_replaying_the_request(): void
    {
        $this->configure('salla');
        app(ProviderConfigurationService::class)->setEnabled('salla', false);

        $this->actingAs($this->operator)
            ->postJson('/api/v1/integrations/commerce/salla/oauth/start')
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'disabled');
    }

    /** An advertising provider is not reachable through the commerce flow, and vice versa. */
    public function test_the_two_families_do_not_share_an_oauth_route(): void
    {
        $this->actingAs($this->operator)
            ->postJson('/api/v1/integrations/commerce/meta/oauth/start')
            ->assertStatus(404);

        $this->get('/api/v1/oauth/commerce/meta/callback')->assertStatus(404);
    }

    // ── The callback, which is public and trusts only its state ───────────────────────────────

    public function test_a_callback_with_a_state_we_never_issued_connects_nothing(): void
    {
        $this->configure('salla');

        $this->get('/api/v1/oauth/commerce/salla/callback?code=abc&state=forged')
            ->assertRedirect();

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count());
    }

    /**
     * The full flow: a real state, a real exchange, and a store listing that earns the word connected.
     */
    public function test_a_genuine_callback_opens_a_connection_and_discovers_the_merchants_store(): void
    {
        $this->configure('salla');

        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response([
                'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 1209600,
            ]),
            'api.salla.dev/*/store/info' => Http::response(['data' => [
                'id' => 'store-9', 'name' => 'متجر تجريبي', 'domain' => 'demo.salla.sa', 'currency' => 'SAR',
            ]]),
        ]);

        $state = $this->issueState('salla');

        $this->get("/api/v1/oauth/commerce/salla/callback?code=real-code&state={$state}")
            ->assertRedirect();

        $connection = ProviderConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('salla', $connection->provider);
        $this->assertSame($this->tenant->id, $connection->tenant_id);

        $store = ExternalAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('store', $store->account_type);
        $this->assertSame('store-9', $store->external_id);
        $this->assertSame('متجر تجريبي', $store->name);
        // Discovered is not synced, and the column that says «when did data last arrive» stays null.
        $this->assertNull($store->last_synced_at);
    }

    /**
     * A token exchange that succeeds and then lists no store is NOT a connection.
     *
     * It is a misconfigured app or a scope set that reads nothing, and calling it connected puts a
     * green light on a workspace that will never receive an order.
     */
    public function test_zid_refuses_a_token_that_arrives_without_the_manager_token_its_api_also_needs(): void
    {
        $this->configure('zid');

        Http::fake([
            'oauth.zid.sa/oauth/token' => Http::response(['access_token' => 'AT', 'expires_in' => 3600]),
        ]);

        $state = $this->issueState('zid');

        $this->get("/api/v1/oauth/commerce/zid/callback?code=real-code&state={$state}")
            ->assertRedirect();

        // Nothing was opened: an access token without the manager token is a connection that would
        // exchange perfectly and fail on its first read.
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    // ── The merchant's board ──────────────────────────────────────────────────────────────────

    public function test_the_board_reports_awaiting_credentials_without_naming_any_key(): void
    {
        $response = $this->actingAs($this->operator)
            ->getJson('/api/v1/commerce/stores')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'salla')
            ->assertJsonPath('data.0.state', 'awaiting_credentials')
            ->assertJsonPath('data.1.key', 'zid');

        $this->assertStringNotContainsString('client_secret', $response->getContent());
        // Zid's inability to report abandoned carts is a stated capability, never a zero.
        $this->assertFalse($response->json('data.1.supports_abandoned_carts'));
        $this->assertTrue($response->json('data.0.supports_abandoned_carts'));
    }

    public function test_a_connected_store_is_listed_with_its_counts_and_can_be_synced_on_demand(): void
    {
        Queue::fake();
        $this->configure('salla');

        $this->holdingTenant((string) $this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: 'Salla',
        );
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 's-1',
            'name' => 'متجر', 'currency' => 'SAR', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($this->operator)
            ->getJson('/api/v1/commerce/stores')
            ->assertOk()
            ->assertJsonPath('data.0.state', 'connected')
            ->assertJsonPath('data.0.stores.0.name', 'متجر')
            ->assertJsonPath('data.0.stores.0.counts.orders', 0);

        $this->actingAs($this->operator)
            ->postJson("/api/v1/commerce/stores/{$store->getKey()}/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        Queue::assertPushed(SyncStoreJob::class, 1);
    }

    /** A revoked authorisation is refused rather than queued into a guaranteed failure row. */
    public function test_syncing_a_store_behind_a_revoked_connection_is_refused(): void
    {
        Queue::fake();

        $this->holdingTenant((string) $this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: 'Salla',
        );
        $connection->forceFill(['status' => 'revoked'])->save();

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 's-1',
            'name' => 'متجر', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($this->operator)
            ->postJson("/api/v1/commerce/stores/{$store->getKey()}/sync")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** Another tenant's store is a 404, not a 403 — the board fails closed like everything else. */
    public function test_another_tenants_store_cannot_be_synced(): void
    {
        Queue::fake();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $other->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $other->id, provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: 'Salla',
        );
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 's-x',
            'name' => 'Theirs', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($this->operator)
            ->postJson("/api/v1/commerce/stores/{$store->getKey()}/sync")
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("commerce_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    /** Start the flow for real, so the callback is tested against a state we genuinely issued. */
    private function issueState(string $provider): string
    {
        $response = $this->actingAs($this->operator)
            ->postJson("/api/v1/integrations/commerce/{$provider}/oauth/start")
            ->assertOk();

        parse_str((string) parse_url((string) $response->json('data.authorization_url'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }
}
