<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Services\InsightRowNormaliser;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — moving an account to another project moves where its NEW rows land.
 *
 * `external_campaigns.project_id` was set on INSERT only, and every lower grain — ad sets, ads,
 * creatives, and every metric row through `InsightRowNormaliser` — takes its project from that
 * campaign row. So an account deselected from project A and selected for project B kept filling A:
 * the binding said B, the sweep fetched for B, and the rows went to A because a campaign row written
 * months ago still said A. That is the re-binding mechanism, and it runs for ever.
 *
 * The importer now re-files a campaign under the account's CURRENT binding project. The unified
 * campaign it was adopted into stays in A with A's history; the campaign is adopted afresh in B, and
 * the next metric row goes to B. Nothing already stored is moved or deleted — that is the cleanup's
 * decision, on the inventory's evidence.
 */
final class RebindingRefilesTheCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->projectA = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'B', 'status' => 'active']);

        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'A', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);

        $this->bind($this->projectA);
    }

    public function test_a_campaign_follows_the_account_to_its_new_project_and_so_does_the_next_metric_row(): void
    {
        $this->import();

        $campaign = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $this->account->id)->firstOrFail();
        $this->assertSame($this->projectA->id, $campaign->project_id);
        $unifiedInA = (string) $campaign->unified_campaign_id;

        // The operator deselects the account from A and selects it for B.
        ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectA->id)->update(['is_active' => false]);
        $this->bind($this->projectB);

        $this->import();

        $campaign->refresh();
        $this->assertSame($this->projectB->id, $campaign->project_id, 'the campaign kept the project it was first filed under');
        $this->assertNotSame($unifiedInA, (string) $campaign->unified_campaign_id, 'the campaign is still linked to A\'s unified campaign');
        $this->assertSame($this->projectB->id, UnifiedCampaign::withoutGlobalScopes()->findOrFail($campaign->unified_campaign_id)->project_id);

        // A's history is untouched: the unified campaign it was adopted into still exists there.
        $this->assertSame($this->projectA->id, UnifiedCampaign::withoutGlobalScopes()->findOrFail($unifiedInA)->project_id);

        // And the next metric row is B's.
        [$metrics] = app(InsightRowNormaliser::class)->normalise(
            $this->account,
            [['campaign_id' => 'cmp-1', 'date' => '2026-08-01', 'spend' => 12.5]],
            MetricsAggregator::readKeys(),
        );
        $this->assertNotSame([], $metrics);
        foreach ($metrics as $metric) {
            $this->assertSame($this->projectB->id, $metric->projectId, 'a metric row went to the project the account left');
        }
    }

    public function test_a_campaign_that_is_not_re_bound_keeps_its_project_and_its_link(): void
    {
        $this->import();
        $campaign = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $this->account->id)->firstOrFail();
        $link = (string) $campaign->unified_campaign_id;

        $this->import();

        $campaign->refresh();
        $this->assertSame($this->projectA->id, $campaign->project_id);
        $this->assertSame($link, (string) $campaign->unified_campaign_id, 'an ordinary re-sync must not re-adopt the campaign');
        $this->assertSame(1, UnifiedCampaign::withoutGlobalScopes()->count());
    }

    private function import(): void
    {
        $projectId = app(AccountAssignment::class)->projectIdFor($this->account);
        $this->assertNotNull($projectId);

        app(ImportExternalCampaigns::class)->execute(
            $this->account,
            SyncResult::of([['id' => 'cmp-1', 'name' => 'Launch', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES']]),
            $projectId,
        );
    }

    private function bind(Project $project): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $this->account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);
    }
}
