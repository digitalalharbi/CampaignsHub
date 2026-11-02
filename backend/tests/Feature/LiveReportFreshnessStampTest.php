<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-FRESHNESS-STAMP-001 — a live report states WHEN each platform was last confirmed by a finished sync.
 *
 * Triggered by a client questioning Snapchat spend on a public link: «live» says this system recomputed, not that
 * the platform delivered. The stamp is the completion time of the latest SUCCESSFUL run, scoped to the link's own
 * project and still-bound accounts, and a failed latest attempt over an old success is never shown as fresh.
 */
final class LiveReportFreshnessStampTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Project $other;

    private ClientWorkspace $workspace;

    private ProviderConnection $connection;

    private UnifiedCampaign $campaign;

    private Report $report;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-02 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'fresh-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = $this->makeProject('P1');
        $this->other = $this->makeProject('P2');
        app(ProjectContext::class)->setProjectId($this->project->id);

        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();
        $this->connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => 'Camp', 'status' => 'active']);
        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => ['kpis' => ['spend' => 1]],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProject(string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function account(string $externalId, ?Project $bindTo = null, bool $active = true): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
        if ($bindTo !== null) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $bindTo->id,
                'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => $active,
            ]);
        }

        return $account;
    }

    /** One sync run row; `$finished`/`$started` are minutes before "now". */
    private function syncRun(Project $project, ?ExternalAccount $account, string $status, ?int $finishedAgo, ?int $startedAgo = null, string $provider = 'snapchat'): void
    {
        $at = fn (?int $m) => $m === null ? null : $this->now->copy()->subMinutes($m);
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account?->id, 'provider' => $provider, 'status' => $status,
            'window_start' => '2026-11-01', 'window_end' => '2026-11-01',
            'started_at' => $at($startedAgo ?? $finishedAgo), 'finished_at' => $at($finishedAgo),
            'created_at' => $at($startedAgo ?? $finishedAgo), 'updated_at' => $at($finishedAgo),
        ]);
    }

    /** @param list<string> $providers @param list<string> $accounts */
    private function payload(array $providers = ['snapchat'], array $accounts = []): array
    {
        $scope = [
            'project_id' => $this->project->id, 'campaign_ids' => [$this->campaign->id],
            'providers' => $providers, 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
        ];
        if ($accounts !== []) {
            $scope['account_ids'] = $accounts;
        }
        [, $raw] = app(ShareService::class)->create($this->report, ['scope' => $scope], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    private function entry(array $payload, string $provider = 'snapchat'): array
    {
        return collect($payload['freshness'])->firstWhere('provider', $provider);
    }

    public function test_the_stamp_is_the_completion_time_not_the_start_time(): void
    {
        $account = $this->account('a1', $this->project);
        // Started 50 minutes ago, finished 10 minutes ago.
        $this->syncRun($this->project, $account, 'success', finishedAgo: 10, startedAgo: 50);

        $e = $this->entry($this->payload());

        $this->assertSame($this->now->copy()->subMinutes(10)->toIso8601ZuluString(), $e['last_success_at']);
        $this->assertSame(10, $e['age_minutes']);
        $this->assertSame('fresh', $e['data_status']);
        $this->assertSame('success', $e['last_attempt_status']);
        $this->assertSame('synced', $e['state'], 'existing keys are kept');
    }

    public function test_the_summary_carries_the_riyadh_clock_and_the_worst_platform(): void
    {
        $a = $this->account('a1', $this->project);
        $this->syncRun($this->project, $a, 'success', 10);
        $this->syncRun($this->project, $a, 'success', 300, provider: 'meta');

        $p = $this->payload(['snapchat', 'meta']);

        $this->assertSame('Asia/Riyadh', $p['freshness_summary']['timezone']);
        $this->assertSame('delayed', $p['freshness_summary']['worst_status']);
        $this->assertSame($this->now->copy()->subMinutes(300)->toIso8601ZuluString(), $p['freshness_summary']['oldest_success_at']);
        $this->assertSame($this->now->toIso8601ZuluString(), $p['freshness_summary']['generated_at']);
    }

    public function test_another_projects_newer_success_does_not_make_this_project_look_fresh(): void
    {
        $mine = $this->account('mine', $this->project);
        $theirs = $this->account('theirs', $this->other);
        $this->syncRun($this->project, $mine, 'success', 3000);
        $this->syncRun($this->other, $theirs, 'success', 2);
        $this->syncRun($this->other, null, 'success', 1);

        $e = $this->entry($this->payload());

        $this->assertSame('stale', $e['data_status']);
        $this->assertSame($this->now->copy()->subMinutes(3000)->toIso8601ZuluString(), $e['last_success_at']);
    }

    public function test_an_unselected_accounts_run_is_ignored(): void
    {
        $selected = $this->account('sel', $this->project);
        $deselected = $this->account('desel', $this->project, active: false);
        $this->syncRun($this->project, $selected, 'success', 600);
        $this->syncRun($this->project, $deselected, 'success', 1);

        $e = $this->entry($this->payload());

        $this->assertSame('delayed', $e['data_status']);
        $this->assertSame(600, $e['age_minutes']);
    }

    public function test_a_link_narrowed_to_one_account_reads_only_that_accounts_runs(): void
    {
        $one = $this->account('one', $this->project);
        $two = $this->account('two', $this->project);
        $this->syncRun($this->project, $one, 'success', 600);
        $this->syncRun($this->project, $two, 'success', 5);

        $e = $this->entry($this->payload(accounts: [$one->id]));

        $this->assertSame(600, $e['age_minutes']);
        $this->assertSame('delayed', $e['data_status']);
    }

    public function test_a_failed_latest_attempt_over_an_old_success_is_failed(): void
    {
        $a = $this->account('a1', $this->project);
        $this->syncRun($this->project, $a, 'success', 400);
        $this->syncRun($this->project, $a, 'failed', 20);

        $e = $this->entry($this->payload());

        $this->assertSame('failed', $e['data_status']);
        $this->assertSame('failed', $e['last_attempt_status']);
        $this->assertSame($this->now->copy()->subMinutes(400)->toIso8601ZuluString(), $e['last_success_at']);
        $this->assertSame($this->now->copy()->subMinutes(20)->toIso8601ZuluString(), $e['last_attempt_at']);
    }

    public function test_a_failure_right_after_a_fresh_success_is_still_fresh(): void
    {
        $a = $this->account('a1', $this->project);
        $this->syncRun($this->project, $a, 'success', 30);
        $this->syncRun($this->project, $a, 'failed', 5);

        $this->assertSame('fresh', $this->entry($this->payload())['data_status']);
    }

    public function test_a_later_success_clears_an_earlier_failure(): void
    {
        $a = $this->account('a1', $this->project);
        $this->syncRun($this->project, $a, 'failed', 500);
        $this->syncRun($this->project, $a, 'success', 100);

        $e = $this->entry($this->payload());
        $this->assertSame('delayed', $e['data_status']);
        $this->assertSame('success', $e['last_attempt_status']);
    }

    public function test_a_running_attempt_is_reported_but_is_not_a_confirmation(): void
    {
        $a = $this->account('a1', $this->project);
        $this->syncRun($this->project, $a, 'success', 2000);
        $this->syncRun($this->project, $a, 'running', null, startedAgo: 3);

        $e = $this->entry($this->payload());

        $this->assertSame('running', $e['last_attempt_status']);
        $this->assertSame('stale', $e['data_status']);
    }

    public function test_no_run_at_all_is_unknown_never_fresh(): void
    {
        $this->account('a1', $this->project);

        $e = $this->entry($this->payload());

        $this->assertSame('unknown', $e['data_status']);
        $this->assertNull($e['last_success_at']);
        $this->assertNull($e['age_minutes']);
        $this->assertSame('none', $e['last_attempt_status']);
    }

    public function test_the_payload_does_not_leak_account_identifiers(): void
    {
        $a = $this->account('leaky-external-id', $this->project);
        $this->syncRun($this->project, $a, 'success', 10);

        $json = json_encode($this->payload()['freshness']);

        $this->assertStringNotContainsString('leaky-external-id', $json);
        $this->assertStringNotContainsString($a->id, $json);
    }

    public function test_the_number_of_freshness_statements_does_not_grow_with_providers(): void
    {
        $a = $this->account('a1', $this->project);
        $this->syncRun($this->project, $a, 'success', 10);

        $count = function (array $providers): int {
            $n = 0;
            DB::listen(function ($q) use (&$n): void {
                if (str_contains($q->sql, 'metric_sync_runs')) {
                    $n++;
                }
            });
            $this->payload($providers);

            return $n;
        };

        $one = $count(['snapchat']);
        $four = $count(['snapchat', 'meta', 'tiktok', 'linkedin']);

        $this->assertSame($one, $four, 'metric_sync_runs statements grew with the number of providers');
    }
}
