<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-002 — a ChatGPT Ads key answers for ONE account, and only that account's
 * rows may be stored from it.
 *
 * The incident this guards (Production, 2026-11-01): two advertisers' keys were pasted into the same
 * workspace. The second re-credentialed the connection the first had opened, and the first
 * advertiser's account row — still named, still bound to its own project — went on syncing with the
 * second advertiser's key. The collection endpoints take no account id, so what came back was the
 * second advertiser's campaigns, filed under the first's name and into the first's project, and from
 * there into a client report.
 *
 * Every test here is the shape of that incident: two projects, two accounts, ONE key. The first half
 * proves the sync refuses; the second proves a pasted key can no longer re-credential another
 * advertiser's connection in the first place.
 */
final class OpenAiAdsAccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_FOR_B = 'sk-ads-live-belongs-to-b-0000';

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $accountA;

    private ExternalAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->projectA = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'A', 'status' => 'active',
        ]);
        $this->projectB = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'B', 'status' => 'active',
        ]);

        // ONE connection, holding B's key — exactly the state a re-keyed connection was left in.
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens(self::KEY_FOR_B, null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        $this->accountA = $this->account($connection, 'acct_a', 'Advertiser A');
        $this->accountB = $this->account($connection, 'acct_b', 'Advertiser B');

        $this->bind($this->accountA, $this->projectA, $workspace);
        $this->bind($this->accountB, $this->projectB, $workspace);
    }

    /** The metrics sync for the account the key does NOT own stores nothing and says why. */
    public function test_a_metrics_sync_with_another_accounts_key_is_refused_and_stores_nothing(): void
    {
        $this->providerAnswersForB();

        $this->runMetricsSync($this->accountA);

        $run = DB::table('metric_sync_runs')->where('external_account_id', $this->accountA->id)->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('ACCOUNT-SCOPE-ISOLATION-002', (string) $run->error);
        $this->assertStringNotContainsString(self::KEY_FOR_B, (string) $run->error);

        $this->assertSame(0, DB::table('daily_metrics')->where('project_id', $this->projectA->id)->count(),
            'another advertiser\'s figures reached project A');
        $this->assertSame(0, DB::table('daily_metrics')->where('external_account_id', $this->accountA->id)->count());
    }

    /** The same key, the account it belongs to: the figures land, in B's project only. */
    public function test_the_owning_account_still_syncs_into_its_own_project(): void
    {
        $this->providerAnswersForB();

        $this->runStructureSync($this->accountB);
        $this->runMetricsSync($this->accountB);

        $this->assertGreaterThan(0, DB::table('daily_metrics')->where('project_id', $this->projectB->id)->count());
        $this->assertSame(0, DB::table('daily_metrics')->where('project_id', $this->projectA->id)->count());
    }

    /** Structure (campaigns, ad groups, ads) is refused on the same proof — no campaign of B's under A. */
    public function test_a_structure_sync_with_another_accounts_key_stores_no_campaign(): void
    {
        $this->providerAnswersForB();

        $this->runStructureSync($this->accountA);

        $this->assertSame(0, DB::table('external_campaigns')->where('external_account_id', $this->accountA->id)->count());
        $this->assertSame(0, DB::table('external_campaigns')->where('project_id', $this->projectA->id)->count());

        $run = DB::table('integration_sync_runs')->where('project_id', $this->projectA->id)->where('type', 'structure')->latest('created_at')->first();
        $this->assertNotNull($run);
        $this->assertStringContainsString('ACCOUNT-SCOPE-ISOLATION-002', (string) $run->error);

        // And B's own structure sync is untouched by A's refusal.
        $this->runStructureSync($this->accountB);
        $this->assertSame(1, DB::table('external_campaigns')->where('external_account_id', $this->accountB->id)->count());
    }

    /** An account the provider stopped listing under its credential is no longer synced by the sweeps. */
    public function test_an_account_the_credential_no_longer_lists_is_not_actively_assigned(): void
    {
        $this->assertTrue(app(AccountAssignment::class)->isActivelyAssigned($this->accountA));

        $this->accountA->forceFill(['access_lost_at' => Carbon::now()])->save();

        $this->assertFalse(app(AccountAssignment::class)->isActivelyAssigned($this->accountA->fresh()));
        $this->assertSame(0, app(AccountAssignment::class)->scopeToAssigned(
            ExternalAccount::withoutGlobalScopes()->whereNull('access_lost_at')->where('id', $this->accountA->id),
        )->count());
    }

    /**
     * A key for a DIFFERENT advertiser opens its own connection. The first advertiser's connection
     * keeps its own key, and its account keeps its own name and project.
     */
    public function test_a_key_for_another_account_opens_its_own_connection_instead_of_rekeying(): void
    {
        $this->seed(PermissionSeeder::class);
        $operator = $this->operator();

        // Start clean: the connection from setUp is the incident's state, this test proves it can no longer arise.
        ProviderConnection::withoutGlobalScopes()->delete();

        $this->providerSaysKeyBelongsTo('acct_a', 'Advertiser A');
        $this->connect($operator, 'sk-ads-live-belongs-to-a-0000')->assertCreated();

        $first = ProviderConnection::withoutGlobalScopes()->sole();
        $this->assertSame('acct_a', $first->external_owner_id);

        $this->providerSaysKeyBelongsTo('acct_b', 'Advertiser B');
        $this->connect($operator, self::KEY_FOR_B)->assertCreated();

        $this->assertSame(2, ProviderConnection::withoutGlobalScopes()->count(), 'B\'s key re-credentialed A\'s connection');

        $first->refresh();
        $this->assertSame('sk-ads-live-belongs-to-a-0000', app(TokenVault::class)->stored($first)->accessToken);
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $first->id)->count());
        $this->assertSame('acct_a', ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $first->id)->sole()->external_id);

        $second = ProviderConnection::withoutGlobalScopes()->where('id', '!=', $first->id)->sole();
        $this->assertSame('acct_b', $second->external_owner_id);
        $this->assertSame('acct_b', ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $second->id)->sole()->external_id);

        // Rotating A's key still re-credentials A's connection — and only A's.
        $this->providerSaysKeyBelongsTo('acct_a', 'Advertiser A');
        $this->connect($operator, 'sk-ads-live-belongs-to-a-ROTATED')->assertCreated();
        $this->assertSame(2, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame('sk-ads-live-belongs-to-a-ROTATED', app(TokenVault::class)->stored($first->fresh())->accessToken);
        $this->assertSame(self::KEY_FOR_B, app(TokenVault::class)->stored($second->fresh())->accessToken);
    }

    /**
     * The incident's own connection, repaired by its customers: A pastes A's key. A's row — bindings,
     * project and all — moves to a connection of its own; B keeps the old connection and B's key.
     */
    public function test_a_key_pasted_onto_a_shared_legacy_connection_takes_only_its_own_row(): void
    {
        $this->seed(PermissionSeeder::class);
        $legacy = ProviderConnection::withoutGlobalScopes()->sole();
        $this->assertNull($legacy->external_owner_id);

        $this->providerSaysKeyBelongsTo('acct_a', 'Advertiser A');
        $this->connect($this->operator(), 'sk-ads-live-belongs-to-a-0000')->assertCreated();

        $this->assertSame(2, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame(self::KEY_FOR_B, app(TokenVault::class)->stored($legacy->fresh())->accessToken);

        $moved = $this->accountA->fresh();
        $this->assertNotSame($legacy->id, $moved->provider_connection_id);
        $this->assertSame('sk-ads-live-belongs-to-a-0000', app(TokenVault::class)->stored(ProviderConnection::withoutGlobalScopes()->find($moved->provider_connection_id))->accessToken);
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('external_id', 'acct_a')->count(), 'a second row for the same account');
        $this->assertSame($this->projectA->id, app(AccountAssignment::class)->projectIdFor($moved));
        $this->assertSame($legacy->id, $this->accountB->fresh()->provider_connection_id);
    }

    /** A legacy connection holding only this account is simply rotated. */
    public function test_a_legacy_connection_holding_only_this_account_is_rotated_in_place(): void
    {
        $this->seed(PermissionSeeder::class);
        $legacy = ProviderConnection::withoutGlobalScopes()->sole();
        $this->accountA->delete();

        $this->providerSaysKeyBelongsTo('acct_b', 'Advertiser B');
        $this->connect($this->operator(), 'sk-ads-live-belongs-to-b-ROTATED')->assertCreated();

        $this->assertSame($legacy->id, ProviderConnection::withoutGlobalScopes()->sole()->id);
        $this->assertSame('acct_b', $legacy->fresh()->external_owner_id);
        $this->assertSame('sk-ads-live-belongs-to-b-ROTATED', app(TokenVault::class)->stored($legacy->fresh())->accessToken);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function account(ProviderConnection $connection, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'openai_ads',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $name,
            'currency' => 'SAR',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, ClientWorkspace $workspace): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    /** What B's key sees: B's account, B's one campaign, B's figures. */
    private function providerAnswersForB(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/conversions/insights')) {
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights') && ! str_contains($url, 'aggregation_level=campaign')) {
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights')) {
                return Http::response(['object' => 'list', 'data' => [[
                    'id' => 'r1', 'readable_time' => '2026-09-01', 'campaign_id' => 'cmp_b',
                    'spend' => 187.73, 'impressions' => 17954, 'clicks' => 120,
                ]], 'has_more' => false]);
            }

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_b', 'name' => 'B campaign']], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_groups') || str_contains($url, '/ads')) {
                return Http::response(['data' => [], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_b', 'object' => 'ad_account', 'name' => 'Advertiser B', 'currency' => 'SAR']);
        });
    }

    /** @var array{string, string}|null the account the provider currently says the pasted key belongs to */
    private ?array $keyOwner = null;

    /**
     * Registered ONCE and read per call: `Http::fake()` merges stubs and the first registered match
     * wins, so re-registering the same pattern with a different account would keep answering the
     * first — which is exactly the mistake this test exists to catch, and not the one it should make.
     */
    private function providerSaysKeyBelongsTo(string $accountId, string $name): void
    {
        $first = $this->keyOwner === null;
        $this->keyOwner = [$accountId, $name];

        if ($first) {
            Http::fake(fn () => Http::response([
                'id' => $this->keyOwner[0], 'object' => 'ad_account', 'name' => $this->keyOwner[1],
                'currency' => 'SAR', 'status' => 'ACTIVE',
            ]));
        }
    }

    private function operator(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret-password']);
        $this->grantMembership($operator, $this->tenant);
        $operator->assignRole($role);

        return $operator;
    }

    private function connect(User $operator, string $key)
    {
        return $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/integrations/openai_ads/api-key/connect', ['api_key' => $key]);
    }

    private function runStructureSync(ExternalAccount $account): void
    {
        app()->call([new SyncAccountStructureJob($account->id), 'handle']);
    }

    private function runMetricsSync(ExternalAccount $account): void
    {
        app()->call([new SyncAccountMetricsJob($account->id, '2026-09-01', '2026-09-07'), 'handle']);
    }
}
