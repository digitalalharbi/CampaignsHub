<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RUNTIME-100 §5 §42 — filling in what discovery could not record at the time, without a second consent.
 *
 * ## The live state this is written from
 *
 * The first real Snapchat authorisation catalogued **309 ad accounts**. It ran before the product
 * recorded `parent_name` at all, so every one of those rows carries an organisation ID and no
 * organisation name — and the wizard's parent step, which falls back to the id when the name is
 * missing, shows a column of raw UUIDs where an agency expects to read «Acme Media».
 *
 * The authorisation is fine. The token is fine. The only thing missing is a column we did not have
 * when we asked, and the product's sole offer for that was to send the customer back through OAuth.
 * Re-consenting to repair our own omission is not a fix, it is a bill.
 *
 * ## What a refresh must not do
 *
 * External ids must not move — they are what every campaign, metric and binding is keyed on.
 * Nothing may be duplicated. Nothing may be deleted, including an account that has stopped coming
 * back: it is marked and kept, because it may still be feeding a project a year of history, and
 * because one bad response from a provider must not be able to erase a customer's inventory.
 */
final class DiscoveryRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ProviderConnection $connection;

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

        $this->connection = $this->connection('snapchat');

        app(TenantContext::class)->forget();
    }

    /**
     * **The defect, pinned.** Accounts catalogued before `parent_name` existed get their names, and
     * keep their identity.
     */
    public function test_a_refresh_fills_in_missing_parent_names_without_touching_identity(): void
    {
        // The live shape: a parent id, and no parent name, because the column did not exist yet.
        $a = $this->discoveredWithoutName('act-1', 'org-1');
        $b = $this->discoveredWithoutName('act-2', 'org-1');

        $this->providerReturns([
            $this->organisation('org-1', 'Acme Media', [
                ['id' => 'act-1', 'name' => 'Riyadh Retail'],
                ['id' => 'act-2', 'name' => 'Jeddah Retail'],
            ]),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertOk()
            ->assertJsonPath('data.discovered', 2)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.named', 2);

        $this->assertSame('Acme Media', $a->fresh()->parent_name);
        $this->assertSame('Acme Media', $b->fresh()->parent_name);
        $this->assertSame('Riyadh Retail', $a->fresh()->name);

        // Identity is untouched — everything downstream is keyed on these.
        $this->assertSame('act-1', $a->fresh()->external_id);
        $this->assertSame('org-1', $a->fresh()->parent_external_id);
        $this->assertSame(2, ExternalAccount::withoutGlobalScopes()->count(), 'no duplicates');
    }

    /** A binding survives a refresh: the account it names is updated in place, not replaced. */
    public function test_a_refresh_leaves_project_assignments_alone(): void
    {
        $account = $this->discoveredWithoutName('act-1', 'org-1');
        $binding = $this->bindTo($account);

        $this->providerReturns([
            $this->organisation('org-1', 'Acme Media', [['id' => 'act-1', 'name' => 'Renamed']]),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")->assertOk();

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame(
            (string) $account->id,
            (string) ProjectIntegrationBinding::withoutGlobalScopes()->findOrFail($binding->id)->external_account_id,
        );
    }

    /** An account the provider no longer returns is MARKED, never deleted. */
    public function test_an_account_that_stopped_coming_back_is_marked_and_kept(): void
    {
        $kept = $this->discoveredWithoutName('act-1', 'org-1');
        $gone = $this->discoveredWithoutName('act-2', 'org-1');

        $this->providerReturns([
            $this->organisation('org-1', 'Acme Media', [['id' => 'act-1', 'name' => 'Still here']]),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertOk()
            ->assertJsonPath('data.access_lost', 1);

        $this->assertNotNull($gone->fresh(), 'RUNTIME-100 §32: history must survive a provider that went quiet');
        $this->assertNotNull($gone->fresh()->access_lost_at);
        $this->assertNull($kept->fresh()->access_lost_at);
    }

    /** And an account that comes back is no longer lost. */
    public function test_an_account_that_returns_is_no_longer_marked_lost(): void
    {
        $account = $this->discoveredWithoutName('act-1', 'org-1');
        $account->forceFill(['access_lost_at' => now()->subDay()])->save();

        $this->providerReturns([
            $this->organisation('org-1', 'Acme Media', [['id' => 'act-1', 'name' => 'Back']]),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")->assertOk();

        $this->assertNull($account->fresh()->access_lost_at);
    }

    /** New accounts the authorisation has gained since are catalogued too. */
    public function test_a_refresh_catalogues_accounts_that_did_not_exist_before(): void
    {
        $this->discoveredWithoutName('act-1', 'org-1');

        $this->providerReturns([
            $this->organisation('org-1', 'Acme Media', [['id' => 'act-1', 'name' => 'Existing']]),
            $this->organisation('org-2', 'Beta Group', [['id' => 'act-9', 'name' => 'New one']]),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame(2, ExternalAccount::withoutGlobalScopes()->count());
    }

    /** A refresh is a write, and answers to the connect permission rather than the view one. */
    public function test_a_viewer_may_not_refresh(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $viewerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $viewerRole->givePermissionTo('integrations.view');
        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($viewerRole);
        app(TenantContext::class)->forget();

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertForbidden();
    }

    /** Another tenant's connection is a 404 — the refusal says nothing about it existing. */
    public function test_another_tenants_connection_cannot_be_refreshed(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        $theirs = $this->connection('snapchat', $other);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$theirs->id}/refresh")
            ->assertNotFound();
    }

    /** A revoked connection has nothing to read, and says so rather than calling out. */
    public function test_a_revoked_connection_is_refused_before_any_provider_call(): void
    {
        $this->connection->forceFill(['status' => 'revoked'])->save();
        $this->providerReturns([]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertStatus(409);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * Script Snapchat's own answer, and let the REAL connector map it.
     *
     * The behaviour under test is the catalogue's — upsert, keep identity, mark what stopped coming
     * back — but running it through the real adapter is what makes the fixture meaningful: it is
     * `SnapchatConnector` that reads `organization.name` into `parent_name`, and a double would let
     * that mapping rot while these tests went on passing.
     *
     * @param  list<array{id:string,name:string,accounts:list<array{id:string,name:string}>}>  $organisations
     */
    private function providerReturns(array $organisations): void
    {
        $this->configure('snapchat');

        Http::fake([
            'adsapi.snapchat.com/*me/organizations*' => Http::response([
                'organizations' => array_map(fn (array $org): array => [
                    'organization' => [
                        'id' => $org['id'],
                        'name' => $org['name'],
                        'ad_accounts' => array_map(fn (array $a): array => [
                            'id' => $a['id'],
                            'name' => $a['name'],
                            'currency' => 'SAR',
                            'timezone' => 'Asia/Riyadh',
                            'status' => 'ACTIVE',
                        ], $org['accounts']),
                    ],
                ], $organisations),
            ]),
        ]);
    }

    /** @return array{id:string,name:string,accounts:list<array{id:string,name:string}>} */
    private function organisation(string $id, string $name, array $accounts): array
    {
        return ['id' => $id, 'name' => $name, 'accounts' => $accounts];
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function connection(string $provider, ?Tenant $tenant = null): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: ($tenant ?? $this->tenant)->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: $provider,
        );
    }

    private function discoveredWithoutName(string $externalId, string $parentExternalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection->id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'parent_external_id' => $parentExternalId,
            // The live shape: never recorded, because the column did not exist at discovery time.
            'parent_name' => null,
            'discovered_at' => now()->subWeek(),
            'last_synced_at' => null,
        ]);
    }

    private function bindTo(ExternalAccount $account): ProjectIntegrationBinding
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create([
            'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);
    }
}
