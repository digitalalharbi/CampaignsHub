<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GA4-INTEGRATION-001 — the measurement family's own two halves, and the fence between the families.
 *
 * The callback is public by necessity: Google redirects a BROWSER to it from its own origin, and no
 * session cookie or tenant header survives that hop. So the interesting question here is the same one
 * the advertising flow answers — **what does that public route trust?** — plus one this family adds:
 * a measurement consent must never be able to land on an advertising handler, or the other way round,
 * because the two discover different things and apply different rules afterwards.
 */
final class MeasurementConnectionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Project $project;

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

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── start ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The honest state of this install: no OAuth client registered, so no authorise URL.
     *
     * And it does not say WHICH key is absent. «ينقص: client_secret» is an instruction for the
     * console at `/admin`, addressed to the wrong reader in a tenant's error.
     */
    public function test_starting_an_unconfigured_property_flow_says_awaiting_credentials_without_naming_a_secret(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/measurement/ga4/oauth/start')
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'awaiting_credentials');

        $this->assertStringNotContainsString('client_secret', $response->getContent());
    }

    /**
     * The authorise URL asks for the refresh token, and for read access only.
     *
     * Both halves matter. Google issues a refresh token ONLY when `access_type=offline` and
     * `prompt=consent` are both present, and only on the first consent — omit them and the connection
     * works for one hour and then goes dark with nothing to refresh from. And the scope is the single
     * read-only one: anything wider puts a consent screen in front of a customer claiming this product
     * may change their Analytics configuration.
     */
    public function test_the_authorise_url_asks_for_offline_read_only_access(): void
    {
        $this->configure();

        $data = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/measurement/ga4/oauth/start')
            ->assertOk()
            ->json('data');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $data['authorization_url']);

        parse_str((string) parse_url($data['authorization_url'], PHP_URL_QUERY), $query);

        $this->assertSame('offline', $query['access_type'] ?? null, 'no refresh token would ever be issued');
        $this->assertSame('consent', $query['prompt'] ?? null, 'no refresh token would ever be issued');
        $this->assertSame('https://www.googleapis.com/auth/analytics.readonly', $query['scope']);
        $this->assertStringEndsWith('/api/v1/oauth/measurement/ga4/callback', $query['redirect_uri']);

        // The state resolves once, to THIS tenant, and never a second time.
        $claim = AuthorizationState::claim($query['state'], 'ga4');
        $this->assertSame($this->tenant->id, $claim['tenant_id']);
        $this->assertNull(AuthorizationState::claim($query['state'], 'ga4'));
    }

    public function test_connecting_requires_the_connect_permission(): void
    {
        $this->configure();

        $bystander = User::create(['name' => 'B', 'email' => 'b@agency.test', 'password' => 'secret123']);
        $this->grantMembership($bystander, $this->tenant);

        $this->actingAs($bystander, 'sanctum')
            ->postJson('/api/v1/integrations/measurement/ga4/oauth/start')
            ->assertForbidden();
    }

    /**
     * The fence between the families, from both sides.
     *
     * An advertising provider is not reachable through the measurement flow, and GA4 is not reachable
     * through the advertising one. Without this, a measurement consent could land on the handler that
     * discovers AD ACCOUNTS — and GA4 would be filed as a platform somebody buys advertising on,
     * which is the specific thing the third `ProviderKind` exists to prevent.
     */
    public function test_the_two_families_do_not_share_an_oauth_route(): void
    {
        $this->configure();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/measurement/meta/oauth/start')
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/ga4/oauth/start')
            ->assertNotFound();

        $this->get('/api/v1/oauth/ads/ga4/callback?state=x')->assertNotFound();
    }

    // ── callback ──────────────────────────────────────────────────────────────────────────────

    /** The whole flow: consent, token, and the property listing that earns the word «connected». */
    public function test_the_whole_flow_opens_a_connection_and_discovers_properties_without_selecting_any(): void
    {
        $this->configure();
        $state = $this->startAndTakeState();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]),
            '*analyticsadmin.googleapis.com*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/100', 'displayName' => 'Acme Group',
                    'propertySummaries' => [
                        ['property' => 'properties/111', 'displayName' => 'Acme Store'],
                        ['property' => 'properties/222', 'displayName' => 'Other Client Store'],
                    ],
                ]],
            ], 200),
        ]);

        $this->get("/api/v1/oauth/measurement/ga4/callback?code=C&state={$state}")
            ->assertRedirect()
            ->assertRedirectContains('outcome=connected')
            ->assertRedirectContains('properties=2');

        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'ga4')->firstOrFail();
        $this->assertSame('connected', $connection->status);

        $this->assertSame(2, ExternalAccount::withoutGlobalScopes()
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->count());

        // …and NOTHING was selected. Two clients' properties behind one agency identity.
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    /** A state we never issued connects nothing — the branch an attacker sees. */
    public function test_a_state_we_never_issued_connects_nothing(): void
    {
        $this->configure();

        $this->get('/api/v1/oauth/measurement/ga4/callback?code=C&state=forged')
            ->assertRedirect()
            ->assertRedirectContains('outcome=invalid_state');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    // ── the properties the operator chooses from ───────────────────────────────────────────────

    /**
     * The list names every discovered property and says which are selected — rather than hiding the
     * rest, which would make a property nobody chose look like one nobody found.
     */
    public function test_the_property_list_states_selection_rather_than_filtering_it(): void
    {
        $connection = $this->connection();
        $chosen = $this->property($connection, '111', 'Acme Store', selected: true);
        $this->property($connection, '222', 'Other Client Store', selected: false);

        $rows = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/measurement/properties')
            ->assertOk()
            ->json('data.properties');

        $this->assertCount(2, $rows);

        $byId = collect($rows)->keyBy('property_id');
        $this->assertTrue($byId['111']['is_selected']);
        $this->assertSame($this->project->id, $byId['111']['project_id']);
        $this->assertFalse($byId['222']['is_selected']);
        $this->assertNull($byId['222']['project_id']);

        // The GA4 hierarchy is readable: «Acme Group → Acme Store».
        $this->assertSame('Acme Group', $byId['111']['analytics_account_name']);

        /*
         * A never-synced property honestly says it does not know its own settings. Discovery does not
         * ask the Admin API for them — a guessed `UTC` here would shift a Gulf client's whole report
         * by a day, and a guessed currency would label their revenue in the wrong money.
         */
        $this->assertNull($byId['222']['timezone']);
        $this->assertSame((string) $chosen->getKey(), $byId['111']['id']);
    }

    /** Syncing a property nobody selected is refused, and reads nothing. */
    public function test_syncing_an_unselected_property_is_refused(): void
    {
        $connection = $this->connection();
        $property = $this->property($connection, '222', 'Other Client Store', selected: false);

        Http::fake();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/measurement/properties/{$property->getKey()}/sync")
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, MeasurementDailyMetric::withoutGlobalScopes()->count());
    }

    /** Another tenant's property is a 404, not a 403 — this tenant has no business knowing it exists. */
    public function test_another_tenants_property_cannot_be_synced(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $foreign = $this->property($this->connection($other->id), '999', 'Theirs', selected: false, tenantId: $other->id);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/measurement/properties/{$foreign->getKey()}/sync")
            ->assertNotFound();
    }

    /** A selected property syncs on demand, and reports what it actually wrote. */
    public function test_a_selected_property_syncs_on_demand(): void
    {
        $connection = $this->connection();
        $property = $this->property($connection, '111', 'Acme Store', selected: true);

        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(
                ['displayName' => 'Acme Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR'], 200,
            ),
            '*analyticsdata.googleapis.com*' => fn (): PromiseInterface => Http::response([
                'metricHeaders' => [['name' => 'sessions'], ['name' => 'purchaseRevenue']],
                'rows' => [[
                    'dimensionValues' => [['value' => '20261007']],
                    'metricValues' => [['value' => '4100'], ['value' => '8900.5']],
                ]],
            ], 200),
        ]);

        $data = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/measurement/properties/{$property->getKey()}/sync", ['days' => 2])
            ->assertOk()
            ->json('data');

        $this->assertSame('Asia/Riyadh', $data['timezone']);
        $this->assertSame(1, $data['days']);
        $this->assertSame(2, $data['figures']);
        $this->assertSame(2, MeasurementDailyMetric::withoutGlobalScopes()->count());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function configure(): void
    {
        config()->set('measurement_platforms.platforms.ga4.client_id', 'test-client-id');
        config()->set('measurement_platforms.platforms.ga4.client_secret', 'test-client-secret');
    }

    private function startAndTakeState(): string
    {
        $url = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/measurement/ga4/oauth/start')
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * Opened through the vault rather than inserted, so the row carries a real stored credential.
     *
     * A hand-built `ProviderConnection` has no `credential_id` — the column is NOT NULL — and even if
     * it were nullable, a connection without a token is a state this product does not have: every
     * read through it would fail at the vault instead of at the fixture.
     */
    private function connection(?string $tenantId = null): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $tenantId ?? $this->tenant->id,
            provider: 'ga4',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'Google Analytics 4',
        );
    }

    private function property(
        ProviderConnection $connection,
        string $externalId,
        string $name,
        bool $selected,
        ?string $tenantId = null,
    ): ExternalAccount {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'ga4',
            'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => $externalId,
            'name' => $name,
            'parent_external_id' => '100',
            'parent_name' => 'Acme Group',
            'status' => 'active',
            'discovered_at' => now(),
        ]);

        if ($selected) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId ?? $this->tenant->id,
                'client_workspace_id' => $this->project->client_workspace_id,
                'project_id' => $this->project->id,
                'external_account_id' => $account->id,
                'provider' => 'ga4',
                'purpose' => 'analytics',
                'is_active' => true,
            ]);
        }

        return $account;
    }
}
