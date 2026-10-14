<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\PendingStoreInstall;
use App\Domains\Commerce\Providers\SallaConnector;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
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

        /*
         * SALLA-SYNC-RUN-001 — «202, then nothing»: a store no project selects is refused with the
         * reason, and nothing is queued into a job that would return silently.
         */
        $this->actingAs($this->operator)
            ->postJson("/api/v1/commerce/stores/{$store->getKey()}/sync")
            ->assertStatus(409)
            ->assertJsonPath('meta.reason', 'awaiting_assignment');
        Queue::assertNothingPushed();

        $project = Project::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id, 'external_account_id' => $store->id,
            'provider' => 'salla', 'purpose' => 'ecommerce', 'is_active' => true,
        ]);

        $runId = $this->actingAs($this->operator)
            ->postJson("/api/v1/commerce/stores/{$store->getKey()}/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1)
            ->json('data.run_id');

        // A run row exists from the moment the job is queued — the card never reads «null».
        $this->assertSame('queued', IntegrationSyncRun::withoutGlobalScopes()->findOrFail($runId)->status);
        Queue::assertPushed(SyncStoreJob::class, 1);
    }

    /** SALLA-SYNC-RUN-001 — the queued row is the one the job moves: never a second row, never left «queued». */
    public function test_the_queued_run_is_closed_by_the_job_whatever_happens(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: 'Salla',
        );
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 's-2',
            'name' => 'متجر', 'currency' => 'SAR', 'status' => 'active',
        ]);
        $run = new IntegrationSyncRun;
        $run->forceFill(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'type' => 'commerce', 'status' => 'queued', 'started_at' => now()])->save();
        app(TenantContext::class)->forget();

        // Unassigned by the time it runs: the queued row says why.
        app()->call([new SyncStoreJob((string) $store->id, '2026-10-01', '2026-10-14', ['run_id' => (string) $run->id]), 'handle']);
        $this->assertSame('awaiting_assignment', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);

        // A job that exhausted its attempts closes its row as failed, with the reason.
        $run->refresh()->forceFill(['status' => 'queued', 'finished_at' => null])->save();
        (new SyncStoreJob((string) $store->id, '2026-10-01', '2026-10-14', ['run_id' => (string) $run->id]))->failed(new \RuntimeException('Salla answered 500'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('Salla answered 500', (string) $run->fresh()->error);
    }

    /** SALLA-SYNC-RUN-001 — a store Salla names with an empty string is named by its domain, never left blank. */
    public function test_a_store_salla_sends_without_a_name_is_named_by_its_domain(): void
    {
        $this->configure('salla');
        Http::fake(['*store/info*' => Http::response(['data' => ['id' => 793298857, 'name' => '', 'domain' => 'https://demostore.salla.sa/dev-x', 'currency' => 'SAR']])]);
        $connector = app(SallaConnector::class);

        $method = new \ReflectionMethod($connector, 'nameOf');
        $this->assertSame('demostore.salla.sa', $method->invoke(null, ['id' => 793298857, 'name' => '', 'domain' => 'https://demostore.salla.sa/dev-x']));
        $this->assertSame('Salla store 793298857', $method->invoke(null, ['id' => 793298857, 'name' => ' ']));
        $this->assertSame('متجري', $method->invoke(null, ['id' => 1, 'name' => 'متجري', 'domain' => 'https://x.test']));
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

    // ── SALLA-INSTALL-001 — installed from Salla's side, then claimed ───────────────────────────

    private function fakeSalla(): void
    {
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'AT-install', 'refresh_token' => 'RT-install', 'expires_in' => 1209600]),
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 793298857, 'name' => 'متجر سلة التجريبي', 'domain' => 'https://demostore.salla.sa/dev', 'currency' => 'SAR']]),
        ]);
    }

    /** A merchant who installs from the Salla App Store arrives with Salla's code and no state of ours. */
    public function test_a_merchant_initiated_install_is_kept_for_claiming_not_refused(): void
    {
        $this->configure('salla');
        $this->fakeSalla();

        $response = $this->get('/api/v1/oauth/commerce/salla/callback?code=from-salla');

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/app/store-install?', $location, 'a store-initiated install was refused as invalid state');
        $this->assertStringNotContainsString('invalid_state', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $claim = $query['claim'];

        // Nothing is connected until a workspace claims it; the tokens wait, encrypted.
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
        $pending = PendingStoreInstall::query()->firstOrFail();
        $this->assertSame('793298857', $pending->merchant_id);
        $this->assertSame('AT-install', $pending->tokens['access_token']);
        $this->assertStringNotContainsString('AT-install', (string) DB::table('pending_store_installs')->value('tokens'), 'tokens stored in clear');

        // The claim page reads the store's name without signing in — never the tokens.
        $this->getJson("/api/v1/commerce/store-installs/{$claim}")
            ->assertOk()
            ->assertJsonPath('data.store_name', 'متجر سلة التجريبي')
            ->assertJsonMissingPath('data.tokens');
        $this->getJson('/api/v1/commerce/store-installs/not-a-claim')->assertNotFound();

        // A signed-in user claims it into their workspace: now it is a connection with its store.
        $this->actingAs($this->operator)
            ->postJson("/api/v1/commerce/store-installs/{$claim}/claim")
            ->assertOk()
            ->assertJsonPath('data.stores', 1);

        $connection = ProviderConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->tenant->id, $connection->tenant_id);
        $this->assertSame('متجر سلة التجريبي', ExternalAccount::withoutGlobalScopes()->where('external_id', '793298857')->value('name'));
        $this->assertNotNull($pending->fresh()->claimed_at);
        $this->assertSame([], $pending->fresh()->tokens, 'the claimed install keeps no copy of the tokens');

        // One claim per code.
        $this->actingAs($this->operator)->postJson("/api/v1/commerce/store-installs/{$claim}/claim")->assertStatus(410);
    }

    /** The CampaignsHub-initiated path is unchanged: a forged state still connects nothing. */
    public function test_a_forged_state_is_still_refused_after_the_install_path_exists(): void
    {
        $this->configure('salla');
        $this->fakeSalla();

        $location = (string) $this->get('/api/v1/oauth/commerce/salla/callback?code=abc&state=forged')->headers->get('Location');

        $this->assertStringContainsString('invalid_state', $location);
        $this->assertSame(0, PendingStoreInstall::query()->count());
    }

    /** `app.store.authorize` renews a connected merchant's tokens; `app.uninstalled` stops the connection. */
    public function test_salla_app_webhooks_renew_and_withdraw_the_store(): void
    {
        $this->configure('salla');
        config()->set('commerce_platforms.platforms.salla.webhook_secret', 'whsec');
        $this->fakeSalla();

        $this->holdingTenant((string) $this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'salla',
            tokens: new OAuthTokens('AT-old', 'RT-old', Carbon::now()->addDay()), connectionName: 'Salla',
        );
        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => '793298857', 'name' => 'متجر', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $post = function (array $body): TestResponse {
            $raw = json_encode($body, JSON_THROW_ON_ERROR);

            return $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $raw, 'whsec'),
            ], $raw);
        };

        $post(['event' => 'app.store.authorize', 'merchant' => 793298857, 'created_at' => '2026-10-14 10:00:00',
            'data' => ['access_token' => 'AT-new', 'refresh_token' => 'RT-new', 'expires' => now()->addDays(14)->timestamp, 'scope' => 'offline_access']])->assertOk();
        $this->assertSame('AT-new', app(TokenVault::class)->stored($connection->fresh())->accessToken, 'a reinstall did not renew the tokens');
        $this->assertSame(0, PendingStoreInstall::query()->count(), 'a connected merchant is renewed in place, not queued as a new install');

        $post(['event' => 'app.uninstalled', 'merchant' => 793298857, 'created_at' => '2026-10-14 11:00:00', 'data' => []])->assertOk();
        $this->assertSame('revoked', $connection->fresh()->status);

        // An unsigned delivery changes nothing.
        $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'app.uninstalled', 'merchant' => 1]))->assertStatus(401);
    }

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
