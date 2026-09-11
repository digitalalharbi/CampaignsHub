<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OPS-LEDGER-001 — the pipeline summary counted the hundred runs it fetched.
 *
 * `SyncRunController::index()` caps at a hundred runs and derived `summary` — the per-status counts an
 * operator reads to judge whether the pipeline is healthy — from that capped collection. A workspace
 * with four hundred runs in the window was told about a hundred of them, and «12 failed» meant
 * «12 failed among the most recent hundred», which is a different and much more comforting claim.
 *
 * The cap stays: an unbounded run log is what it prevents. What changes is that the summary describes
 * the whole filtered set, and the response says how many runs it is not showing.
 */
final class SyncRunLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
        $this->user->assignRole($role);
    }

    /** Named `syncRun`, not `run` — PHPUnit's `TestCase::run()` is final. */
    private function syncRun(string $status, int $minutesAgo): void
    {
        MetricSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => null, 'provider' => 'meta', 'status' => $status,
            'window_start' => '2026-08-01', 'window_end' => '2026-08-30',
            'started_at' => now()->subMinutes($minutesAgo), 'finished_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /** @return array<string, mixed> */
    private function runs(): array
    {
        return (array) $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/sync-runs")
            ->assertOk()->json('data');
    }

    /**
     * The failures are OLDEST here, so a hundred-row cap hides every one of them.
     *
     * «0 failed» over a pipeline that failed forty times is the most comforting possible way to be
     * wrong about whether the data is trustworthy.
     */
    public function test_the_summary_counts_every_run_not_the_hundred_it_fetched(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->syncRun('failed', 1_000 + $i);
        }
        for ($i = 0; $i < 150; $i++) {
            $this->syncRun('success', $i);
        }

        $data = $this->runs();

        $this->assertSame(40, (int) ($data['summary']['failed'] ?? 0), 'every failure is counted, not only the recent ones');
        $this->assertSame(150, (int) ($data['summary']['success'] ?? 0));
    }

    /** And the response says how many it is not showing, rather than leaving it to be inferred. */
    public function test_it_states_the_total_and_what_the_cap_left_out(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->syncRun('success', $i);
        }

        $data = $this->runs();

        $this->assertSame(130, (int) $data['runs_total']);
        $this->assertSame(30, (int) $data['runs_withheld']);
    }

    /** Within the cap, nothing is withheld and the page says so. */
    public function test_a_small_log_withholds_nothing(): void
    {
        $this->syncRun('success', 1);

        $data = $this->runs();

        $this->assertSame(1, (int) $data['runs_total']);
        $this->assertSame(0, (int) $data['runs_withheld']);
    }

    /**
     * The Integration Centre's per-account log had TWO stacked caps and neither said so.
     *
     * Two hundred runs are read, collapsed, then cut to fifty. An account synced every half hour
     * passes two hundred runs in four days, so «what has this account been doing» showed a few days
     * of it, indistinguishable from an account with a short history.
     */
    public function test_the_account_log_states_how_many_runs_exist(): void
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider' => 'meta',
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act-1', 'name' => 'Acct', 'status' => 'active',
        ]);

        for ($i = 0; $i < 260; $i++) {
            MetricSyncRun::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $account->id, 'provider' => 'meta',
                'status' => $i % 2 === 0 ? 'success' : 'failed',
                'window_start' => '2026-08-01', 'window_end' => '2026-08-30',
                'started_at' => now()->subMinutes($i), 'finished_at' => now()->subMinutes($i),
            ]);
        }

        $data = (array) $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/accounts/{$account->id}/logs")
            ->assertOk()->json('data');

        $this->assertSame(260, (int) $data['runs_total'], 'the total counts RUNS, not collapsed rows');
        $this->assertGreaterThan(0, (int) $data['runs_withheld']);
        $this->assertLessThanOrEqual(50, count($data['runs']));
    }
}
