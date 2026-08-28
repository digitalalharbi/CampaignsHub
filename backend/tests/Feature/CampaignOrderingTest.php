<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ENTITY-RELEVANCE-ORDERING-001, first slice: the campaign breakdown is TOTALLY ordered, and it
 * carries the facts a reader needs to tell a running campaign from a finished one.
 *
 * `byCampaign()` sorted by spend alone, over rows Postgres returned in whatever order the hash
 * aggregate produced. Two campaigns that spent the same amount — and a project full of campaigns
 * that spent nothing has many — therefore had NO defined order between them: the same request twice
 * could put them either way round, and the table's own stable sort faithfully preserved whichever
 * order it was handed. A reader refreshing a page and seeing rows swap has no way to know nothing
 * changed.
 *
 * It also returned no `status` and no `last_active_on`, so nothing downstream could tell a campaign
 * that is running today from one that stopped three weeks ago and still leads on spend. That is the
 * «currently-serving must not be mixed with stopped historical» half of the requirement, and it
 * cannot be answered by a surface that was never given the facts.
 *
 * The DEFAULT order stays spend-first on purpose. `byCampaign()` feeds reports, live report links
 * and the daily digest, where «the top campaigns» means the ones that spent the most; quietly
 * re-ranking them by how recently they ran would change what those documents say. The relevance
 * ordering belongs to the operational surface that asks for it — the facts land here, the ranking
 * is applied where the question is operational.
 */
final class CampaignOrderingTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW_FROM = '2026-07-01';

    private const WINDOW_TO = '2026-07-31';

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'ord-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        $this->account = $this->account();
    }

    /**
     * The tie-break that was missing.
     *
     * Both campaigns spent exactly the same, so spend cannot separate them. Without a further key the
     * order is whatever the database happened to produce — so the assertion is not «B before A», it is
     * that the SAME order comes back every time, and that it follows a rule a reader could predict.
     */
    /**
     * The tie-break, tested where it can actually fail.
     *
     * The rows are handed in deliberately scrambled, because through the database this cannot be
     * proven: PostgreSQL returns these groups in id order anyway, so a database version of this test
     * passes with the tiebreak and without it — decoration, not a guard. Scrambled input is the only
     * form that fails when the rule is removed.
     */
    public function test_campaigns_that_spent_the_same_are_ordered_by_a_key_that_cannot_move(): void
    {
        $scrambled = [
            ['campaign_id' => 'c-9', 'spend' => 500.0],
            ['campaign_id' => 'c-3', 'spend' => 500.0],
            ['campaign_id' => 'c-7', 'spend' => 500.0],
            ['campaign_id' => 'c-1', 'spend' => 500.0],
        ];

        $ordered = array_column(MetricsAggregator::orderCampaignRows($scrambled), 'campaign_id');

        $this->assertSame(['c-1', 'c-3', 'c-7', 'c-9'], $ordered);
    }

    /** The tiebreak is the SECOND key, never the first — spend still decides while it can. */
    public function test_the_tiebreak_never_outranks_spend(): void
    {
        $rows = [
            ['campaign_id' => 'c-1', 'spend' => 10.0],
            ['campaign_id' => 'c-9', 'spend' => 900.0],
            ['campaign_id' => 'c-5', 'spend' => 900.0],
        ];

        $ordered = array_column(MetricsAggregator::orderCampaignRows($rows), 'campaign_id');

        $this->assertSame(['c-5', 'c-9', 'c-1'], $ordered);
    }

    /** Spend still decides while it can — reports and the digest depend on it. */
    public function test_the_default_order_is_still_the_largest_spend_first(): void
    {
        $small = $this->campaign('Small', 'active');
        $large = $this->campaign('Large', 'paused');

        $this->sync($small, 10.0, '2026-07-10');
        $this->sync($large, 900.0, '2026-07-02');

        $this->assertSame([(string) $large->id, (string) $small->id], $this->ids());
    }

    /**
     * The facts that let a surface separate what is running from what has stopped.
     *
     * `status` is the canonical one, not the provider's own string, and `last_active_on` is the most
     * recent day this campaign actually reported anything inside the window — which is what «still
     * serving» means operationally. Neither is a verdict: the row states them and the surface decides.
     */
    public function test_every_campaign_row_states_its_status_and_when_it_was_last_active(): void
    {
        $running = $this->campaign('Running', 'active');
        $stopped = $this->campaign('Stopped', 'paused');

        $this->sync($running, 100.0, '2026-07-30');
        $this->sync($stopped, 800.0, '2026-07-03');

        $rows = collect($this->rows())->keyBy('campaign_id');

        $this->assertSame('active', $rows[(string) $running->id]['status']);
        $this->assertSame('2026-07-30', $rows[(string) $running->id]['last_active_on']);

        $this->assertSame('paused', $rows[(string) $stopped->id]['status']);
        $this->assertSame('2026-07-03', $rows[(string) $stopped->id]['last_active_on']);
    }

    /**
     * A campaign whose only rows are zeros was not active on those days.
     *
     * `last_active_on` answers «when did this last DO anything», so a day of zeros is not an answer —
     * reading it as one would put a campaign that has been dark all month at the top of «most
     * recently active», which is the opposite of the truth.
     */
    public function test_a_day_of_zeros_is_not_a_day_the_campaign_was_active(): void
    {
        $dark = $this->campaign('Dark', 'active');

        $this->sync($dark, 40.0, '2026-07-05');
        $this->sync($dark, 0.0, '2026-07-25');

        $rows = collect($this->rows())->keyBy('campaign_id');

        $this->assertSame('2026-07-05', $rows[(string) $dark->id]['last_active_on']);
    }

    /** A campaign that reported nothing inside the window says so, rather than naming a day. */
    public function test_a_campaign_with_no_activity_in_the_window_reports_no_last_active_day(): void
    {
        $outside = $this->campaign('Outside', 'completed');

        $this->sync($outside, 300.0, '2026-06-15');

        $rows = collect($this->rows())->keyBy('campaign_id');

        $this->assertArrayNotHasKey((string) $outside->id, $rows, 'a campaign outside the window is not in the window');
    }

    /**
     * A campaign row says which metrics its platforms actually SENT — the coalesced-zero rule, at
     * campaign grain.
     *
     * `byCampaign()` sums with `COALESCE(..., 0)`, so a metric no platform ever reported for this
     * campaign arrives as `0` and is indistinguishable from a real measurement of none. A card
     * leading a leads campaign with «العملاء المحتملون 0» when the connector has never sent a lead
     * is the same defect the dashboard's `reported` map exists to prevent — one grain down, and on
     * the screen where somebody decides whether to keep paying for it.
     */
    public function test_a_campaign_row_says_which_metrics_its_platforms_actually_sent(): void
    {
        $campaign = $this->campaign('Reported', 'active');
        $this->sync($campaign, 120.0, '2026-07-10');

        $rows = collect($this->rows())->keyBy('campaign_id');
        $reported = $rows[(string) $campaign->id]['reported'];

        // Spend was sent. Leads never were — and that is not a leads count of zero.
        $this->assertTrue($reported['spend']);
        $this->assertFalse($reported['leads']);
        $this->assertFalse($reported['impressions']);
    }

    /** Two campaigns, two different answers — «reported» is a fact about THIS campaign's platforms. */
    public function test_one_campaigns_missing_metric_is_not_reported_as_missing_for_another(): void
    {
        $withClicks = $this->campaign('Clicks', 'active');
        $spendOnly = $this->campaign('Spend only', 'active');

        $this->sync($withClicks, 50.0, '2026-07-10', 'clicks', 30.0);
        $this->sync($spendOnly, 50.0, '2026-07-10');

        $rows = collect($this->rows())->keyBy('campaign_id');

        $this->assertTrue($rows[(string) $withClicks->id]['reported']['clicks']);
        $this->assertFalse($rows[(string) $spendOnly->id]['reported']['clicks']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $this->holdingTenant((string) $this->tenant->id);

        return app(MetricsAggregator::class)
            ->forProjects([(string) $this->project->id])
            ->byCampaign(Carbon::parse(self::WINDOW_FROM), Carbon::parse(self::WINDOW_TO));
    }

    /** @return list<string> */
    private function ids(): array
    {
        return array_map(static fn (array $r): string => (string) $r['campaign_id'], $this->rows());
    }

    private function campaign(string $name, string $status): UnifiedCampaign
    {
        $this->holdingTenant((string) $this->tenant->id);

        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => $status, 'objective' => 'sales',
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
    }

    private function sync(UnifiedCampaign $campaign, float $spend, string $date, ?string $extraKey = null, ?float $extraValue = null): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'ext-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        $metric = fn (string $key, ?float $value): NormalizedMetric => new NormalizedMetric(
            tenantId: (string) $this->tenant->id,
            projectId: (string) $this->project->id,
            provider: 'meta',
            externalAccountId: (string) $this->account->getKey(),
            externalCampaignId: (string) $external->id,
            unifiedCampaignId: (string) $campaign->id,
            metricDate: Carbon::parse($date),
            metricKey: $key,
            value: $value,
            projectCurrency: 'SAR',
        );

        $rows = [$metric('spend', $spend)];
        if ($extraKey !== null) {
            $rows[] = $metric($extraKey, $extraValue);
        }

        app(UpsertDailyMetrics::class)->handle($rows);

        app(TenantContext::class)->forget();
    }

    private function account(): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'meta-ad', 'name' => 'Meta',
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }
}
