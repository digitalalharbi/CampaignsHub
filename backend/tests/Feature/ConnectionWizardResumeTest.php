<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * ORCH-100 §39 §41 — a closed browser must not cost a second consent.
 *
 * The live Snapchat connection was authorised, discovered 309 accounts, and was then left alone. The
 * product offered nothing but the connect button again — a fresh OAuth for an authorisation that had
 * never lapsed, which is both a worse experience and a pointless re-consent.
 *
 * The state is DERIVED from the data rather than remembered, so there is nothing to expire and
 * nothing to reconcile: a connection with accounts and no bindings is at `needs_selection` whether
 * that was true a minute ago or a week ago.
 *
 * These tests also pin the distinction the old single «متصل» chip erased — authorised, chosen,
 * synced and revoked are four different situations with four different next actions.
 */
final class ConnectionWizardResumeTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    /** The live shape: authorised, 309 discovered, nothing chosen — resumable, not finished. */
    public function test_an_authorisation_with_discoveries_and_no_choices_is_resumable(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 309);

        $state = app(ConnectionWizardState::class)->for($connection);

        $this->assertSame(ConnectionWizardState::NEEDS_SELECTION, $state['state']);
        $this->assertSame(309, $state['discovered']);
        $this->assertSame(0, $state['assigned']);
        $this->assertSame(0, $state['synced']);
        $this->assertTrue($state['resumable'], 'the token is valid; asking for consent again is wrong');
        $this->assertSame('parent', $state['next_step'], 'Snapchat resumes at its organisations');
    }

    /** A provider with no parent level resumes straight at the account list. */
    public function test_a_provider_without_a_parent_resumes_at_the_accounts_step(): void
    {
        $connection = $this->connection('meta');
        $this->discover($connection, 4);

        $this->assertSame('accounts', app(ConnectionWizardState::class)->for($connection)['next_step']);
    }

    /** Once accounts are assigned but nothing has synced, the next move is the first sync. */
    public function test_assigned_but_never_synced_is_first_sync_pending(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 3);
        $this->assign(ExternalAccount::withoutGlobalScopes()->first());

        $state = app(ConnectionWizardState::class)->for($connection);

        $this->assertSame(ConnectionWizardState::FIRST_SYNC_PENDING, $state['state']);
        $this->assertSame(1, $state['assigned']);
        $this->assertSame(0, $state['synced']);
        $this->assertFalse($state['resumable'], 'this is not an unfinished connection any more');
        $this->assertSame('sync', $state['next_step']);
    }

    /** And only a REAL sync moves it on — `last_synced_at` is written by data, not by discovery. */
    public function test_a_real_sync_is_what_makes_a_connection_active(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 3);

        $account = ExternalAccount::withoutGlobalScopes()->first();
        $this->assign($account);
        $account->forceFill(['last_synced_at' => now()])->save();

        $state = app(ConnectionWizardState::class)->for($connection);

        $this->assertSame(ConnectionWizardState::ACTIVE, $state['state']);
        $this->assertSame(1, $state['synced']);
        $this->assertNull($state['next_step']);
    }

    // ── INTEGRATION-DATASOURCE-WIZARD-001 §14 — the state a READER is shown ───────────────────

    /**
     * One vocabulary, derived from the record, and no surface adds a tenth.
     *
     * The integrations card said «متصل», the wizard said «needs selection» and the project page
     * said «قيد المزامنة» — three names for one connection, and none of them agreed about a
     * connection whose accounts were bound but had never produced a row.
     */
    public function test_the_reader_state_follows_the_record(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 4);

        $this->assertSame(
            ConnectionWizardState::USER_ACCOUNT_SELECTION_REQUIRED,
            app(ConnectionWizardState::class)->for($connection)['user_state'],
        );

        $account = ExternalAccount::withoutGlobalScopes()->first();
        $this->assign($account);

        $this->assertSame(
            ConnectionWizardState::USER_SYNCING,
            app(ConnectionWizardState::class)->for($connection)['user_state'],
            'bindings with no row yet is SYNCING — «connected» over an empty dashboard reads as «my data is gone»',
        );

        $account->forceFill(['last_synced_at' => now()])->save();

        $this->assertSame(
            ConnectionWizardState::USER_HEALTHY,
            app(ConnectionWizardState::class)->for($connection)['user_state'],
        );
    }

    /**
     * One account in trouble outranks nine that are fine.
     *
     * Ten accounts behind one authorisation, nine syncing and one whose access was withdrawn, used
     * to render as a single green «متصل» — and that one account was the only fact anybody needed.
     */
    public function test_an_account_that_needs_attention_outranks_a_healthy_connection(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 2);

        foreach (ExternalAccount::withoutGlobalScopes()->get() as $account) {
            $this->assign($account);
            $account->forceFill(['last_synced_at' => now()])->save();
        }

        $broken = ExternalAccount::withoutGlobalScopes()->first();
        $broken->forceFill(['access_lost_at' => now()])->save();

        $this->assertSame(
            ConnectionWizardState::USER_ATTENTION_REQUIRED,
            app(ConnectionWizardState::class)->for($connection)['user_state'],
        );
    }

    /** A revoked authorisation asks for consent again, and says so in the reader's vocabulary. */
    public function test_a_revoked_connection_reads_as_needing_reauthorisation(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 3);
        $connection->forceFill(['status' => 'revoked'])->save();

        $this->assertSame(
            ConnectionWizardState::USER_REAUTH_REQUIRED,
            app(ConnectionWizardState::class)->for($connection->refresh())['user_state'],
        );
    }

    /** A revoked authorisation is not resumable — it needs consent, and says so. */
    public function test_a_revoked_connection_asks_to_reconnect(): void
    {
        $connection = $this->connection('snapchat');
        $this->discover($connection, 5);
        $connection->forceFill(['status' => 'revoked'])->save();

        $state = app(ConnectionWizardState::class)->for($connection->refresh());

        $this->assertSame(ConnectionWizardState::ACCESS_REVOKED, $state['state']);
        $this->assertFalse($state['resumable']);
        $this->assertSame('reconnect', $state['next_step']);
    }

    /** An authorisation the provider gave nothing for says that plainly rather than looking connected. */
    public function test_an_authorisation_with_no_accounts_says_so(): void
    {
        $connection = $this->connection('snapchat');

        $this->assertSame(
            ConnectionWizardState::NO_ACCOUNTS,
            app(ConnectionWizardState::class)->for($connection)['state'],
        );
    }

    // ── The endpoint ──────────────────────────────────────────────────────────────────────────

    /** The integrations page reads the unfinished ones from here. */
    public function test_the_endpoint_reports_the_unfinished_connection(): void
    {
        $unfinished = $this->connection('snapchat');
        $this->discover($unfinished, 309);

        $done = $this->connection('meta');
        $this->discover($done, 2);
        $account = ExternalAccount::withoutGlobalScopes()->where('provider', 'meta')->first();
        $this->assign($account);
        $account->forceFill(['last_synced_at' => now()])->save();

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/connections/resumable')
            ->assertOk();

        $this->assertCount(2, $response->json('data.connections'));
        $this->assertCount(1, $response->json('data.resumable'), 'only the unfinished one');
        $this->assertSame('snapchat', $response->json('data.resumable.0.connection.provider'));
        $this->assertSame(309, $response->json('data.resumable.0.discovered'));
        $this->assertSame(0, $response->json('data.resumable.0.assigned'));
    }

    /** Another tenant's unfinished connection is not ours to resume. */
    public function test_another_tenants_connection_is_not_listed(): void
    {
        $theirs = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($theirs->id);
        $this->discover($this->connection('snapchat', $theirs), 10, $theirs);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/connections/resumable')
            ->assertOk()
            ->assertJsonCount(0, 'data.connections');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * One uncatalogued connection may not take down the integrations page.
     *
     * `ProviderCatalogue::get()` THROWS on a provider it does not know, and `resumable()` called it
     * once per row with nothing between. So a single row carrying a provider the catalogue has since
     * dropped — a renamed key, a commerce connection, a value written by an older build — returned a
     * 500 for the whole endpoint, and the integrations page rendered nothing for anybody in that
     * tenant. The E2E gate caught it as «console errors on /app/integrations».
     *
     * The row is still LISTED rather than filtered away. A connection that exists and cannot be named
     * is exactly the one an operator needs to see in order to remove it; hiding it would leave them
     * with a page that looks healthy and a sync that never runs.
     */
    public function test_an_uncatalogued_provider_does_not_take_down_the_endpoint(): void
    {
        $this->connection('meta');
        $this->connection('a_provider_the_catalogue_never_heard_of');

        $body = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/connections/resumable')
            ->assertOk()
            ->json('data');

        $providers = array_column(array_column($body['connections'], 'connection'), 'provider');

        $this->assertContains('meta', $providers, 'the healthy connection was lost with the odd one');
        $this->assertContains('a_provider_the_catalogue_never_heard_of', $providers, 'the unnameable connection was hidden');

        $odd = collect($body['connections'])
            ->firstWhere('connection.provider', 'a_provider_the_catalogue_never_heard_of');

        $this->assertFalse($odd['connection']['catalogued'], 'the row does not say it cannot be named');
        // Falls back to the raw key rather than inventing a friendly name for something unknown.
        $this->assertSame('a_provider_the_catalogue_never_heard_of', $odd['connection']['label']);
    }

    /** The same stale row one endpoint along: a refusal, not a stack trace. */
    public function test_the_hierarchy_of_an_uncatalogued_connection_is_refused_not_crashed(): void
    {
        $connection = $this->connection('a_provider_the_catalogue_never_heard_of');

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->getKey()}/hierarchy")
            ->assertStatus(404);
    }

    private function connection(string $provider, ?Tenant $tenant = null): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function discover(ProviderConnection $connection, int $many, ?Tenant $tenant = null): void
    {
        foreach (range(1, $many) as $i) {
            ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => ($tenant ?? $this->tenant)->id,
                'provider_connection_id' => $connection->id,
                'provider' => $connection->provider,
                'account_type' => 'ad_account',
                'external_id' => "{$connection->provider}-act-{$i}",
                'parent_external_id' => $connection->provider === 'snapchat' ? 'org-1' : null,
                'parent_name' => $connection->provider === 'snapchat' ? 'Acme Media' : null,
                'name' => "Account {$i}",
                'status' => 'active',
                'discovered_at' => now(),
                'last_synced_at' => null,
            ]);
        }
    }

    private function assign(ExternalAccount $account): void
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P '.uniqid(), 'status' => 'active',
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => 'advertising',
            'is_active' => true,
        ]);
    }
}
