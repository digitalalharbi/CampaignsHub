<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — the whole chain, from the project and back to it.
 *
 *     project A → «ربط» → the platform (a key here) → the account → confirmed → sync
 *       → analytics, project counts and a generated report for A show it
 *       → project B and tenant B show nothing
 *       → unlink → new data stops for A, what was stored and reported stays
 *
 * Every surface on the way has its own tests. This one holds the CHAIN through the project journey's own
 * endpoints, with a provider that answers over HTTP, so that «the link works» cannot be true while the
 * account never reaches the dashboard — or reaches the wrong project's.
 *
 * It also holds the rule for a report's pickers: an account of ANOTHER project named in a scope is
 * refused, never quietly dropped — a dropped id reads to the sender as «accepted».
 */
final class ProjectLinkWholeChainTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ads-live-0000beef';

    private const WINDOW = 'from=2026-09-01&to=2026-09-30';

    private Tenant $tenant;

    private User $operator;

    private Project $a;

    private Project $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        [$this->tenant, $this->operator] = $this->agency('Agency');
        $this->a = $this->project($this->tenant, 'Project A');
        $this->b = $this->project($this->tenant, 'Project B');

        app(TenantContext::class)->forget();
    }

    public function test_an_account_linked_from_project_a_reaches_as_surfaces_and_nobody_elses_and_unlinking_stops_new_data(): void
    {
        Queue::fake();
        $this->providerReports(['date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250, 'conversions' => 12]);

        // ── project → «ربط» → key → account → confirmed ─────────────────────────────────────
        $account = $this->linkThroughTheJourney($this->a);
        $this->assertSame('acct_live_1', $account->external_id);
        $this->assertSame((string) $this->a->id, (string) ProviderConnection::withoutGlobalScopes()->findOrFail($account->provider_connection_id)->project_id);

        // ── sync ────────────────────────────────────────────────────────────────────────────
        $this->sync($account);

        // ── A shows it: analytics, the platform breakdown, the project's own counts ───────────
        $this->assertEqualsWithDelta(120.5, (float) ($this->read($this->a, 'metrics/summary?'.self::WINDOW)['current']['spend'] ?? 0), 0.001);
        $this->assertContains('openai_ads', array_column($this->read($this->a, 'metrics/platforms?'.self::WINDOW), 'provider'));

        $cards = collect($this->projects($this->operator, $this->tenant))->keyBy('name');
        $this->assertSame(1, $cards['Project A']['summary']['accounts']);
        $this->assertSame(['openai_ads'], $cards['Project A']['summary']['providers']);
        $this->assertGreaterThan(0, $cards['Project A']['summary']['campaigns']);

        // ── and a report generated for A carries the figure ────────────────────────────────────
        $report = $this->report($this->a, [(string) $account->id]);
        $this->assertEqualsWithDelta(120.5, (float) $report->data['kpis']['spend'], 0.001);

        // ── B shows nothing, by every route ───────────────────────────────────────────────────
        $this->assertSame(0.0, (float) ($this->read($this->b, 'metrics/summary?'.self::WINDOW)['current']['spend'] ?? 0));
        $this->assertSame([], $this->read($this->b, 'metrics/platforms?'.self::WINDOW));
        $this->assertSame(0, $cards['Project B']['summary']['accounts']);
        $this->assertSame([], $cards['Project B']['summary']['providers']);
        $this->assertSame(0, (int) ($cards['Project B']['summary']['campaigns'] ?? 0));
        $hubB = $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/v1/integrations/hub?project_id='.$this->b->id)->assertOk();
        $this->assertSame([], $hubB->json('data.connections'));
        $this->assertStringNotContainsString('Acme Riyadh', $hubB->getContent());

        // ── tenant B shows nothing, and cannot name A's project or account ────────────────────
        [$rival, $rivalOperator] = $this->agency('Rival');
        $theirs = $this->project($rival, 'Rival Project');
        app(TenantContext::class)->forget();
        $this->assertSame(['Rival Project'], array_column($this->projects($rivalOperator, $rival), 'name'));
        $rivalHub = $this->actingAs($rivalOperator, 'sanctum')->withHeader('X-Tenant-ID', $rival->id)
            ->getJson('/api/v1/integrations/hub')->assertOk();
        $this->assertSame([], $rivalHub->json('data.connections'));
        $this->actingAs($rivalOperator, 'sanctum')->withHeader('X-Tenant-ID', $rival->id)->withHeader('X-Project-Id', $this->a->id)
            ->getJson("/api/v1/projects/{$this->a->id}/metrics/summary?".self::WINDOW)->assertNotFound();
        $this->actingAs($rivalOperator, 'sanctum')->withHeader('X-Tenant-ID', $rival->id)
            ->getJson('/api/v1/integrations/hub?project_id='.$this->a->id)->assertNotFound();
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->where('project_id', $theirs->id)->count());

        // ── unlink: new data stops for A; what was stored and reported stays ───────────────────
        $stored = DailyMetric::withoutGlobalScopes()->where('project_id', $this->a->id)->count();
        $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)->withHeader('X-Project-Id', $this->a->id)
            ->postJson("/api/v1/projects/{$this->a->id}/integrations/accounts/{$account->id}/unlink")
            ->assertOk();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->a->id)->where('is_active', true)->count());

        // The provider reports a NEW day; a sync after the unlink files nothing more under A.
        $this->providerReports(['date' => '2026-09-02', 'spend' => 999, 'impressions' => 1, 'clicks' => 1]);
        $this->sync($account->refresh(), tolerateRefusal: true);
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->where('project_id', $this->a->id)->where('metric_date', '2026-09-02')->count());
        $this->assertSame($stored, DailyMetric::withoutGlobalScopes()->where('project_id', $this->a->id)->count(), 'history is kept, and nothing is added');

        // The stored report is exactly what it was.
        $this->assertEqualsWithDelta(120.5, (float) $report->refresh()->data['kpis']['spend'], 0.001);
        $this->assertSame('completed', $report->status);
    }

    /** A request that names another project's account is REFUSED by every report scope endpoint — not dropped. */
    public function test_a_report_scope_naming_another_projects_account_is_refused_not_dropped(): void
    {
        Queue::fake();
        $this->providerReports(['date' => '2026-09-01', 'spend' => 120.5, 'impressions' => 10000, 'clicks' => 250]);
        $mine = $this->linkThroughTheJourney($this->a);
        $this->sync($mine);

        // B has an account of its own, with figures, so «not in this project» is not «does not exist».
        $foreign = $this->foreignAccount($this->b);

        $as = fn () => $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)->withHeader('X-Project-Id', $this->a->id);
        $base = "/api/v1/projects/{$this->a->id}";

        // The picker's options list A's account and nothing of B's.
        $options = $as()->getJson("{$base}/reports/scope/options")->assertOk();
        $this->assertSame([(string) $mine->id], array_column($options->json('data.accounts'), 'id'));
        $this->assertStringNotContainsString((string) $foreign->id, $options->getContent());
        $this->assertStringNotContainsString('Beta Account', $options->getContent());

        // Creating a report: refused, and nothing stored.
        $as()->postJson("{$base}/reports", ['name' => 'Sneaky', 'type' => 'weekly', 'scope' => ['account_ids' => [(string) $mine->id, (string) $foreign->id]]])
            ->assertStatus(422);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertSame(0, Report::query()->where('name', 'Sneaky')->count());
        app(TenantContext::class)->forget();

        // The picker narrowed by B's account, the scope explanation and a saved template: refused, each of them.
        $as()->getJson("{$base}/reports/scope/options?account_ids[]=".$foreign->id)->assertStatus(422);
        $as()->postJson("{$base}/reports/scope/explain", ['scope' => ['account_ids' => [(string) $mine->id, (string) $foreign->id]]])->assertStatus(422);
        $as()->postJson("{$base}/reports/scope-templates", ['name' => 'T', 'scope' => ['account_ids' => [(string) $foreign->id]]])->assertStatus(422);

        // A's own account is accepted by the same routes.
        $as()->getJson("{$base}/reports/scope/options?account_ids[]=".$mine->id)->assertOk();
        $as()->postJson("{$base}/reports/scope/explain", ['scope' => ['account_ids' => [(string) $mine->id]]])->assertOk();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** The project's own journey over HTTP: paste the key for the project, list the accounts, confirm one. */
    private function linkThroughTheJourney(Project $project): ExternalAccount
    {
        $as = fn () => $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)->withHeader('X-Project-Id', $project->id);

        $connection = $as()->postJson('/api/v1/integrations/openai_ads/api-key/connect', ['api_key' => self::KEY, 'project_id' => $project->id])
            ->assertCreated()->json('data.connection');

        $listed = $as()->getJson("/api/v1/projects/{$project->id}/integrations/link/{$connection}/accounts")->assertOk()->json('data.accounts');
        $this->assertSame(['acct_live_1'], array_column($listed, 'external_id'));

        $as()->postJson("/api/v1/projects/{$project->id}/integrations/link/{$connection}/accounts", ['external_id' => 'acct_live_1', 'organisation' => null])
            ->assertSuccessful();

        return ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection)->sole();
    }

    private function sync(ExternalAccount $account, bool $tolerateRefusal = false): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        try {
            app(AccountStructureSyncer::class)->sync($account);
            app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::parse('2026-09-01')->startOfDay(), Carbon::parse('2026-09-30')->endOfDay());
        } catch (\Throwable $e) {
            // An unlinked account may be refused outright; either way nothing may be filed under the project.
            if (! $tolerateRefusal) {
                throw $e;
            }
        } finally {
            app(TenantContext::class)->forget();
        }
    }

    /** @return array<mixed> */
    private function read(Project $project, string $path): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/{$path}")
            ->assertOk()
            ->json('data') ?? [];
    }

    /** @return list<array<string,mixed>> */
    private function projects(User $user, Tenant $tenant): array
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/projects')->assertOk()->json('data');
    }

    /** @param  list<string>  $accountIds */
    private function report(Project $project, array $accountIds): Report
    {
        $id = $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/reports", [
                'name' => 'September', 'type' => 'weekly', 'scope' => ['account_ids' => $accountIds],
                'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            ])->assertSuccessful()->json('data.id');

        app(TenantContext::class)->setTenantId($this->tenant->id);
        (new GenerateReportJob((string) $id))->handle(app(ReportGenerator::class));
        $report = Report::query()->findOrFail($id);
        app(TenantContext::class)->forget();

        $this->assertSame('completed', $report->status);

        return $report;
    }

    /** An account B reads, with a figure of its own. */
    private function foreignAccount(Project $project): ExternalAccount
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'Meta', projectId: (string) $project->id,
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
            'provider_connection_id' => $connection->getKey(), 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_beta', 'name' => 'Beta Account', 'currency' => 'SAR', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => '2026-09-03', 'value' => 77,
        ]);
        app(TenantContext::class)->forget();

        return $account;
    }

    /** @return array{0: Tenant, 1: User} */
    private function agency(string $name): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => $name.' Owner', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant, Portal::Agency);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    private function project(Tenant $tenant, string $name): Project
    {
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $row the one day the provider reports */
    private function providerReports(array $row): void
    {
        Http::fake(function ($request) use ($row) {
            $url = $request->url();

            if (str_contains($url, '/conversions/insights')) {
                $conv = array_filter(['conversions' => $row['conversions'] ?? null], static fn ($v) => $v !== null);

                return Http::response(['object' => 'list', 'data' => $conv === [] ? [] : [['entity_id' => 'cmp_1', 'date' => $row['date']] + $conv], 'count' => $conv === [] ? 0 : 1]);
            }

            if (str_contains($url, '/ad_account/insights') && ! str_contains($url, 'aggregation_level=campaign')) {
                return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
            }

            if (str_contains($url, '/ad_account/insights')) {
                $delivery = array_diff_key($row, array_flip(['date', 'conversions']));

                return Http::response(['object' => 'list', 'data' => [['id' => 'r1', 'readable_time' => $row['date'], 'campaign_id' => 'cmp_1'] + $delivery], 'has_more' => false, 'last_id' => 'r1']);
            }

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp_1', 'name' => 'Launch']], 'has_more' => false]);
            }

            return Http::response(['id' => 'acct_live_1', 'object' => 'ad_account', 'name' => 'Acme Riyadh', 'currency' => 'SAR', 'currency_code' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'ACTIVE']);
        });
    }
}
