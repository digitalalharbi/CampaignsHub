<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SEC-CLUSTER-2 — the coverage block of a total speaks only about the projects the reader asked for.
 *
 * `ContributorCoverage` read expected providers, figures and the newest sync run per provider by
 * TENANT, so a reader of one project received another client's provider names and raw sync error text
 * in `coverage.reasons`, and had their own total marked partial/failed because of that client's run.
 */
final class CoverageStaysInsideTheReadersProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'P2-PROVIDER-ERROR-TEXT';

    private Tenant $tenant;

    private Project $p1;

    private Project $p2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->p1 = $this->project('C1');
        $this->p2 = $this->project('C2');

        // P1: one Snapchat campaign, no figures, no run. P2: Snapchat (its newest run FAILED) and TikTok.
        $this->campaign($this->p1, 'snapchat');
        $snapP2 = $this->campaign($this->p2, 'snapchat');
        $this->campaign($this->p2, 'tiktok');

        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->p2->id, 'external_account_id' => $snapP2->external_account_id,
            'provider' => 'snapchat', 'status' => 'failed', 'window_start' => '2026-09-01', 'window_end' => '2026-09-14',
            'error' => 'The last sync failed: '.self::SECRET,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_single_project_read_does_not_carry_another_projects_run(): void
    {
        app(ProjectContext::class)->setProjectId($this->p1->id);

        $coverage = app(MetricsAggregator::class)->totals(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-14'))['coverage'];

        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($coverage));
        $this->assertStringNotContainsString('tiktok', (string) json_encode($coverage));
    }

    public function test_a_multi_project_read_is_limited_to_its_own_projects(): void
    {
        $coverage = app(MetricsAggregator::class)->forProjects([$this->p1->id])->acrossProjects()
            ->totals(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-14'))['coverage'];

        $json = (string) json_encode($coverage);
        $this->assertStringNotContainsString(self::SECRET, $json, "another client's sync error reached this client's coverage");
        $this->assertStringNotContainsString('tiktok', $json, "another client's provider is listed in this client's coverage");
    }

    public function test_a_client_whose_projects_own_the_run_still_sees_it(): void
    {
        $coverage = app(MetricsAggregator::class)->forProjects([$this->p2->id])->acrossProjects()
            ->totals(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-14'))['coverage'];

        $this->assertStringContainsString(self::SECRET, (string) json_encode($coverage));
        $this->assertStringContainsString('tiktok', (string) json_encode($coverage));
    }

    public function test_a_read_with_no_statable_scope_names_no_contributor(): void
    {
        $coverage = app(MetricsAggregator::class)->totals(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-14'))['coverage'];

        $json = (string) json_encode($coverage);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('snapchat', $json);
    }

    private function project(string $client): Project
    {
        $ws = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $client, 'slug' => strtolower($client).'-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);

        return Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => $client, 'status' => 'active']);
    }

    private function campaign(Project $project, string $provider): ExternalCampaign
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider.'-'.uniqid(),
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act-'.uniqid(), 'name' => 'a', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'external_id' => 'cmp-'.uniqid(), 'name' => 'c', 'status' => 'active',
        ]);
    }
}
