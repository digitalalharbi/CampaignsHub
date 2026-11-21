<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Coverage\ContributionState;
use App\Domains\Metrics\Coverage\ContributorCoverage;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ReportDataFreshness;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PERF — proof that `latestRuns()` choosing one row per provider in SQL (DISTINCT ON) keeps the freshness and failure
 * states of the SELECTED accounts, providers and projects exactly as the old fetch-all-and-pick-in-PHP selection did.
 *
 * The reference is computed here in PHP from the raw rows: visibility is derived from the fixture's own bindings (not by
 * calling BoundAccountVisibility), then ORDER BY window_end DESC, created_at DESC, first per provider.
 */
final class LatestRunsPerSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private Project $p1;

    private Project $p2;

    private Project $q1;

    private Carbon $now;

    private ExternalAccount $snapA;

    private ExternalAccount $snapB;

    private ExternalAccount $snapDeselected;

    private ExternalAccount $tikA;

    /** @var array<string, ExternalAccount> */
    private array $others = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-02 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 'lrps-'.uniqid(), 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'T2', 'slug' => 'lrps2-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->p1 = $this->project($this->tenant, 'P1');
        $this->p2 = $this->project($this->tenant, 'P2');
        $this->q1 = $this->project($this->otherTenant, 'Q1');

        $this->snapA = $this->account($this->tenant, 'snapchat', $this->p1);
        $this->snapB = $this->account($this->tenant, 'snapchat', $this->p1);
        $this->snapDeselected = $this->account($this->tenant, 'snapchat', $this->p1, active: false);
        $this->tikA = $this->account($this->tenant, 'tiktok', $this->p1);
        $this->others['p2snap'] = $this->account($this->tenant, 'snapchat', $this->p2);
        $this->others['t2snap'] = $this->account($this->otherTenant, 'snapchat', $this->q1);

        // P1 expects both providers (coverage only judges providers that have a live campaign).
        foreach (['snapchat' => $this->snapA, 'tiktok' => $this->tikA] as $provider => $acct) {
            ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->p1->id, 'external_account_id' => $acct->id,
                'provider' => $provider, 'external_id' => 'cmp-'.uniqid(), 'name' => 'c', 'status' => 'active',
            ]);
        }

        // --- snapchat, P1, account A: older success, then a NEWER failure on the very same window_end (created_at breaks the tie).
        $this->syncRun($this->p1, $this->snapA, 'snapchat', 'success', '2026-10-28', createdAgo: 900, finishedAgo: 899);
        $this->syncRun($this->p1, $this->snapA, 'snapchat', 'success', '2026-10-29', createdAgo: 601, finishedAgo: 600);
        $this->syncRun($this->p1, $this->snapA, 'snapchat', 'failed', '2026-10-29', createdAgo: 31, finishedAgo: 30, error: 'A-boom');
        // --- snapchat, P1, account B: the newest window among the SELECTED accounts, a fresh success.
        $this->syncRun($this->p1, $this->snapB, 'snapchat', 'success', '2026-10-31', createdAgo: 6, finishedAgo: 5);
        // --- a run with no account at all (visible by the unbound rule), old.
        $this->syncRun($this->p1, null, 'snapchat', 'success', '2026-10-01', createdAgo: 5000, finishedAgo: 4999);
        // --- DESELECTED account of P1: the newest of everything. Must never be the provider's latest.
        $this->syncRun($this->p1, $this->snapDeselected, 'snapchat', 'success', '2026-11-01', createdAgo: 2, finishedAgo: 1);
        // --- another PROJECT of the same tenant, newer and failing; another TENANT, newer and failing.
        $this->syncRun($this->p2, $this->others['p2snap'], 'snapchat', 'failed', '2026-11-01', createdAgo: 3, finishedAgo: 2, error: 'P2-SECRET');
        $this->syncRun($this->q1, $this->others['t2snap'], 'snapchat', 'failed', '2026-11-01', createdAgo: 3, finishedAgo: 2, error: 'T2-SECRET');

        // --- tiktok, P1: success, then success+failed on the same window_end (failed created later), failed is the latest.
        $this->syncRun($this->p1, $this->tikA, 'tiktok', 'success', '2026-10-25', createdAgo: 4000, finishedAgo: 3999);
        $this->syncRun($this->p1, $this->tikA, 'tiktok', 'success', '2026-10-30', createdAgo: 400, finishedAgo: 399);
        $this->syncRun($this->p1, $this->tikA, 'tiktok', 'failed', '2026-10-30', createdAgo: 100, finishedAgo: 99, error: 'tik-boom');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The OLD selection, from the raw rows: visible per the fixture's bindings, newest window then newest creation, first per provider. */
    private function reference(string $tenantId, ?array $projectIds, array $providers): array
    {
        $bindings = DB::table('project_integration_bindings')->get();
        $visible = function (object $run) use ($bindings): bool {
            if ($run->external_account_id === null) {
                return true;
            }
            $mine = $bindings->where('external_account_id', $run->external_account_id);
            if ($mine->isEmpty()) {
                return true;
            }

            return $mine->where('project_id', $run->project_id)->where('is_active', true)->isNotEmpty();
        };

        $rows = DB::table('metric_sync_runs')->where('tenant_id', $tenantId)->get()
            ->filter(fn ($r) => in_array($r->provider, $providers, true)
                && ($projectIds === null || in_array($r->project_id, $projectIds, true))
                && $visible($r))
            ->sort(fn ($a, $b) => [$b->window_end, $b->created_at] <=> [$a->window_end, $a->created_at])
            ->values();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->provider] ??= $r;
        }

        return $out;
    }

    private function latest(string $tenantId, array $providers, ?array $projectIds): array
    {
        $m = new \ReflectionMethod(ContributorCoverage::class, 'latestRuns');

        return $m->invoke(app(ContributorCoverage::class), $tenantId, $providers, $projectIds);
    }

    public function test_sql_selection_equals_the_php_reference_for_every_scope(): void
    {
        $scopes = [
            'p1' => [$this->tenant->id, [$this->p1->id]],
            'p2' => [$this->tenant->id, [$this->p2->id]],
            'p1+p2' => [$this->tenant->id, [$this->p1->id, $this->p2->id]],
            'whole tenant' => [$this->tenant->id, null],
            'other tenant' => [$this->otherTenant->id, [$this->q1->id]],
            'other tenant, wrong project' => [$this->otherTenant->id, [$this->p1->id]],
        ];
        foreach ($scopes as $label => [$tenantId, $projects]) {
            foreach ([['snapchat'], ['tiktok'], ['snapchat', 'tiktok']] as $providers) {
                $ref = $this->reference($tenantId, $projects, $providers);
                $got = $this->latest($tenantId, $providers, $projects);
                $this->assertEqualsCanonicalizing(array_keys($ref), array_keys($got), "$label ".implode(',', $providers));
                foreach ($ref as $provider => $r) {
                    $this->assertSame($r->status, $got[$provider]->status, "$label $provider status");
                    $this->assertSame((string) $r->window_end, (string) $got[$provider]->window_end, "$label $provider window_end");
                    $this->assertSame($r->error, $got[$provider]->error, "$label $provider error");
                }
            }
        }
    }

    public function test_coverage_reads_the_selected_accounts_not_the_deselected_nor_other_projects_or_tenants(): void
    {
        $cov = app(ContributorCoverage::class);
        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-11-01');

        foreach ([
            'forWindow' => $cov->forWindow($this->tenant->id, $this->p1->id, $from, $to),
            'forDays' => $cov->forDays($this->tenant->id, $this->p1->id, $to, $to)['2026-11-01'],
        ] as $path => $c) {
            // Case 1+2: B (selected) has the newest window, through 10-31. The deselected account's 11-01 would read as covered.
            $this->assertSame(ContributionState::Stale, $c->contributors['snapchat'], $path);
            $this->assertStringContainsString('2026-10-31', $c->reasons['snapchat'], "$path: B's run, not A's 10-29 and not the deselected 11-01");
            // Case 3: neither P2's nor T2's failure leaks.
            $this->assertStringNotContainsString('SECRET', (string) json_encode($c->toArray()), $path);
            // Case 4: failed latest over an older success -> Failed with its error text.
            $this->assertSame(ContributionState::Failed, $c->contributors['tiktok'], $path);
            $this->assertStringContainsString('tik-boom', $c->reasons['tiktok'], $path);
        }

        // The other project's own read sees its own failure, the other tenant sees only its own.
        $c2 = $cov->forWindow($this->tenant->id, $this->p2->id, $from, $to);
        $this->assertSame([], $c2->contributors, 'P2 has no campaign, so nothing is expected and no foreign run is named');
    }

    public function test_the_deselected_account_is_not_the_latest_until_it_is_selected_again(): void
    {
        $got = $this->latest($this->tenant->id, ['snapchat'], [$this->p1->id]);
        $this->assertSame('2026-10-31', substr((string) $got['snapchat']->window_end, 0, 10));

        DB::table('project_integration_bindings')->where('external_account_id', $this->snapDeselected->id)->update(['is_active' => true]);
        $got = $this->latest($this->tenant->id, ['snapchat'], [$this->p1->id]);
        $this->assertSame('2026-11-01', substr((string) $got['snapchat']->window_end, 0, 10), 're-selected history reappears');
        $this->assertSame($this->reference($this->tenant->id, [$this->p1->id], ['snapchat'])['snapchat']->window_end, $got['snapchat']->window_end);
    }

    public function test_freshness_is_per_narrowed_account_and_the_unnarrowed_link_reads_the_selected_set(): void
    {
        $f = app(ReportDataFreshness::class);
        $at = fn (int $m): string => $this->now->copy()->subMinutes($m)->toIso8601ZuluString();

        // Narrowed to A: A's own success was 600 min ago and its LATEST attempt failed, B's newer success is irrelevant.
        $a = $f->forProviders($this->tenant->id, $this->p1->id, ['snapchat'], [$this->snapA->id])['snapchat'];
        $this->assertSame($at(600), $a['last_success_at']);
        $this->assertSame('failed', $a['data_status']);
        $this->assertSame('failed', $a['last_attempt_status']);

        // Narrowed to B: only B's runs.
        $b = $f->forProviders($this->tenant->id, $this->p1->id, ['snapchat'], [$this->snapB->id])['snapchat'];
        $this->assertSame($at(5), $b['last_success_at']);
        $this->assertSame('fresh', $b['data_status']);

        // Un-narrowed: the selected set (A, B, unaccounted run) -> B's success is the provider's latest; the deselected account's 1-minute-old success is not.
        $all = $f->forProviders($this->tenant->id, $this->p1->id, ['snapchat'], null)['snapchat'];
        $this->assertSame($at(5), $all['last_success_at']);
        $this->assertSame('fresh', $all['data_status']);

        // Narrowing to the deselected account alone yields nothing: its runs are not this project's.
        $d = $f->forProviders($this->tenant->id, $this->p1->id, ['snapchat'], [$this->snapDeselected->id])['snapchat'];
        $this->assertNull($d['last_success_at']);
        $this->assertSame('unknown', $d['data_status']);

        // Project and tenant isolation: P2's and T2's runs never colour P1; P1's never colour P2.
        $p2 = $f->forProviders($this->tenant->id, $this->p2->id, ['snapchat'], null)['snapchat'];
        $this->assertSame('failed', $p2['data_status']);
        $this->assertNull($p2['last_success_at']);
        $t2 = $f->forProviders($this->otherTenant->id, $this->p1->id, ['snapchat'], null)['snapchat'];
        $this->assertSame('unknown', $t2['data_status']);

        // Failure semantics for tiktok: latest attempt failed over an older success.
        $t = $f->forProviders($this->tenant->id, $this->p1->id, ['tiktok'], null)['tiktok'];
        $this->assertSame($at(399), $t['last_success_at']);
        $this->assertSame('failed', $t['data_status']);
    }

    private function project(Tenant $tenant, string $name): Project
    {
        $ws = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);

        return Project::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => $name, 'status' => 'active']);
    }

    private function account(Tenant $tenant, string $provider, Project $bindTo, bool $active = true): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.'-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act-'.uniqid(), 'name' => 'a', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $bindTo->client_workspace_id, 'project_id' => $bindTo->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => $active,
        ]);

        return $account;
    }

    private function syncRun(Project $project, ?ExternalAccount $account, string $provider, string $status, string $windowEnd, int $createdAgo, int $finishedAgo, ?string $error = null): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'project_id' => $project->id,
            'external_account_id' => $account?->id, 'provider' => $provider, 'status' => $status,
            'window_start' => '2026-10-01', 'window_end' => $windowEnd, 'error' => $error,
            'started_at' => $this->now->copy()->subMinutes($createdAgo), 'finished_at' => $this->now->copy()->subMinutes($finishedAgo),
            'created_at' => $this->now->copy()->subMinutes($createdAgo), 'updated_at' => $this->now->copy()->subMinutes($finishedAgo),
        ]);
    }
}
