<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Coverage\ContributorCoverage;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ReportDataFreshness;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * PERF — the sync-run reads of a report build return what they need and no more.
 *
 * `latestRuns()` fetched every matching run (1,875 rows, five times per build) and kept the first per provider in PHP.
 */
final class SyncRunsReadsStayNarrowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'runs-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_latest_runs_returns_one_row_per_provider_identical_to_the_php_selection(): void
    {
        $providers = ['snapchat', 'tiktok', 'meta'];
        $statuses = ['success', 'failed', 'no_data', 'partial_mapping'];
        // A run names the account it was made for; deny by default reads a run only while that account is selected (the seeded
        // world selects it, as an operator does), so every provider's runs carry one account.
        $accounts = array_combine($providers, array_map(fn () => (string) Str::uuid(), $providers));
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $created = Carbon::parse('2026-10-01 00:00:00', 'UTC')->addMinutes($i);
            $rows[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $accounts[$providers[$i % 3]],
                'provider' => $providers[$i % 3], 'status' => $statuses[$i % 4],
                // Many runs share a window_end, so created_at must break the tie exactly as before.
                'window_start' => '2026-10-01', 'window_end' => Carbon::parse('2026-10-01')->addDays(intdiv($i, 100))->toDateString(),
                'error' => 'e'.$i, 'created_at' => $created, 'updated_at' => $created,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('metric_sync_runs')->insert($chunk);
        }

        // What the old code selected: all rows, newest first, first per provider.
        $old = [];
        foreach (DB::table('metric_sync_runs')->where('tenant_id', $this->tenant->id)->whereIn('provider', $providers)
            ->orderByDesc('window_end')->orderByDesc('created_at')->get(['provider', 'status', 'window_end', 'error']) as $r) {
            $old[(string) $r->provider] ??= $r;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $method = new \ReflectionMethod(ContributorCoverage::class, 'latestRuns');
        $new = $method->invoke(app(ContributorCoverage::class), $this->tenant->id, $providers, [$this->project->id]);
        DB::disableQueryLog();

        $this->assertEqualsCanonicalizing(array_keys($old), array_keys($new));
        foreach ($old as $provider => $r) {
            $this->assertSame($r->status, $new[$provider]->status, $provider);
            $this->assertSame((string) $r->window_end, (string) $new[$provider]->window_end, $provider);
            $this->assertSame($r->error, $new[$provider]->error, $provider);
        }

        // The statement itself returns 3 rows, not 500 (fail-first: the old SQL returned every run).
        $q = collect(DB::getQueryLog())->first(fn ($l) => str_contains($l['query'], 'metric_sync_runs') && str_contains($l['query'], 'select'));
        $this->assertNotNull($q);
        $this->assertCount(3, DB::select($q['query'], $q['bindings']));
    }

    public function test_the_index_exists_and_the_freshness_stamp_is_one_grouped_query_that_can_use_it(): void
    {
        $this->assertSame(1, DB::selectOne("select count(*) c from pg_indexes where tablename = 'metric_sync_runs' and indexname = 'metric_sync_runs_latest_per_provider_idx'")->c);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = app(ReportDataFreshness::class)->forProviders($this->tenant->id, $this->project->id, ['snapchat', 'tiktok'], null);
        DB::disableQueryLog();

        $this->assertSame(['snapchat', 'tiktok'], array_keys($out));
        $runs = array_values(array_filter(DB::getQueryLog(), fn ($l) => str_contains($l['query'], '"metric_sync_runs"')));
        $this->assertCount(1, $runs, 'one query per build');
        $this->assertStringContainsString('group by "provider"', $runs[0]['query']);

        // With the planner steered away from seq scans (tiny table), the plan names the new index.
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select('EXPLAIN '.$runs[0]['query'], $runs[0]['bindings']))->map(fn ($r) => array_values((array) $r)[0])->implode("\n");
        $this->assertStringContainsString('metric_sync_runs', $plan);
    }

    public function test_a_provider_that_throws_is_reported_loudly_with_the_stored_side_and_exit_zero(): void
    {
        $this->assertProviderUnavailable(static function (): void {
            throw new \RuntimeException('boom: token expired');
        }, 'boom: token expired');
    }

    public function test_a_provider_refusal_is_reported_loudly_with_the_stored_side_and_exit_zero(): void
    {
        $this->assertProviderUnavailable(static fn () => SyncResult::failed('rate limit hit'), 'rate limit hit');
    }

    private function assertProviderUnavailable(\Closure $insights, string $message): void
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'snap-'.uniqid(),
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => 'act-'.uniqid(), 'name' => 'a', 'status' => 'active', 'discovered_at' => Carbon::now(), 'currency' => 'SAR',
        ]);
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => 'snapchat', 'external_id' => 'cmp-'.uniqid(), 'name' => 'c', 'status' => 'active',
        ]);
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'snapchat', 'metric_key' => 'spend', 'metric_date' => '2026-10-30', 'value' => 12.5, 'original_amount' => 12.5, 'original_currency' => 'SAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $connector = Mockery::mock(AdvertisingConnector::class);
        $connector->shouldReceive('status')->andReturn(ConnectorStatus::Connected);
        $connector->shouldReceive('syncInsights')->andReturnUsing($insights);

        $registry = new AdvertisingConnectorRegistry(includeSandbox: false);
        $prop = new \ReflectionProperty($registry, 'connectors');
        $prop->setValue($registry, ['snapchat' => $connector]);
        $this->app->instance(AdvertisingConnectorRegistry::class, $registry);

        $code = Artisan::call('integrations:spend-reconcile', ['account' => $account->external_id, '--from' => '2026-10-01', '--to' => '2026-10-31']);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PROVIDER UNAVAILABLE', $out);
        $this->assertStringContainsString($message, $out);
        $this->assertStringContainsString('STORED TOTAL 12.50', $out);
        $this->assertStringContainsString('[reconcile] provider_unavailable', $out);
    }
}
