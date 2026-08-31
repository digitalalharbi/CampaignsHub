<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PLATFORM-DECISION-ANALYTICS-001 — platforms inside each marketing path, never across them.
 *
 * The Platforms surface listed platforms with one set of figures over every objective at once. That
 * row cannot answer «which platform is contributing most to this objective», and the comparison it
 * invites is the one that must never be made: a platform buying awareness and a platform buying
 * sales are not better or worse than each other, and ranking them together invents a verdict out of
 * the work each was given.
 */
final class PlatformObjectiveContributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private const FROM = '2026-08-01';

    private const TO = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'pobj-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /** Two platforms on one path: comparable, with each one's share of THAT path's spend. */
    public function test_two_platforms_on_one_path_are_comparable_and_share_its_spend(): void
    {
        $this->spend('meta', 'awareness', 6_000);
        $this->spend('tiktok', 'awareness', 2_000);

        $awareness = $this->path('awareness');

        $this->assertTrue($awareness['comparable']);
        $this->assertSame('two_or_more_platforms_spent', $awareness['comparable_reason']);
        $this->assertSame(8_000.0, $awareness['spend']);

        $share = array_column($awareness['platforms'], 'spend_share', 'provider');
        $this->assertEqualsWithDelta(0.75, $share['meta'], 0.001);
        $this->assertEqualsWithDelta(0.25, $share['tiktok'], 0.001);
    }

    /**
     * One platform on a path is not a ranking.
     *
     * «Meta is the best platform for awareness» when Meta is the only platform that ran awareness is
     * a sentence with no evidence behind it, and it is exactly what a surface writes when the payload
     * hands it a sorted list of one.
     */
    public function test_a_path_only_one_platform_ran_is_not_comparable(): void
    {
        $this->spend('meta', 'awareness', 6_000);

        $awareness = $this->path('awareness');

        $this->assertFalse($awareness['comparable']);
        $this->assertSame('only_one_platform_spent', $awareness['comparable_reason']);
        // The row is still there — the figure is real, only the comparison is absent.
        $this->assertCount(1, $awareness['platforms']);
    }

    /** A path nobody ran says so, rather than reading as a path that performed badly. */
    public function test_a_path_nobody_ran_says_nothing_was_spent_on_it(): void
    {
        $this->spend('meta', 'awareness', 1_000);

        $conversion = $this->path('conversion');

        $this->assertFalse($conversion['comparable']);
        $this->assertSame('nothing_spent_on_this_path', $conversion['comparable_reason']);
        $this->assertSame([], $conversion['platforms']);
    }

    /**
     * The share is of the PATH, not of the project.
     *
     * «40% of awareness» is a fact about a decision somebody made. «40% of everything» is a fact
     * about the mix of work, and reading it as performance is how a platform running one big
     * conversion campaign comes to look like the best awareness buyer in the account.
     */
    public function test_the_share_is_of_the_path_not_of_the_whole_account(): void
    {
        $this->spend('meta', 'awareness', 1_000);
        $this->spend('tiktok', 'awareness', 1_000);
        $this->spend('snapchat', 'sales', 98_000);

        $share = array_column($this->path('awareness')['platforms'], 'spend_share', 'provider');

        $this->assertEqualsWithDelta(0.5, $share['meta'], 0.001);
        $this->assertEqualsWithDelta(0.5, $share['tiktok'], 0.001);
    }

    /**
     * And there is no list that contains platforms from two paths.
     *
     * The shape is the guard: a «best platform» card can only be written against a flat list, so the
     * payload never produces one, and it says why in as many words.
     */
    public function test_no_ranking_crosses_the_paths(): void
    {
        $this->spend('meta', 'awareness', 6_000);
        $this->spend('tiktok', 'sales', 6_000);

        $out = $this->build();

        $this->assertFalse($out['cross_path_comparison']);
        $this->assertStringContainsString('not compared across paths', $out['cross_path_reason_en']);

        foreach ($out['paths'] as $path) {
            foreach ($path['platforms'] as $platform) {
                $this->assertArrayNotHasKey('rank', $platform, 'a rank inside a path is fine; a rank across them is what this refuses');
            }
        }

        $awareness = array_column($out['paths'], null, 'path')['awareness'];
        $conversion = array_column($out['paths'], null, 'path')['conversion'];

        $this->assertSame(['meta'], array_column($awareness['platforms'], 'provider'));
        $this->assertSame(['tiktok'], array_column($conversion['platforms'], 'provider'));
    }

    /** The path totals the existing surface reports are unchanged by the new grain. */
    public function test_the_paths_still_total_what_they_did_before(): void
    {
        $this->spend('meta', 'awareness', 6_000);
        $this->spend('tiktok', 'awareness', 2_000);

        $paths = array_column((new ObjectivePerformance)->build(Carbon::parse(self::FROM), Carbon::parse(self::TO))['paths'], null, 'path');

        $this->assertEqualsWithDelta(8_000.0, $paths['awareness']['spend'], 0.01);
    }

    // ── OBJECTIVE-ANALYTICS-DEPTH-001 — strongest and weakest INSIDE a path ──────────────────────

    /**
     * Both ends of one path, by that path's own metric — and cost metrics read the other way round.
     *
     * The lowest cost per order is the strongest sales campaign. Ranking a cost the same way round
     * as a volume metric is how «best» comes to name the most expensive campaign in the account.
     */
    public function test_the_strongest_and_weakest_are_read_by_the_paths_own_metric(): void
    {
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);   // cpa 10
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 20);    // cpa 50

        $conversion = $this->leaders('conversion');

        $this->assertTrue($conversion['comparable']);
        $this->assertSame('cpa', $conversion['strongest']['metric']);
        $this->assertEqualsWithDelta(10.0, $conversion['strongest']['value'], 0.01, 'the cheapest order is the strongest, not the dearest');
        $this->assertEqualsWithDelta(50.0, $conversion['weakest']['value'], 0.01);
    }

    /**
     * A strongest of one is a figure wearing a superlative.
     *
     * «Your best sales campaign», said of the only sales campaign, tells a client nothing they did
     * not know and implies a choice was made between alternatives that did not exist.
     */
    public function test_one_campaign_on_a_path_has_no_strongest(): void
    {
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);

        $conversion = $this->leaders('conversion');

        $this->assertFalse($conversion['comparable']);
        $this->assertSame('only_one_campaign_spent', $conversion['comparable_reason']);
        $this->assertNull($conversion['strongest']);
        $this->assertNull($conversion['weakest']);
    }

    /** Both ends, or neither: a list of winners with no counterpart never says what to stop. */
    public function test_the_weakest_is_named_whenever_the_strongest_is(): void
    {
        $this->spendWith('meta', 'awareness', spend: 1_000, impressions: 1_000_000);
        $this->spendWith('tiktok', 'awareness', spend: 1_000, impressions: 200_000);

        $awareness = $this->leaders('awareness');

        $this->assertNotNull($awareness['strongest']);
        $this->assertNotNull($awareness['weakest']);
        $this->assertNotSame($awareness['strongest']['id'], $awareness['weakest']['id']);
    }

    /** And no campaign is ranked against a campaign from another path. */
    public function test_campaigns_are_never_ranked_across_paths(): void
    {
        $this->spendWith('meta', 'awareness', spend: 9_000, impressions: 1_000_000);
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);

        $out = (new ObjectivePerformance)->leadersByPath(Carbon::parse(self::FROM), Carbon::parse(self::TO));

        $this->assertFalse($out['cross_path_comparison']);

        $paths = array_column($out['paths'], null, 'path');
        $this->assertFalse($paths['awareness']['comparable'], 'one awareness campaign is not a ranking');
        $this->assertFalse($paths['conversion']['comparable']);
    }

    // ── FUNNEL-ANALYTICAL-PATTERN-001 — signal → context → explanation → evidence → action ──────

    /**
     * The funnel's shape, on a path that has something to say.
     *
     * The funnel is the product's most-praised surface because it does not draw a chart and leave
     * the reader to interpret it. Every other analytical surface draws the chart.
     */
    public function test_a_comparable_path_gives_all_five_steps(): void
    {
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);   // cpa 10
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 20);    // cpa 50

        $conversion = $this->explanation('conversion');

        $this->assertSame('cpa', $conversion['signal']['metric']);
        $this->assertEqualsWithDelta(10.0, $conversion['signal']['best']['value'], 0.01);
        $this->assertEqualsWithDelta(50.0, $conversion['signal']['worst']['value'], 0.01);
        $this->assertSame('conversion', $conversion['context']['scope']);
        $this->assertSame(2, $conversion['context']['campaigns']);
        $this->assertStringContainsString('difference in execution', $conversion['explanation']['en']);
        $this->assertSame(['spend', 'cpa'], $conversion['evidence']);
        $this->assertStringContainsString('before the weaker one takes more', $conversion['action']['en']);
        $this->assertNull($conversion['silent_reason']);
    }

    /**
     * A path with one campaign has no range, so it has no signal — and therefore no action.
     *
     * An action offered without evidence is worse than silence: it is the product spending
     * somebody's afternoon on its own guess.
     */
    public function test_a_path_with_nothing_to_compare_says_nothing_and_says_why(): void
    {
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);

        $conversion = $this->explanation('conversion');

        $this->assertNull($conversion['signal']);
        $this->assertNull($conversion['context']);
        $this->assertNull($conversion['explanation']);
        $this->assertSame([], $conversion['evidence']);
        $this->assertNull($conversion['action'], 'no comparison, no recommendation');
        $this->assertSame('only_one_campaign_spent', $conversion['silent_reason']);
    }

    /** And a path nobody ran is silent for its own reason, not for the previous one. */
    public function test_an_unrun_path_is_silent_for_its_own_reason(): void
    {
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);

        $this->assertSame('nothing_spent_on_this_path', $this->explanation('awareness')['silent_reason']);
    }

    /**
     * The signal is a RANGE, never a verdict.
     *
     * No industry figure, no «good» threshold, no multiple that trips an alarm — those are numbers
     * nobody here is entitled to invent, and a reader told that 50 is «bad» has been told something
     * we do not know. The payload carries the two ends and no judgement about either.
     */
    public function test_the_signal_carries_no_benchmark_and_no_verdict(): void
    {
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 100);
        $this->spendWith('meta', 'sales', spend: 1_000, orders: 20);

        $signal = $this->explanation('conversion')['signal'];

        foreach (['benchmark', 'target', 'good', 'bad', 'verdict', 'grade', 'score'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $signal);
        }
        $this->assertSame(['metric', 'best', 'worst'], array_keys($signal));
    }

    /** @return array<string,mixed> */
    private function explanation(string $path): array
    {
        $out = (new ObjectivePerformance)->explainByPath(Carbon::parse(self::FROM), Carbon::parse(self::TO));

        return array_column($out['paths'], null, 'path')[$path];
    }

    /** @return array<string,mixed> */
    private function leaders(string $path): array
    {
        $out = (new ObjectivePerformance)->leadersByPath(Carbon::parse(self::FROM), Carbon::parse(self::TO));

        return array_column($out['paths'], null, 'path')[$path];
    }

    private function spendWith(
        string $provider,
        string $objective,
        float $spend,
        float $orders = 0,
        float $impressions = 0,
        string $date = '2026-08-10',
    ): void {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => "{$provider} {$objective} ".uniqid(), 'status' => 'active', 'objective' => $objective,
        ]);

        $account = $this->account($provider);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => $provider, 'external_id' => 'x-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        foreach (['spend' => $spend, 'conversions' => $orders, 'impressions' => $impressions] as $key => $value) {
            if ($value <= 0) {
                continue;
            }

            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'external_account_id' => $account->getKey(),
                'external_campaign_id' => $external->getKey(),
                'unified_campaign_id' => $campaign->id,
                'provider' => $provider,
                'metric_key' => $key,
                'metric_date' => $date,
                'value' => $value,
                'original_amount' => $key === 'spend' ? $value : null,
                'original_currency' => $key === 'spend' ? 'SAR' : null,
                'project_currency' => 'SAR',
                'exchange_rate' => 1,
            ]);
        }
    }

    /**
     * OBJECTIVE-ANALYTICS-DEPTH-001 — each path's own trend, and the days nobody reported.
     *
     * One series over a mixed programme moves for reasons that cancel each other: awareness rising
     * while sales falls is a flat line, and the reader concludes the account is doing nothing.
     *
     * The empty days matter as much as the measured ones. A day with no row is not a day the chart
     * may skip — skipping it draws the line straight through the gap and turns a pause into a slope —
     * so every day in the window appears and says whether anything reported.
     */
    public function test_each_path_gets_its_own_series_with_every_day_in_the_window(): void
    {
        $this->spendWith('meta', 'awareness', spend: 300, impressions: 60_000, date: '2026-08-10');
        $this->spendWith('snapchat', 'sales', spend: 500, orders: 10, date: '2026-08-12');

        $trend = $this->trend('2026-08-10', '2026-08-12');
        $paths = array_column($trend['paths'], null, 'path');

        $this->assertArrayHasKey('awareness', $paths);
        $this->assertArrayHasKey('conversion', $paths);

        // Three days in the window, three points per path — including the two nobody reported.
        $this->assertCount(3, $paths['awareness']['days']);
        $this->assertSame([true, false, false], array_column($paths['awareness']['days'], 'reported'));
        $this->assertSame([false, false, true], array_column($paths['conversion']['days'], 'reported'));

        // A day nobody reported carries null, never a zero: a zero is a measurement.
        $this->assertNull($paths['awareness']['days'][1]['spend']);
        $this->assertSame(300.0, $paths['awareness']['days'][0]['spend']);
    }

    /**
     * The day's cost is the day's own, never the window's average.
     *
     * A window's cost per result is its spend over its results; a day's is that day's. They differ
     * the moment a result lands after the spend that bought it — which is every attribution model
     * there is — and deriving one from the other is how a chart comes to disagree with the card
     * above it.
     */
    public function test_the_cost_of_a_day_is_derived_from_that_day(): void
    {
        $this->spendWith('snapchat', 'sales', spend: 400, orders: 10, date: '2026-08-10');
        $this->spendWith('snapchat', 'sales', spend: 600, orders: 60, date: '2026-08-11');

        $days = array_column($this->trend('2026-08-10', '2026-08-11')['paths'], null, 'path')['conversion']['days'];

        $this->assertSame(40.0, $days[0]['cost_per_result']);
        $this->assertSame(10.0, $days[1]['cost_per_result']);
    }

    /** A path nobody spent on is absent — a flat line at zero reads as a result. */
    public function test_a_path_nobody_ran_has_no_series_at_all(): void
    {
        $this->spendWith('meta', 'awareness', spend: 300, impressions: 60_000, date: '2026-08-10');

        $paths = array_column($this->trend('2026-08-10', '2026-08-11')['paths'], 'path');

        $this->assertContains('awareness', $paths);
        $this->assertNotContains('conversion', $paths);
    }

    /** @return array<string,mixed> */
    private function trend(string $from, string $to): array
    {
        return (new ObjectivePerformance)->trendByPath(Carbon::parse($from), Carbon::parse($to));
    }

    /** @return array<string,mixed> */
    private function build(): array
    {
        return (new ObjectivePerformance)->byPlatform(Carbon::parse(self::FROM), Carbon::parse(self::TO));
    }

    /** @return array<string,mixed> */
    private function path(string $path): array
    {
        return array_column($this->build()['paths'], null, 'path')[$path];
    }

    private function spend(string $provider, string $objective, float $amount): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => "{$provider} {$objective}", 'status' => 'active', 'objective' => $objective,
        ]);

        $account = $this->account($provider);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => $provider, 'external_id' => 'x-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'external_campaign_id' => $external->getKey(),
            'unified_campaign_id' => $campaign->id,
            'provider' => $provider,
            'metric_key' => 'spend',
            'metric_date' => '2026-08-10',
            'value' => $amount,
            'original_amount' => $amount,
            'original_currency' => 'SAR',
            'project_currency' => 'SAR',
            'exchange_rate' => 1,
        ]);
    }

    /** @var array<string,ExternalAccount> */
    private array $accounts = [];

    private function account(string $provider): ExternalAccount
    {
        if (isset($this->accounts[$provider])) {
            return $this->accounts[$provider];
        }

        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => 'act-'.$provider,
            'name' => $provider,
            'status' => 'active',
            'currency' => 'SAR',
        ])->save();

        return $this->accounts[$provider] = $account;
    }
}
