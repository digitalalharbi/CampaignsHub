<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PERF-INTEGRATIONS-001 — opening the integrations center costs the same however many accounts a
 * connection discovered.
 *
 * Production, 2026-11-01: a connection with 565 discovered accounts and ten selected cost about 1,700
 * queries each time the wizard, the hub or the connection list was read, because every discovered
 * account was loaded and asked three questions in order to be counted and discarded. The answer must
 * not change — only what it costs.
 */
final class IntegrationsQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProviderConnection $connection;

    /** @var list<ExternalAccount> */
    private array $selected = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'snapchat',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()), connectionName: 'snapchat',
        );

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        $now = Carbon::now();
        $rows = [];

        for ($i = 0; $i < 565; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id,
                'provider' => 'snapchat', 'account_type' => 'ad_account', 'external_id' => "acct-{$i}", 'name' => "Account {$i}",
                'status' => 'active', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'parent_external_id' => 'org-1', 'parent_name' => 'Org',
                'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('external_accounts')->insert($rows);

        // Ten selected, in every state the health words distinguish.
        foreach (array_slice($rows, 0, 10) as $n => $row) {
            $account = ExternalAccount::withoutGlobalScopes()->findOrFail($row['id']);

            $account->forceFill(match (true) {
                $n < 3 => ['last_synced_at' => $now->copy()->subHour()],
                $n < 5 => ['last_synced_at' => $now->copy()->subDays(5)],
                $n < 6 => ['last_synced_at' => $now, 'last_sync_error_category' => 'rate_limited'],
                $n < 7 => ['access_lost_at' => $now],
                default => [],
            })->save();

            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
                'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => true,
            ]);

            DB::table('metric_sync_runs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
                'provider' => 'snapchat', 'status' => $n === 0 ? 'no_data' : 'success', 'window_start' => '2026-10-25', 'window_end' => '2026-11-01',
                'metrics_upserted' => $n === 0 ? 0 : 12, 'started_at' => $now->copy()->subMinutes(5), 'created_at' => $now, 'updated_at' => $now,
            ]);

            $this->selected[] = $account;
        }
    }

    public function test_the_health_summary_costs_a_handful_of_queries_whatever_the_number_of_discovered_accounts(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $summary = app(AccountHealth::class)->summarise((string) $this->connection->id);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queries, "summarise() ran {$queries} queries for 565 discovered accounts");
        $this->assertSame(10, $summary['connected']);
    }

    /** The cheaper path must say exactly what the per-account path says. */
    public function test_the_summary_is_the_sum_of_the_per_account_answers(): void
    {
        $health = app(AccountHealth::class);
        $expected = [];

        foreach (ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $this->connection->id)->get() as $account) {
            $state = $health->for($account);

            if ($state !== AccountHealth::NOT_CONNECTED) {
                $expected[$state] = ($expected[$state] ?? 0) + 1;
            }
        }

        $summary = $health->summarise((string) $this->connection->id);

        ksort($expected);
        $actual = $summary['states'];
        ksort($actual);

        $this->assertSame($expected, $actual);
        $this->assertSame(array_sum($expected), $summary['connected']);
    }

    public function test_opening_the_connection_state_does_not_grow_with_the_discovered_accounts(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $state = app(ConnectionWizardState::class)->for($this->connection->fresh());

        $log = DB::getQueryLog();
        $queries = count($log);
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $queries, "the connection state ran {$queries} queries for 565 discovered accounts");
        $this->assertSame(565, $state['discovered']);
        $this->assertSame(10, $state['assigned']);
    }

    /**
     * Re-reading 565 accounts the connection already holds writes nothing per account, creates no
     * binding and queues no job — and a changed name is the only thing that costs a row of its own.
     */
    public function test_a_refresh_of_565_known_accounts_costs_a_handful_of_queries_and_creates_nothing(): void
    {
        Queue::fake();

        $this->providerLists(ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $this->connection->id)
            ->orderBy('external_id')->get()->map(fn (ExternalAccount $a): array => ['id' => $a->external_id, 'name' => $a->name])->all());

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()->count();
        $accountsBefore = ExternalAccount::withoutGlobalScopes()->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(AccountDiscovery::class)->refresh($this->connection->fresh());
        $log = DB::getQueryLog();
        $queries = count($log);
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $queries, "refresh() ran {$queries} queries for 565 known accounts");
        $this->assertSame(565, $result['discovered']);
        $this->assertSame(0, $result['created']);
        $this->assertSame($accountsBefore, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame($bindings, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();

        // One account renamed and one new: exactly those change.
        $listed = $this->listed;
        $renamed = $listed[3]['id'];
        $listed[3]['name'] = 'Renamed';
        $listed[] = ['id' => 'acct-new', 'name' => 'New'];
        $this->providerLists($listed);

        $result = app(AccountDiscovery::class)->refresh($this->connection->fresh());

        $this->assertSame(1, $result['created']);
        $this->assertSame($accountsBefore + 1, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame('Renamed', ExternalAccount::withoutGlobalScopes()->where('external_id', $renamed)->value('name'));

        $newId = ExternalAccount::withoutGlobalScopes()->where('external_id', 'acct-new')->value('id');
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $newId)->count(), 'a newly discovered account was selected');
        Queue::assertNothingPushed();
    }

    /** @var list<array{id:string,name:string}> */
    private array $listed = [];

    private bool $faked = false;

    /**
     * Snapchat's own answer, read per call (`Http::fake()` keeps the FIRST stub registered, so a second
     * registration would silently go on answering with the first list).
     *
     * @param  list<array{id:string,name:string}>  $accounts
     */
    private function providerLists(array $accounts): void
    {
        $this->listed = $accounts;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake(['adsapi.snapchat.com/*me/organizations*' => fn () => Http::response(['organizations' => [[
            'organization' => [
                'id' => 'org-1', 'name' => 'Org',
                'ad_accounts' => array_map(static fn (array $a): array => [
                    'id' => $a['id'], 'name' => $a['name'], 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'ACTIVE',
                ], $this->listed),
            ],
        ]]])]);
    }
}
