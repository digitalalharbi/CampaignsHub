<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RUNTIME-100 §30 §31 — an account's sync state, said honestly, and filed where it belongs.
 *
 * ## The half of ASSIGN-PROJECT-001 that survived
 *
 * `AccountStructureSyncer::projectIdFor()` was fixed to read the assignment. `AccountMetricsSyncer`
 * has its own copy of that method and it was NOT — it still ends:
 *
 * ```php
 * return Project::withoutGlobalScopes()
 *     ->where('tenant_id', $account->tenant_id)
 *     ->orderBy('created_at')
 *     ->value('id');
 * ```
 *
 * `MetricSyncRun` carries `BelongsToProject`, and `SyncRunController` lists runs project-scoped. So a
 * metrics run for an account belonging to client B — assigned, correct, doing exactly what it was
 * told — is filed under client A's oldest project the whole time it has no campaigns yet, which is
 * precisely the window a FIRST sync runs in. Client A's operator then reads sync history naming
 * client B's provider, account and row counts, and `DataFreshnessService` computes A's freshness from
 * B's runs.
 *
 * Two copies of one rule, one of them fixed. That is what made it survive a review of the fix.
 *
 * ## And «last synced» was the only thing the record could say
 *
 * `last_synced_at` alone cannot distinguish «never tried» from «tried an hour ago and failed» from
 * «succeeded and is due again at 03:00». All three render as an absent or stale timestamp, so a
 * broken integration and a new one look the same on every screen. The checkpoint columns below exist
 * so each of those is a different answer.
 */
final class SyncCheckpointAndHealthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // A is deliberately the OLDER client — it is what the fallback would have chosen.
        $this->clientA = $this->workspace('Client A');
        $this->clientB = $this->workspace('Client B');
        $this->projectA = $this->project('A — retainer', $this->clientA, '2026-01-01');
        $this->projectB = $this->project('B — retainer', $this->clientB, '2026-06-01');
    }

    // ── Where a run is filed ──────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** A metrics run belongs to the project the account was assigned to.
     *
     * Before the fix, an assigned account with no campaigns yet filed its run under the tenant's
     * OLDEST project — which is another agency client's, and is visible to them.
     */
    public function test_a_metrics_run_is_filed_under_the_project_the_account_was_assigned_to(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $this->assertSame(
            $this->projectB->id,
            $run->project_id,
            'METRICS-RUN-PROJECT-001: the run was filed under the tenant\'s oldest project, so one '
                .'agency client\'s sync history — provider, account and counts — appeared in another\'s.',
        );
    }

    /** An unassigned account files no run against anybody's project. */
    public function test_an_unassigned_account_is_not_filed_into_someone_elses_project(): void
    {
        $account = $this->discoveredAccount();

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $this->assertNotSame($this->projectA->id, $run->project_id);
        $this->assertNotSame($this->projectB->id, $run->project_id);
    }

    // ── The checkpoint ────────────────────────────────────────────────────────────────────────

    /** Every attempt is recorded as an attempt, whatever it produced. */
    public function test_an_attempt_is_stamped_even_when_it_produces_nothing(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $this->assertNull($account->last_sync_attempt_at);

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $account->refresh();
        $this->assertNotNull(
            $account->last_sync_attempt_at,
            'RUNTIME-100 §30: «we tried and it failed» and «we never tried» were the same absent '
                .'timestamp, so a broken integration read exactly like a new one.',
        );
    }

    /** A failure names its CATEGORY, because that is what decides who has to act. */
    public function test_a_failure_records_a_category_not_only_a_message(): void
    {
        // `snapchat` is unconfigured on this install, so the syncer refuses before calling out —
        // a real, reachable outcome rather than a simulated one.
        $account = $this->assignedAccount($this->projectB, $this->clientB, provider: 'snapchat');

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $account->refresh();
        $this->assertSame('awaiting_credentials', $account->last_sync_error_category);
        $this->assertNull($account->last_synced_at, 'a refusal is not a sync');
    }

    /** A success clears the failure and moves the success checkpoint, not merely the attempt one. */
    public function test_a_success_clears_the_error_category(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $account->forceFill(['last_sync_error_category' => 'provider_error'])->save();

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $account->refresh();
        $this->assertNull($account->last_sync_error_category);
        $this->assertNotNull($account->last_synced_at);
    }

    // ── The health model ──────────────────────────────────────────────────────────────────────

    /** An account nobody assigned is not «unhealthy» — it is not in the pipeline at all. */
    public function test_a_discovered_account_is_not_described_as_a_failing_one(): void
    {
        $this->assertSame(AccountHealth::NOT_CONNECTED, app(AccountHealth::class)->for($this->discoveredAccount()));
    }

    /** Assigned and never synced is «waiting for its first sync», which is a state and not a fault. */
    public function test_an_assigned_account_awaiting_its_first_sync_says_so(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);

        $this->assertSame(AccountHealth::PENDING_FIRST_SYNC, app(AccountHealth::class)->for($account));
    }

    /** A recent success is healthy. */
    public function test_a_recently_synced_account_is_healthy(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $account->forceFill(['last_synced_at' => Carbon::now()->subHour()])->save();

        $this->assertSame(AccountHealth::HEALTHY, app(AccountHealth::class)->for($account->refresh()));
    }

    /**
     * A success long enough ago is DELAYED, and that is different from failed.
     *
     * Nothing errored; the data is simply older than the customer should have to guess at. Reporting
     * it as an error would send somebody to reconnect an authorisation that is working.
     */
    public function test_an_account_whose_last_success_is_old_is_delayed_not_failed(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $account->forceFill(['last_synced_at' => Carbon::now()->subDays(3)])->save();

        $this->assertSame(AccountHealth::DELAYED, app(AccountHealth::class)->for($account->refresh()));
    }

    /** A failure after a success is FAILED — the stale data is a symptom, not the story. */
    public function test_a_failed_attempt_after_a_success_reads_as_failed(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $account->forceFill([
            'last_synced_at' => Carbon::now()->subHours(2),
            'last_sync_attempt_at' => Carbon::now()->subMinutes(5),
            'last_sync_error_category' => 'provider_error',
        ])->save();

        $this->assertSame(AccountHealth::FAILED, app(AccountHealth::class)->for($account->refresh()));
    }

    /** Access lost outranks everything: no amount of retrying fixes a permission we no longer have. */
    public function test_access_lost_outranks_a_stale_timestamp(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $account->forceFill([
            'last_synced_at' => Carbon::now()->subDays(9),
            'access_lost_at' => Carbon::now()->subDay(),
        ])->save();

        $this->assertSame(AccountHealth::ACCESS_LOST, app(AccountHealth::class)->for($account->refresh()));
    }

    /** And a revoked connection is the connection's problem, reported as the connection's problem. */
    public function test_a_revoked_connection_outranks_the_accounts_own_history(): void
    {
        $account = $this->assignedAccount($this->projectB, $this->clientB);
        $account->forceFill(['last_synced_at' => Carbon::now()])->save();

        ProviderConnection::withoutGlobalScopes()
            ->whereKey($account->provider_connection_id)
            ->update(['status' => 'revoked']);

        $this->assertSame(AccountHealth::REVOKED, app(AccountHealth::class)->for($account->refresh()));
    }

    /**
     * A connection's headline is a SUMMARY of its accounts, not a single green badge.
     *
     * «10 connected · 9 healthy · 1 needs attention» is the sentence an operator can act on; «متصل»
     * over one broken account out of ten is the sentence that hides it.
     */
    public function test_a_connection_summarises_its_accounts_rather_than_claiming_one_state(): void
    {
        $healthy = $this->assignedAccount($this->projectB, $this->clientB, externalId: 'act-ok');
        $healthy->forceFill(['last_synced_at' => Carbon::now()->subHour()])->save();

        $broken = $this->assignedAccount($this->projectB, $this->clientB, externalId: 'act-bad', connection: $healthy->connection);
        $broken->forceFill([
            'last_synced_at' => Carbon::now()->subHours(2),
            'last_sync_attempt_at' => Carbon::now(),
            'last_sync_error_category' => 'provider_error',
        ])->save();

        $summary = app(AccountHealth::class)->summarise((string) $healthy->provider_connection_id);

        $this->assertSame(2, $summary['connected']);
        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(1, $summary['needs_attention']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace, string $createdAt): Project
    {
        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
        $project->forceFill(['created_at' => Carbon::parse($createdAt)])->save();

        return $project;
    }

    private function connection(string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function discoveredAccount(
        string $provider = 'sandbox',
        string $externalId = 'act-1',
        ?ProviderConnection $connection = null,
    ): ExternalAccount {
        $connection ??= $this->connection($provider);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
            'last_synced_at' => null,
        ]);
    }

    private function assignedAccount(
        Project $project,
        ClientWorkspace $workspace,
        string $provider = 'sandbox',
        string $externalId = 'act-1',
        ?ProviderConnection $connection = null,
    ): ExternalAccount {
        $account = $this->discoveredAccount($provider, $externalId, $connection);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        return $account;
    }
}
