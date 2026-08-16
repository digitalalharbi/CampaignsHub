<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
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
 * OAUTH-WS-001 — the client workspace an OAuth flow is started FOR was never checked to be ours.
 *
 * ## The defect
 *
 * Both OAuth entry points — `AdPlatformOAuthController::start` for the six advertising platforms and
 * `StoreOAuthController::start` for Salla and Zid — validated the caller's `client_workspace_id` as:
 *
 * ```php
 * 'client_workspace_id' => ['sometimes', 'nullable', 'uuid']
 * ```
 *
 * That is a check on the SHAPE of a string and on nothing else. The value was then written into the
 * single-use authorisation state, and on the callback it was stamped onto the `ProviderConnection`
 * and onto every `ExternalAccount` discovery found — none of which ever asked whether that workspace
 * belongs to the tenant doing the connecting, or indeed whether it exists.
 *
 * So an authenticated operator of tenant A, holding `integrations.connect` in their own tenant, could
 * post the uuid of tenant B's client workspace and have a REAL, live platform credential — their own
 * TikTok or Salla token, with their own advertiser and store data behind it — filed under a client
 * belonging to somebody else's company. `client_workspace_id` is what the agency surfaces, the client
 * portals and the per-client report scopes read; a connection carrying a foreign one is a cross-tenant
 * write performed through the front door, by a user who never touched an id they were not shown.
 *
 * The models could not save this. `ClientWorkspace` is `BelongsToTenant`, so the global scope hides
 * tenant B's row from tenant A — but nothing here ever loads the model. It moves a bare string from a
 * request body to a database column, and a global scope cannot defend a column nobody queries through.
 *
 * ## Why the fix is the repository's own idiom, not a new invention
 *
 * `TaskController`, `ProjectController` and `AICredentialController` already validate exactly this
 * field exactly this way:
 *
 * ```php
 * Rule::exists('client_workspaces', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())
 * ```
 *
 * The two OAuth controllers are the ones that did not. The rule is additionally narrowed to
 * `deleted_at is null`, because `client_workspaces` is soft-deleted: without it, a bare
 * `Rule::exists` happily matches a client the agency has already removed, and the flow files a live
 * credential against a workspace no surface will ever show again.
 *
 * ## What is deliberately NOT changed here
 *
 * This adds a TENANT ownership check, which is the defect that is provable. It does not add a
 * per-user client-workspace membership requirement — no controller in this project has one, an agency
 * owner is expected to connect on behalf of any of their own clients, and inventing that rule inside a
 * security fix would break a legitimate flow while pretending to be the same change.
 */
final class OAuthClientWorkspaceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $ours;

    private Tenant $theirs;

    private User $operator;

    private ClientWorkspace $ourClient;

    private ClientWorkspace $theirClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->ours = Tenant::create(['name' => 'Ours', 'slug' => 'ours-'.uniqid(), 'status' => 'active']);
        $this->theirs = Tenant::create(['name' => 'Theirs', 'slug' => 'theirs-'.uniqid(), 'status' => 'active']);

        // Their client workspace, created while their tenant is the active one.
        app(TenantContext::class)->setTenantId($this->theirs->id);
        $this->theirClient = ClientWorkspace::create(['name' => 'Their Client', 'slug' => 'tc-'.uniqid(), 'mode' => 'managed']);

        // Ours, and an operator who legitimately holds every permission — inside their own tenant.
        app(TenantContext::class)->setTenantId($this->ours->id);
        $this->ourClient = ClientWorkspace::create(['name' => 'Our Client', 'slug' => 'oc-'.uniqid(), 'mode' => 'managed']);

        $role = Role::create(['tenant_id' => $this->ours->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@ours.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->ours);
        $this->operator->assignRole($role);
    }

    // ── advertising: the six platforms ────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** Under the old rule this returned 200 with an authorisation URL, and the
     * state it minted carried another company's client workspace.
     */
    public function test_an_ad_platform_flow_cannot_be_started_for_another_tenants_client(): void
    {
        $this->configure('tiktok');
        Http::preventStrayRequests();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start', [
                'client_workspace_id' => $this->theirClient->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_workspace_id');
    }

    /**
     * A uuid that names no workspace at all is refused for the same reason, and by the same rule.
     *
     * Worth its own case: «it belongs to somebody else» and «it does not exist» took the same path
     * before, which is what made the hole easy to miss — neither one was ever looked up.
     */
    public function test_an_ad_platform_flow_cannot_be_started_for_a_workspace_that_does_not_exist(): void
    {
        $this->configure('tiktok');
        Http::preventStrayRequests();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start', [
                'client_workspace_id' => '00000000-0000-4000-8000-000000000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_workspace_id');
    }

    /**
     * A client the agency has already removed is not a place to file a live credential.
     *
     * `client_workspaces` is soft-deleted, so a plain `Rule::exists` matches the row for as long as it
     * sits in the table. The connection would open, the tokens would be stored, and the workspace it
     * named would appear on no surface — a live platform credential with nowhere to be seen.
     */
    public function test_an_ad_platform_flow_cannot_be_started_for_a_deleted_client_workspace(): void
    {
        $this->configure('tiktok');
        Http::preventStrayRequests();

        $this->ourClient->delete();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start', [
                'client_workspace_id' => $this->ourClient->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_workspace_id');
    }

    /**
     * The legitimate flow is untouched: our own client is accepted, and it is carried all the way
     * through the state, the connection and every discovered account.
     *
     * This is the half that makes the fix a fix rather than a refusal — the feature still works, and
     * the workspace it lands on is the one the operator actually chose.
     */
    public function test_our_own_client_workspace_is_accepted_and_carried_through_to_the_discovered_accounts(): void
    {
        $this->configure('tiktok');
        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/access_token*' => Http::response([
                'code' => 0,
                'data' => ['access_token' => 'AT'],
            ]),
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => Http::response([
                'code' => 0,
                'data' => ['list' => [
                    ['advertiser_id' => '777', 'advertiser_name' => 'Our Client Ads', 'currency' => 'SAR'],
                ]],
            ]),
        ]);

        $url = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start', [
                'client_workspace_id' => $this->ourClient->id,
            ])
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        // A TikTok redirect carries `auth_code` as well as `code`, and only the first is exchangeable
        // (TIKTOK-AUTH-001). Driven here with the shape TikTok actually sends.
        $this->get('/api/v1/oauth/ads/tiktok/callback?'.http_build_query([
            'code' => 'not-the-exchangeable-one',
            'auth_code' => 'abc',
            'state' => $query['state'],
        ]))->assertRedirectContains('outcome=connected');

        $connection = ProviderConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->ours->id, $connection->tenant_id);
        $this->assertSame($this->ourClient->id, $connection->client_workspace_id);

        $account = ExternalAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->ourClient->id, $account->client_workspace_id);
        $this->assertSame('777', $account->external_id);
    }

    /**
     * Refused BEFORE a state exists — not merely refused.
     *
     * A state minted and then rejected downstream would still be a claimable record naming a foreign
     * workspace, sitting in the cache for its whole TTL. The gate has to be in front of `issue()`.
     */
    public function test_the_refusal_happens_before_any_state_is_minted(): void
    {
        $this->configure('tiktok');
        Http::preventStrayRequests();

        $response = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start', [
                'client_workspace_id' => $this->theirClient->id,
            ])
            ->assertStatus(422);

        // Nothing that could be spent came back, and no request ever left this server.
        $this->assertNull($response->json('data.authorization_url'));
        Http::assertNothingSent();
    }

    // ── commerce: Salla and Zid ───────────────────────────────────────────────────────────────

    /**
     * The identical defect lived in the identical line of `StoreOAuthController`.
     *
     * Fixed together because it is one rule in two places, and a store connection carries MORE of a
     * client's data than an ad account does — orders, customers and revenue.
     */
    public function test_a_commerce_flow_cannot_be_started_for_another_tenants_client(): void
    {
        $this->configure('salla');
        Http::preventStrayRequests();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/commerce/salla/oauth/start', [
                'client_workspace_id' => $this->theirClient->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_workspace_id');
    }

    public function test_a_commerce_flow_accepts_our_own_client_workspace(): void
    {
        $this->configure('salla');
        Http::preventStrayRequests();

        $url = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/commerce/salla/oauth/start', [
                'client_workspace_id' => $this->ourClient->id,
            ])
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $claim = AuthorizationState::claim((string) $query['state'], 'salla');
        $this->assertSame($this->ours->id, $claim['tenant_id']);
        $this->assertSame($this->ourClient->id, $claim['client_workspace_id']);
    }

    /** Omitting the field entirely stays legal — a tenant-level connection belongs to no one client. */
    public function test_omitting_the_client_workspace_is_still_a_tenant_level_connection(): void
    {
        $this->configure('tiktok');
        Http::preventStrayRequests();

        $url = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start')
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $claim = AuthorizationState::claim((string) $query['state'], 'tiktok');
        $this->assertSame($this->ours->id, $claim['tenant_id']);
        $this->assertNull($claim['client_workspace_id']);
    }

    /** Commerce providers read `commerce_platforms.php`; the advertising six read `ad_platforms.php`. */
    private function configure(string $platform): void
    {
        $root = in_array($platform, ['salla', 'zid'], true) ? 'commerce_platforms' : 'ad_platforms';

        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("{$root}.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }
}
