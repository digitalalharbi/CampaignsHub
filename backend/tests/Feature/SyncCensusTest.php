<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SYNC-CENSUS-001 — the permanent form of «an organisation of 321 accounts, two selected»: for EVERY provider the sweep
 * handles, exactly the two selected accounts are queued and no request leaves; and the census command names an account
 * that synced without being assigned.
 */
final class SyncCensusTest extends TestCase
{
    use RefreshDatabase;

    /** This test asserts the no-binding branch itself (an account that synced without being assigned) and seeds its own bindings. */
    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return array_combine($p = ['snapchat', 'tiktok', 'meta', 'google', 'linkedin', 'x', 'openai_ads'], array_map(static fn (string $x): array => [$x], $p));
    }

    #[DataProvider('providers')]
    public function test_321_accounts_with_two_selected_queue_exactly_two_jobs_and_send_nothing(string $provider): void
    {
        Queue::fake();
        Http::fake();

        $accounts = $this->accounts($this->connection($provider, $this->tenant), 321);
        $project = $this->project('P', $this->workspace('W'));
        $this->bind($accounts[7], $project);
        $this->bind($accounts[300], $project);

        $this->artisan('integrations:sync')->assertSuccessful();

        $pushed = Queue::pushed(SyncAccountMetricsJob::class)->map(fn ($job) => (string) $job->accountId)->sort()->values()->all();
        $this->assertSame(collect([$accounts[7]->id, $accounts[300]->id])->sort()->values()->all(), $pushed, "{$provider}: only the two selected accounts are queued");
        Http::assertNothingSent();
    }

    public function test_the_census_names_an_account_that_synced_without_being_assigned(): void
    {
        $accounts = $this->accounts($this->connection('snapchat', $this->tenant), 5);
        $project = $this->project('P', $this->workspace('W'));
        $this->bind($accounts[0], $project);

        foreach ([$accounts[0], $accounts[3]] as $a) {
            DB::table('metric_sync_runs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
                'external_account_id' => $a->id, 'provider' => 'snapchat', 'status' => 'success',
                'window_start' => now()->subDays(7)->toDateString(), 'window_end' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('integrations:sync-census', ['--hours' => 24])
            ->expectsOutputToContain('SYNCED BUT NOT ASSIGNED')
            ->expectsOutputToContain('account '.substr((string) $accounts[3]->id, 0, 8))
            ->assertSuccessful();
    }

    public function test_the_census_is_clean_when_only_assigned_accounts_synced(): void
    {
        $accounts = $this->accounts($this->connection('snapchat', $this->tenant), 5);
        $project = $this->project('P', $this->workspace('W'));
        $this->bind($accounts[0], $project);
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $accounts[0]->id, 'provider' => 'snapchat', 'status' => 'success',
            'window_start' => now()->subDays(7)->toDateString(), 'window_end' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('integrations:sync-census')
            ->expectsOutputToContain('Every account that synced in the window is actively assigned')
            ->assertSuccessful();
    }

    private function connection(string $provider, Tenant $tenant): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    /** @return list<ExternalAccount> */
    private function accounts(ProviderConnection $connection, int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->id, 'provider' => $connection->provider,
                'account_type' => 'ad_account', 'external_id' => "{$connection->provider}-{$connection->id}-{$i}", 'name' => "Account {$i}",
                'status' => 'active', 'discovered_at' => Carbon::now(),
            ]);
        }

        return $rows;
    }

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace, string $status = 'active'): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => $status,
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $syncEnabled = true): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $project->tenant_id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $account->provider, 'purpose' => 'advertising',
            'is_active' => true, 'sync_enabled' => $syncEnabled, 'campaign_management_enabled' => true,
        ]);
    }
}
