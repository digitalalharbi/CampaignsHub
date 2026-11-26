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
use App\Domains\Integrations\Services\SharedAccounts;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\InsightRowNormaliser;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SHARED-ACCOUNT-PROJECTS-001 (Owner 2026-11-26) — one ad account read by two projects, split by campaign.
 *
 * The Owner's case: the «hanka» Meta account carries Hankah's campaigns and Buzz's. Proven on the rows themselves:
 *  - an account with NO shared binding behaves exactly as before (the sync path is unchanged for everyone else);
 *  - a shared binding with no campaigns picked receives nothing;
 *  - a picked campaign is filed under the shared project, every other campaign stays home, and each metric row
 *    is stamped with its campaign's project — so the two projects add up to the account and nothing is counted twice;
 *  - the campaign's stored history moves with it, so the home project no longer counts it;
 *  - the account is still fetched for its home project (one fetch, one seat).
 */
final class SharedAccountProjectsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $hankah;

    private Project $buzz;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->hankah = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'حنكة', 'status' => 'active']);
        $this->buzz = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'باز', 'status' => 'active']);

        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_hanka', 'name' => 'hanka', 'currency' => 'SAR',
            'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);

        $this->bind($this->hankah, null);
    }

    public function test_an_account_that_is_not_shared_files_everything_home_exactly_as_before(): void
    {
        $this->import();

        $this->assertSame(
            [$this->hankah->id, $this->hankah->id],
            ExternalCampaign::withoutGlobalScopes()->orderBy('external_id')->pluck('project_id')->all(),
        );
        $this->assertSame([$this->hankah->id], app(SharedAccounts::class)->projectsFed($this->account, $this->hankah->id));
    }

    public function test_a_shared_binding_with_no_campaigns_picked_receives_nothing_and_the_home_stays_home(): void
    {
        $this->import();
        $this->bind($this->buzz, ['campaign_external_ids' => [], 'name_contains' => []]);

        $this->assertSame($this->hankah->id, app(AccountAssignment::class)->projectIdFor($this->account), 'the shared project took over the account');

        $this->import();

        $this->assertSame(0, ExternalCampaign::withoutGlobalScopes()->where('project_id', $this->buzz->id)->count());
    }

    public function test_a_picked_campaign_goes_to_the_shared_project_with_its_history_and_every_figure_is_counted_once(): void
    {
        $this->import();
        $buzzCampaign = ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-buzz')->firstOrFail();

        // History already stored under the home project before the split.
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->hankah->id,
            'external_account_id' => $this->account->id, 'external_campaign_id' => $buzzCampaign->id,
            'unified_campaign_id' => $buzzCampaign->unified_campaign_id, 'provider' => 'meta', 'metric_key' => 'spend',
            'metric_date' => '2026-11-01', 'value' => 40, 'project_currency' => 'SAR',
        ]);

        $this->bind($this->buzz, ['campaign_external_ids' => ['cmp-buzz'], 'name_contains' => []]);
        $this->import();

        $buzzCampaign->refresh();
        $this->assertSame($this->buzz->id, $buzzCampaign->project_id);
        $this->assertSame($this->buzz->id, UnifiedCampaign::withoutGlobalScopes()->findOrFail($buzzCampaign->unified_campaign_id)->project_id);
        $this->assertSame($this->hankah->id, ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-hankah')->value('project_id'));

        // The history moved with the campaign: none of it is left counted in Hankah.
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->where('project_id', $this->hankah->id)->where('external_campaign_id', $buzzCampaign->id)->count());
        $this->assertSame(1, DailyMetric::withoutGlobalScopes()->where('project_id', $this->buzz->id)->where('external_campaign_id', $buzzCampaign->id)->count());

        // The account is still fetched for its home project, and each figure lands in its campaign's project.
        $this->assertSame($this->hankah->id, app(AccountAssignment::class)->projectIdFor($this->account));
        [$metrics, $skipped, $misfiled] = app(InsightRowNormaliser::class)->normalise(
            $this->account,
            [
                ['campaign_id' => 'cmp-hankah', 'date' => '2026-11-20', 'spend' => 100],
                ['campaign_id' => 'cmp-buzz', 'date' => '2026-11-20', 'spend' => 30],
            ],
            MetricsAggregator::readKeys(),
            $this->hankah->id,
        );
        $this->assertSame(0, $skipped);
        $this->assertSame(0, $misfiled);

        // One spend row per campaign, each in its campaign's project — the two projects add up to the account.
        $spendRows = [];
        foreach ($metrics as $metric) {
            if ($metric->metricKey === 'spend') {
                $spendRows[] = [$metric->externalCampaignId, $metric->projectId];
            }
        }
        $hankahCampaign = (string) ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-hankah')->value('id');
        $this->assertEqualsCanonicalizing([[$hankahCampaign, $this->hankah->id], [(string) $buzzCampaign->id, $this->buzz->id]], $spendRows);
    }

    public function test_a_name_rule_claims_new_campaigns_for_the_shared_project(): void
    {
        $this->bind($this->buzz, ['campaign_external_ids' => [], 'name_contains' => ['buzz']]);
        $this->import();

        $this->assertSame($this->buzz->id, ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-buzz')->value('project_id'));
        $this->assertSame($this->hankah->id, ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-hankah')->value('project_id'));
    }

    public function test_the_shared_project_may_ask_for_a_fetch_and_an_unrelated_project_may_not(): void
    {
        $this->bind($this->buzz, ['campaign_external_ids' => ['cmp-buzz'], 'name_contains' => []]);
        $other = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->hankah->client_workspace_id, 'name' => 'X', 'status' => 'active']);

        $assignment = app(AccountAssignment::class);
        $this->assertTrue($assignment->isActivelyAssignedTo($this->account, $this->hankah->id));
        $this->assertTrue($assignment->isActivelyAssignedTo($this->account, $this->buzz->id));
        $this->assertFalse($assignment->isActivelyAssignedTo($this->account, $other->id));
    }

    private function import(): void
    {
        $projectId = app(AccountAssignment::class)->projectIdFor($this->account);
        $this->assertNotNull($projectId);

        app(ImportExternalCampaigns::class)->execute(
            $this->account,
            SyncResult::of([
                ['id' => 'cmp-hankah', 'name' => '25Nov 2026ــHankah Sale', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES'],
                ['id' => 'cmp-buzz', 'name' => '25Nov 2026ــBuzz Installs', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_APP_PROMOTION'],
            ]),
            $projectId,
        );
    }

    /** @param  array{campaign_external_ids: list<string>, name_contains: list<string>}|null  $scope  null — a home binding */
    private function bind(Project $project, ?array $scope): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $this->account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
            'settings' => $scope === null ? null : [SharedAccounts::KEY => $scope],
        ]);
    }
}
