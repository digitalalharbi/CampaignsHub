<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §16 — the authorisation truth and the data truth, independently.
 *
 * These exist because the product spent a release answering two questions with one word, and the
 * word that won was the wrong one on Production: a stale `running` row outranked a refused Meta
 * grant and the card hid the only control that could fix it.
 *
 * The fix that shipped re-ranked them (#578). These pin the stronger property — that there is no
 * ranking left to get wrong, because both facts are reported and neither can hide the other.
 */
final class IntegrationTruthTest extends TestCase
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
     * The exact Production state: refused grant, run open. BOTH are said, neither is hidden.
     *
     * Under the single-word state this was unrepresentable — one of the two had to be dropped, and
     * which one depended on the order of a `match`.
     */
    public function test_a_refused_grant_and_an_open_run_are_both_reported(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection);
        $this->select($account);
        $this->syncRun($account, SyncRunStatus::Running, Carbon::now()->subMinutes(2));
        $connection->forceFill(['insights_denied_at' => Carbon::now()])->save();

        $truth = app(ConnectionWizardState::class)->for($connection->fresh());

        $this->assertSame(IntegrationTruth::REAUTH_REQUIRED, $truth['connection_state']);
        $this->assertSame(IntegrationTruth::SYNCING, $truth['sync_state']);
    }

    /** A worker that died must not leave a card reading «مزامنة جارية» for ever. */
    public function test_a_run_open_for_an_hour_is_no_longer_believed(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection);
        $this->select($account);
        $this->syncRun($account, SyncRunStatus::Success, Carbon::now()->subDay());
        $this->syncRun($account, SyncRunStatus::Running, Carbon::now()->subMinutes(IntegrationTruth::ABANDONED_AFTER_MINUTES + 1));

        $this->assertSame(
            IntegrationTruth::SUCCEEDED,
            app(ConnectionWizardState::class)->for($connection->fresh())['sync_state'],
        );
    }

    /**
     * ACCOUNT-SCOPE-ISOLATION-001 — a DESELECTED account's failure is not this connection's state.
     *
     * Discovery is not selection, and a run belonging to something nobody chose must not describe
     * what they did choose.
     */
    public function test_a_deselected_accounts_failure_is_not_the_connections_data_state(): void
    {
        $connection = $this->connection();

        $chosen = $this->account($connection, 'act_chosen');
        $this->select($chosen);
        $this->syncRun($chosen, SyncRunStatus::Success, Carbon::now()->subHours(2));

        $ignored = $this->account($connection, 'act_ignored');
        $this->syncRun($ignored, SyncRunStatus::Failed, Carbon::now()->subMinute());

        $this->assertSame(
            IntegrationTruth::SUCCEEDED,
            app(ConnectionWizardState::class)->for($connection->fresh())['sync_state'],
        );
    }

    /** Nothing chosen, so there is nothing to have synced — and that is not a failure. */
    public function test_a_connection_with_no_selection_has_never_synced(): void
    {
        $connection = $this->connection();
        $this->account($connection);

        $truth = app(ConnectionWizardState::class)->for($connection->fresh());

        $this->assertSame(IntegrationTruth::CONNECTED, $truth['connection_state']);
        $this->assertSame(IntegrationTruth::NEVER_SYNCED, $truth['sync_state']);
    }

    /** Chosen, and owed a run nothing has started: queued, which is neither success nor failure. */
    public function test_a_selection_whose_run_has_not_started_is_queued(): void
    {
        $connection = $this->connection();
        $this->select($this->account($connection));

        $this->assertSame(
            IntegrationTruth::QUEUED,
            app(ConnectionWizardState::class)->for($connection->fresh())['sync_state'],
        );
    }

    /** One account still owed a first run keeps the connection queued, however well the others did. */
    public function test_one_unrun_account_keeps_the_selection_queued(): void
    {
        $connection = $this->connection();
        $done = $this->account($connection, 'act_done');
        $this->select($done);
        $this->syncRun($done, SyncRunStatus::Success, Carbon::now()->subHour());
        $this->select($this->account($connection, 'act_new'));

        $this->assertSame(
            IntegrationTruth::QUEUED,
            app(ConnectionWizardState::class)->for($connection->fresh())['sync_state'],
        );
    }

    /** A revoked authorisation is not «never connected», and it is not a data state either. */
    public function test_a_revoked_authorisation_is_reported_as_revoked(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection);
        $this->select($account);
        $this->syncRun($account, SyncRunStatus::Success, Carbon::now()->subHour());
        $connection->forceFill(['status' => 'revoked'])->save();

        $truth = app(ConnectionWizardState::class)->for($connection->fresh());

        $this->assertSame(IntegrationTruth::REVOKED, $truth['connection_state']);
        $this->assertSame(IntegrationTruth::SUCCEEDED, $truth['sync_state']);
    }

    private function connection(): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => 'meta',
            'connection_name' => 'Meta',
            'scope' => 'project_only',
            'status' => 'connected',
            'scopes' => ['ads_read'],
        ]);
    }

    private function account(ProviderConnection $connection, string $externalId = 'act_1'): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'meta',
            'external_id' => $externalId,
            'name' => 'Account '.$externalId,
            'account_type' => 'ad_account',
            'status' => 'active',
        ]);
    }

    private function select(ExternalAccount $account): void
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $workspace->id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);
    }

    private function syncRun(ExternalAccount $account, SyncRunStatus $status, Carbon $startedAt): MetricSyncRun
    {
        return MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'status' => $status->value,
            'window_start' => $startedAt->copy()->subDays(7)->toDateString(),
            'window_end' => $startedAt->copy()->toDateString(),
            'started_at' => $startedAt,
            'finished_at' => $status === SyncRunStatus::Running ? null : $startedAt->copy()->addMinute(),
            'metrics_upserted' => $status === SyncRunStatus::Success ? 12 : 0,
        ]);
    }
}
