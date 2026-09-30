<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Services\FirstSync;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * SAAS-ACCOUNT-SCOPE — re-authorising must not quietly widen what this install reads.
 *
 * The SaaS contract is one app at system level and, per tenant and workspace, a separate
 * authorisation whose accounts are explicitly SELECTED and explicitly BOUND to a project. Consent to
 * SEE an account is not instruction to sync it — the first live Snapchat consent catalogued 309 of
 * them, and a sweep that ignored the distinction would have pulled all 309 every half hour.
 *
 * Reconnecting is where that could erode without anybody noticing. A re-authorisation refreshes the
 * INVENTORY, which is right and is what «discovered» means; the risk is that the refresh is mistaken
 * for a selection. It is a live risk rather than a theoretical one: the reconnect path now starts a
 * sync immediately, precisely so a customer who reconnected because the figures were missing does
 * not wait for the half-hourly sweep — and «sync what this connection has» would have been the
 * obvious way to write that, and wrong.
 *
 * What this holds: a reconnect fetches the accounts somebody CHOSE, and nothing else, however many
 * the authorisation can now see.
 */
final class ReconnectDoesNotBroadenSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /**
     * One account chosen out of several discovered — only that one is fetched.
     *
     * The shape production is in: an authorisation that can see several ad accounts, one of which
     * the Owner selected. Asserted on the POLICY the reconnect calls, not on a set handed to it, so
     * the test fails if the reconnect ever starts asking the catalogue instead of the selection.
     */
    public function test_a_reconnect_fetches_only_the_accounts_somebody_chose(): void
    {
        $connection = $this->connection();
        $chosen = $this->account($connection, 'act_3493018704182532', 'RazahAvanue');
        $this->discoveredOnly($connection, 'act_111');
        $this->discoveredOnly($connection, 'act_222');
        $this->bind($chosen);

        $fetched = app(AccountAssignment::class)->activeAccountIdsForConnection((string) $connection->id);

        $this->assertSame([(string) $chosen->id], $fetched);
    }

    /**
     * An account the reconnect newly DISCOVERS is not fetched, because nobody chose it.
     *
     * A reconnect through a wider identity is the case that would broaden silently: the catalogue
     * grows, and if the refresh were read as a selection the install would start reading accounts
     * nobody asked for — charged to the plan, filed into a project nobody picked.
     */
    public function test_an_account_discovered_by_the_reconnect_is_not_fetched(): void
    {
        $connection = $this->connection();
        $chosen = $this->account($connection, 'act_3493018704182532', 'RazahAvanue');
        $this->bind($chosen);

        $newlyVisible = $this->discoveredOnly($connection, 'act_newly_visible');

        $fetched = app(AccountAssignment::class)->activeAccountIdsForConnection((string) $connection->id);

        $this->assertSame([(string) $chosen->id], $fetched);
        $this->assertNotContains((string) $newlyVisible->id, $fetched);
    }

    /** And the jobs that reach the provider carry exactly that set — one account, one sync. */
    public function test_only_the_chosen_account_is_queued(): void
    {
        Bus::fake();

        $connection = $this->connection();
        $chosen = $this->account($connection, 'act_3493018704182532', 'RazahAvanue');
        $this->discoveredOnly($connection, 'act_111');
        $this->bind($chosen);

        app(FirstSync::class)->start(
            app(AccountAssignment::class)->activeAccountIdsForConnection((string) $connection->id),
            source: 'reconnect',
        );

        Bus::assertDispatched(SyncAccountMetricsJob::class, 1);
    }

    /**
     * ANOTHER tenant's bound account is not fetched by this connection's reconnect.
     *
     * The SaaS contract is one app at system level and a separate authorisation per tenant. The
     * policy is scoped by connection, and a connection belongs to one tenant — asserted here rather
     * than assumed, because this is the query a reconnect runs.
     */
    public function test_another_tenants_bound_account_is_not_fetched(): void
    {
        $mine = $this->connection();
        $chosen = $this->account($mine, 'act_3493018704182532', 'RazahAvanue');
        $this->bind($chosen);

        $otherTenant = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($otherTenant->id);
        $theirs = $this->connection($otherTenant);
        $theirAccount = $this->account($theirs, 'act_theirs', 'Someone else', $otherTenant);
        $this->bind($theirAccount, $otherTenant);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $fetched = app(AccountAssignment::class)->activeAccountIdsForConnection((string) $mine->id);

        $this->assertSame([(string) $chosen->id], $fetched);
    }

    /** The account id a queued job carries, whatever the constructor signature calls it. */
    private function accountIdOf(SyncAccountMetricsJob $job): string
    {
        foreach ((array) $job as $value) {
            if (is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    private function connection(?Tenant $tenant = null): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'credential_id' => $credential->id,
            'provider' => 'meta',
            'connection_name' => 'Meta',
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function account(ProviderConnection $connection, string $externalId, string $name, ?Tenant $tenant = null): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'meta',
            'external_id' => $externalId,
            'name' => $name,
            'account_type' => 'ad_account',
            'status' => 'active',
        ]);
    }

    /** Discovered and nothing more — in the catalogue, chosen by nobody. */
    private function discoveredOnly(ProviderConnection $connection, string $externalId): ExternalAccount
    {
        return $this->account($connection, $externalId, 'Seen, not chosen');
    }

    private function bind(ExternalAccount $account, ?Tenant $tenant = null): void
    {
        $owner = $tenant ?? $this->tenant;
        $workspace = ClientWorkspace::create([
            'tenant_id' => $owner->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);
        $project = Project::create([
            'tenant_id' => $owner->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $owner->id,
            'project_id' => $project->id,
            'client_workspace_id' => $workspace->id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);
    }
}
