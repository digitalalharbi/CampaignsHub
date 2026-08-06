<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * §14.5 — a report's scope, on twelve axes, that can only ever narrow.
 *
 * The claims under test are the two that make a scope trustworthy rather than decorative:
 *
 *   1. **It narrows the real figures.** Selecting a platform, an account, a campaign, an objective or
 *      a marketing path changes the numbers the aggregator returns — not a label above them.
 *   2. **It cannot widen.** `intersect()` is the only way two scopes combine, and every way of asking
 *      for more than was granted — a campaign outside the ceiling, a wider window, a platform never
 *      shared — ends with less, or with nothing. Never with the ceiling opened.
 *
 * The third claim is about honesty rather than arithmetic: ad sets and ads have no metrics of their
 * own in this system, so a scope naming them resolves UP to their campaigns and says so, instead of
 * presenting a campaign's spend as an ad set's.
 */
final class ReportScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $sales;

    private UnifiedCampaign $awareness;

    private string $metaAccount;

    private string $googleAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Scope Co', 'slug' => 'scope-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sales', 'objective' => 'sales', 'status' => 'active',
        ]);
        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Awareness', 'objective' => 'awareness', 'status' => 'active',
        ]);

        // Real account rows: `external_campaigns.external_account_id` carries a foreign key, and an
        // account axis tested against ids that exist nowhere would prove nothing about the join.
        $this->metaAccount = (string) $this->account($client, 'meta')->getKey();
        $this->googleAccount = (string) $this->account($client, 'tiktok')->getKey();

        // Two campaigns, two platforms, two accounts, two spends — so every axis has something to cut.
        $this->spend($this->sales, 'meta', $this->metaAccount, 1000.0);
        $this->spend($this->awareness, 'tiktok', $this->googleAccount, 400.0);
    }

    /**
     * A real ad account, credential and connection behind it.
     *
     * `external_accounts.provider_connection_id` is NOT NULL and `external_campaigns` carries a
     * foreign key to the account: an account axis tested against ids that exist nowhere would prove
     * nothing about the join it is supposed to make.
     */
    private function account(ClientWorkspace $client, string $provider): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->getKey(), 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::create([
            'tenant_id' => $this->tenant->getKey(),
            'client_workspace_id' => $client->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => 'acct-'.$provider,
            'name' => ucfirst($provider).' Ads',
            'currency' => 'SAR',
            'status' => 'active',
        ]);
    }

    /**
     * The platform-side campaign row an ad set or ad hangs from.
     *
     * `external_ad_sets.external_campaign_id` is NOT NULL — the structure is ingested from the
     * platform, where an ad set without a campaign does not exist. The fixture has to be as real as
     * the schema.
     */
    private function externalCampaign(UnifiedCampaign $campaign, string $provider, string $account): ExternalCampaign
    {
        return ExternalCampaign::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $campaign->getKey(),
            'external_account_id' => $account,
            'provider' => $provider,
            'external_id' => 'ext-'.$campaign->name,
            'name' => $campaign->name,
            'status' => 'active',
        ]);
    }

    private function spend(UnifiedCampaign $campaign, string $provider, string $account, float $spend): void
    {
        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->getKey(),
                projectId: (string) $this->project->getKey(),
                externalAccountId: $account,
                externalCampaignId: (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'scope:camp:'.$campaign->name),
                provider: $provider,
                metricKey: 'spend',
                metricDate: Carbon::parse('2026-07-15'),
                value: $spend,
                unifiedCampaignId: (string) $campaign->getKey(),
            ),
        ]);
    }

    /** Total spend under a scope — the figure every claim here is made against. */
    private function spendUnder(ReportScope $scope): float
    {
        $engine = $scope->applyTo(app(MetricsAggregator::class));

        return (float) $engine->totals(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'))['spend'];
    }

    private function scope(array $input): ReportScope
    {
        return ReportScope::fromArray($input);
    }

    /** Unbounded is the starting point, and it sees everything the project has. */
    public function test_an_unbounded_scope_covers_the_whole_project(): void
    {
        $this->assertTrue(ReportScope::unbounded()->isUnbounded());
        $this->assertEqualsWithDelta(1400.0, $this->spendUnder(ReportScope::unbounded()), 0.01);
    }

    /**
     * Each axis narrows the FIGURES, not a caption.
     *
     * A scope that changed only what the page said it covered would pass every structural test and
     * still hand the client a total including the campaign they were told was excluded.
     */
    public function test_every_figure_axis_narrows_the_numbers(): void
    {
        $cases = [
            'campaign' => [['campaign_ids' => [(string) $this->sales->getKey()]], 1000.0],
            'platform' => [['providers' => ['tiktok']], 400.0],
            'account' => [['account_ids' => [$this->metaAccount]], 1000.0],
            'objective' => [['objectives' => ['awareness']], 400.0],
            'path' => [['paths' => ['conversion']], 1000.0],
        ];

        foreach ($cases as $axis => [$input, $expected]) {
            $this->assertEqualsWithDelta(
                $expected,
                $this->spendUnder($this->scope($input)),
                0.01,
                "the {$axis} axis did not narrow the figures",
            );
        }
    }

    /**
     * Two axes that disagree produce NOTHING, not the union and not the wider of the two.
     *
     * `paths: [awareness]` with `objectives: [sales]` is a scope no campaign satisfies. Answering with
     * either axis alone would be an accident of ordering, and answering with everything would be the
     * failure this whole class exists to prevent.
     */
    public function test_two_axes_that_cannot_both_be_satisfied_match_nothing(): void
    {
        $scope = $this->scope(['paths' => ['awareness'], 'objectives' => ['sales']]);

        $this->assertSame(['__none__'], $scope->objectivesIncludingPaths());
        $this->assertEqualsWithDelta(0.0, $this->spendUnder($scope), 0.01);
    }

    /** A path expands to the objectives on it — conversion covers sales AND leads. */
    public function test_a_marketing_path_expands_to_the_objectives_on_it(): void
    {
        $objectives = $this->scope(['paths' => ['conversion']])->objectivesIncludingPaths();

        $this->assertContains('sales', $objectives);
        $this->assertContains('leads', $objectives);
        $this->assertNotContains('awareness', $objectives);
    }

    /**
     * Asking for something outside the ceiling yields nothing — never the ceiling opened.
     *
     * This is the acceptance case «محاولة توسيع النطاق من الرابط العام والتأكد من الرفض Fail-Closed»,
     * expressed at the level where the rule lives.
     */
    public function test_asking_outside_the_ceiling_matches_nothing_rather_than_widening_it(): void
    {
        $ceiling = $this->scope(['campaign_ids' => [(string) $this->sales->getKey()]]);
        $asked = $this->scope(['campaign_ids' => [(string) $this->awareness->getKey()]]);

        $result = $asked->intersect($ceiling);

        $this->assertSame([ReportScope::IMPOSSIBLE], $result->campaignIds);
        $this->assertEqualsWithDelta(0.0, $this->spendUnder($result), 0.01);
    }

    /** Asking for nothing on an axis inherits the ceiling — the only case where the result is as wide. */
    public function test_an_unasked_axis_inherits_the_ceiling(): void
    {
        $ceiling = $this->scope(['providers' => ['meta']]);

        $result = ReportScope::unbounded()->intersect($ceiling);

        $this->assertSame(['meta'], $result->providers);
        $this->assertEqualsWithDelta(1000.0, $this->spendUnder($result), 0.01);
    }

    /** A ceiling that names nothing on an axis leaves the request's own bound standing. */
    public function test_an_unbounded_ceiling_does_not_erase_the_requested_bound(): void
    {
        $result = $this->scope(['providers' => ['tiktok']])->intersect(ReportScope::unbounded());

        $this->assertSame(['tiktok'], $result->providers);
    }

    /** Dates clamp to the window rather than failing, and a window narrowed past itself does not invert. */
    public function test_dates_clamp_into_the_window_and_never_invert(): void
    {
        $ceiling = $this->scope(['from' => '2026-07-10', 'to' => '2026-07-20']);

        $wider = $this->scope(['from' => '2026-01-01', 'to' => '2026-12-31'])->intersect($ceiling);
        $this->assertSame('2026-07-10', $wider->from);
        $this->assertSame('2026-07-20', $wider->to);

        $past = $this->scope(['from' => '2026-09-01', 'to' => '2026-09-30'])->intersect($ceiling);
        $this->assertNotNull($past->from);
        $this->assertNotNull($past->to);
        $this->assertLessThanOrEqual($past->to, $past->from, 'the window inverted instead of emptying');
    }

    /**
     * An ad set resolves UP to its campaign, because no metric is stored at its grain.
     *
     * The figure is the campaign's, and `explain()` says exactly that — the alternative is presenting
     * a campaign's spend under an ad set's name, wrong by an unknown multiple and uncheckable.
     */
    public function test_an_ad_set_narrows_to_its_campaign_and_says_so(): void
    {
        $adSet = ExternalAdSet::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $this->sales->getKey(), 'provider' => 'meta',
            'external_campaign_id' => $this->externalCampaign($this->sales, 'meta', $this->metaAccount)->getKey(),
            'external_id' => 'as-1', 'name' => 'Prospecting', 'status' => 'active',
        ]);

        $scope = $this->scope(['ad_set_ids' => [(string) $adSet->getKey()]]);

        $this->assertSame([(string) $this->sales->getKey()], $scope->resolvedCampaignIds());
        $this->assertEqualsWithDelta(1000.0, $this->spendUnder($scope), 0.01);

        $note = collect($scope->explain())->firstWhere('axis', 'ad_set_ids');
        $this->assertSame('campaign', $note['grain']);
    }

    /** An ad resolves the same way, and an ad from another campaign intersects to nothing. */
    public function test_an_ad_outside_the_chosen_campaign_leaves_nothing(): void
    {
        $ad = ExternalAd::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $this->awareness->getKey(), 'provider' => 'tiktok',
            'external_campaign_id' => $this->externalCampaign($this->awareness, 'tiktok', $this->googleAccount)->getKey(),
            'external_id' => 'ad-1', 'name' => 'Video A', 'status' => 'active',
        ]);

        $scope = $this->scope([
            'campaign_ids' => [(string) $this->sales->getKey()],
            'ad_ids' => [(string) $ad->getKey()],
        ]);

        $this->assertSame([ReportScope::IMPOSSIBLE], $scope->resolvedCampaignIds());
        $this->assertEqualsWithDelta(0.0, $this->spendUnder($scope), 0.01);
    }

    /**
     * A selection whose rows are gone fails closed.
     *
     * A saved template outliving its ad set must not quietly become «the whole project» the day that
     * ad set is deleted — which is exactly what returning «no bound» would do.
     */
    public function test_a_stale_selection_matches_nothing_rather_than_everything(): void
    {
        $scope = $this->scope(['ad_set_ids' => [(string) Uuid::uuid4()]]);

        $this->assertSame([ReportScope::IMPOSSIBLE], $scope->resolvedCampaignIds());
        $this->assertEqualsWithDelta(0.0, $this->spendUnder($scope), 0.01);
    }

    /** Unknown axes and junk values are dropped, not stored and not honoured. */
    public function test_unknown_axes_and_junk_values_are_dropped(): void
    {
        $scope = $this->scope([
            'objectives' => ['sales', 'not_an_objective'],
            'paths' => ['conversion', 'sideways'],
            'metrics' => ['spend', 'invented_metric'],
            'tenant_ids' => ['sneaky'],
            'from' => 'not a date',
        ]);

        $this->assertSame(['sales'], $scope->objectives);
        $this->assertSame(['conversion'], $scope->paths);
        $this->assertSame(['spend'], $scope->metrics);
        $this->assertNull($scope->from);
        $this->assertArrayNotHasKey('tenant_ids', $scope->toArray());
    }

    /** The stored shape carries only what is bound, so a scope reads as its own summary. */
    public function test_the_stored_shape_omits_empty_axes(): void
    {
        $scope = $this->scope(['providers' => ['meta'], 'campaign_ids' => [], 'to' => '2026-07-31']);

        $this->assertSame(['providers' => ['meta'], 'to' => '2026-07-31'], $scope->toArray());
        $this->assertSame(['providers'], $scope->boundAxes());
    }

    /** `explain()` separates what narrows every figure from what narrows only one section. */
    public function test_explain_states_the_grain_each_axis_reaches(): void
    {
        $scope = $this->scope([
            'providers' => ['meta'],
            'creative_ids' => [(string) Uuid::uuid4()],
        ]);

        $byAxis = collect($scope->explain())->keyBy('axis');

        $this->assertSame('figures', $byAxis['providers']['grain']);
        $this->assertSame('creatives', $byAxis['creative_ids']['grain']);
    }

    /** The metric list is a display choice and a ceiling on it is the last word. */
    public function test_a_ceiling_on_the_visible_metrics_cannot_be_widened_by_asking(): void
    {
        $ceiling = $this->scope(['metrics' => ['spend', 'impressions']]);
        $asked = $this->scope(['metrics' => ['spend', 'revenue']]);

        $this->assertSame(['spend'], $asked->intersect($ceiling)->metrics);
    }
}
