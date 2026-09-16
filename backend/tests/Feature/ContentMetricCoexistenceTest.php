<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner defect 95 — every figure that is legitimately available COEXISTS, on one creative and scope.
 *
 * ## The observation, in the owner's words
 *
 * «Sometimes the KPIs appear but Spend is missing. Sometimes Spend appears and the other KPIs
 * disappear.» The register records that the chain «has never been reconciled end to end on one
 * creative», so the cause was unknown and no per-surface patch could be called the fix.
 *
 * ## What the walk found, and what each case here pins
 *
 * `CreativeMetrics` answers two different questions from two different places. `forCreatives()` — the
 * cards, the popup, the trend, the roster, the fatigue verdict — reads `creative_daily_metrics` and
 * falls back to the AD grain for the five providers that report no creative grain at all. `totalsFor()`
 * — the library's own headline strip, sitting directly above those cards — queried
 * `creative_daily_metrics` alone and applied no demo policy.
 *
 * Both halves of that are the owner's sentence, mechanically:
 *
 *   · on a Meta, Google, TikTok, LinkedIn or X account the cards carry spend and the strip above them
 *     says nothing was reported — the figures appear and vanish in one viewport;
 *   · on a project holding seeded rows beside real ones the two sum different sets, which is
 *     «never mix Demo with Live» broken between two elements of one screen.
 *
 * `aggregate()` is the same class of loss one surface further on: it sums the creative table's column
 * list and never the ad grain's, so a group total, the format comparison and Content Analytics drop
 * the leads, sign-ups, installs, app opens and page views a card for the same creative shows.
 *
 * ## Why these are service tests and not endpoint tests
 *
 * The defect is not in a controller and not in a React cell: it is that two readers of one subject
 * consult different sources. Pinned where that decision is made, so a third reader added later
 * inherits the property rather than having to remember it. `ContentKpiTotalsTest` holds the endpoint
 * half — that the strip a reader actually sees carries what the cards under it carry.
 */
final class ContentMetricCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Coexist', 'slug' => 'co-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Meta',
            'status' => 'active',
        ]);

        /* The active project, as `ResolveProject` sets it on every real request — the demo policy
         * asks the project whether it holds live rows, and without a context it would not filter. */
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /**
     * The library's headline strip reads the SAME sources as the cards beneath it.
     *
     * This is the owner's «Spend appears and the other KPIs disappear» seen from the other side: on a
     * Meta account the creative grain is empty by design — `AccountMetricsSyncer` asks for it behind
     * `instanceof ReportsCreativeInsights` and Snapchat is the only implementor — so the cards fell
     * back to the ad grain and the strip, which never had that fallback, reported nothing at all.
     */
    public function test_the_headline_strip_carries_the_spend_the_cards_carry(): void
    {
        $creative = $this->creative('cr-ad-grain');
        $this->adWithMetrics($creative, spend: 250.0, impressions: 12_000, clicks: 480, extra: ['leads' => 16]);

        [$from, $to] = $this->window();
        $metrics = app(CreativeMetrics::class);

        $card = $metrics->forCreatives([(string) $creative->getKey()], $from, $to)[(string) $creative->getKey()] ?? null;
        $strip = $metrics->totalsFor([(string) $creative->getKey()], $from, $to);

        $this->assertNotNull($card, 'the card itself reported nothing, so this case proves nothing');
        $this->assertNotNull(
            $strip,
            'the cards carry figures and the headline strip above them says nothing was reported',
        );
        $this->assertSame(250.0, (float) $strip['spend'], 'the strip lost the spend the cards show');
        $this->assertSame(12_000.0, (float) $strip['impressions']);
        $this->assertSame(480.0, (float) $strip['clicks']);
    }

    /**
     * And the RESULT the campaign was bought for reaches the strip too.
     *
     * Spend alone would be the owner's other sentence — «the KPIs appear but Spend is missing» read
     * backwards — so the figure a lead campaign is judged by is asserted separately from the price.
     */
    public function test_the_headline_strip_carries_the_result_the_ad_grain_answered(): void
    {
        $creative = $this->creative('cr-leads-strip');
        $this->adWithMetrics($creative, spend: 400.0, impressions: 10_000, clicks: 250, extra: ['leads' => 20]);

        [$from, $to] = $this->window();
        $strip = app(CreativeMetrics::class)->totalsFor([(string) $creative->getKey()], $from, $to);

        $this->assertNotNull($strip);
        $this->assertSame(20.0, (float) ($strip['leads'] ?? -1), 'the leads never reached the headline strip');
        $this->assertSame(20.0, (float) ($strip['spend'] / ($strip['cpl'] ?? INF)), 'cost per lead was not derived on the strip');
    }

    /**
     * Seeded rows are excluded from the strip exactly as they are from the cards.
     *
     * `ANALYTICS-PROVENANCE-001` states it for `daily_metrics`: «a seeded row added to them is not a
     * rounding error — it is invented money inside a real total». `forCreatives()` applies
     * `CreativeDemoPolicy` and `totalsFor()` did not, so one screen summed two different sets and the
     * strip was the one carrying the invented money.
     */
    public function test_the_headline_strip_excludes_the_seeded_rows_the_cards_exclude(): void
    {
        $creative = $this->creative('cr-mixed-strip');

        $this->creativeRow($creative, spend: 100.0, impressions: 1_000, clicks: 10, isDemo: false);
        $this->creativeRow($creative, spend: 9_000.0, impressions: 900_000, clicks: 9_000, isDemo: true);

        [$from, $to] = $this->window();
        $metrics = app(CreativeMetrics::class);

        $card = $metrics->forCreatives([(string) $creative->getKey()], $from, $to)[(string) $creative->getKey()];
        $strip = $metrics->totalsFor([(string) $creative->getKey()], $from, $to);

        $this->assertSame(100.0, (float) $card['spend'], 'the card acquired seeded spend');
        $this->assertNotNull($strip);
        $this->assertSame(
            100.0,
            (float) $strip['spend'],
            'the headline strip added seeded spend to a real total while the cards under it did not',
        );
    }

    /**
     * A group, the format comparison and Content Analytics keep what the ad grain answered.
     *
     * `aggregate()` iterates `SUMS` — the creative table's column list — so every key only
     * `entity_daily_metrics` carries was dropped on the way into an aggregate. The card for the same
     * creative shows 20 leads; the group containing that one creative showed none.
     */
    public function test_an_aggregate_keeps_the_results_only_the_ad_grain_carries(): void
    {
        $creative = $this->creative('cr-agg');
        $this->adWithMetrics($creative, spend: 400.0, impressions: 10_000, clicks: 250, extra: [
            'leads' => 20, 'installs' => 5, 'page_views' => 900, 'sign_ups' => 7, 'app_opens' => 3,
        ]);

        [$from, $to] = $this->window();
        $metrics = app(CreativeMetrics::class);

        $card = $metrics->forCreatives([(string) $creative->getKey()], $from, $to)[(string) $creative->getKey()];
        $aggregate = $metrics->aggregate([$card]);

        $this->assertNotNull($aggregate);

        foreach (['leads' => 20.0, 'installs' => 5.0, 'page_views' => 900.0, 'sign_ups' => 7.0, 'app_opens' => 3.0] as $key => $expected) {
            $this->assertSame(
                $expected,
                (float) ($aggregate[$key] ?? -1),
                "an aggregate over one creative lost «{$key}», which that creative's own card answers",
            );
        }

        $this->assertSame(20.0, (float) ($aggregate['cpl'] ?? -1), 'cost per lead was not derived for the aggregate');
    }

    /**
     * The coexistence property itself, stated once and over a MIXED scope.
     *
     * Every metric the cards can answer for a scope, the strip over that same scope answers too. This
     * is the invariant the owner's sentence violates in both directions, and it is asserted as a
     * property rather than key by key so a metric added later is covered by being added.
     */
    public function test_every_figure_the_cards_answer_the_strip_answers_too(): void
    {
        $native = $this->creative('cr-native');
        $this->creativeRow($native, spend: 60.0, impressions: 6_000, clicks: 120, isDemo: false, extra: [
            'conversions' => 4, 'revenue' => 900.0, 'reach' => 5_000, 'video_views' => 3_000,
        ]);

        $derived = $this->creative('cr-derived');
        $this->adWithMetrics($derived, spend: 40.0, impressions: 4_000, clicks: 80, extra: ['leads' => 8]);

        [$from, $to] = $this->window();
        $metrics = app(CreativeMetrics::class);
        $ids = [(string) $native->getKey(), (string) $derived->getKey()];

        $cards = $metrics->forCreatives($ids, $from, $to);
        $strip = $metrics->totalsFor($ids, $from, $to);

        $this->assertCount(2, $cards, 'both creatives should have reported');
        $this->assertNotNull($strip, 'the strip reported nothing for a scope whose cards both reported');

        $answeredByCards = [];
        foreach ($cards as $row) {
            foreach ($this->answered($row) as $key) {
                $answeredByCards[$key] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($answeredByCards), $this->answered($strip)));

        $this->assertSame(
            [],
            $missing,
            'the headline strip cannot answer figures the cards beneath it answer: '.implode(', ', $missing),
        );
    }

    /**
     * A spend with no conversion rate reaches the strip as WITHHELD — never as absent.
     *
     * FX-001 leaves `spend` null and preserves `spend_original` beside it, which is the state of every
     * Snapchat row on the owner's own account: a USD account with no USD→SAR rate. A strip that reads
     * only the converted column reports «nothing was spent» over money the platform did report, which
     * is the same defect `creativeMoney` exists to prevent one element away.
     */
    public function test_a_withheld_spend_reaches_the_strip_as_withheld_rather_than_absent(): void
    {
        $creative = $this->creative('cr-withheld');

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'spend' => null,
            'spend_original' => 79.61,
            'original_currency' => 'USD',
            'impressions' => 5_000,
            'clicks' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$from, $to] = $this->window();
        $strip = app(CreativeMetrics::class)->totalsFor([(string) $creative->getKey()], $from, $to);

        $this->assertNotNull($strip, 'a scope whose money was withheld reported nothing at all');
        $this->assertNull($strip['spend'], 'an unconvertible spend must not be stated in the reporting currency');
        $this->assertSame(1, (int) $strip['spend_withheld_rows'], 'the strip did not carry the withheld provenance');
        $this->assertSame(79.61, (float) $strip['spend_original']);
        $this->assertSame('USD', $strip['money_original_currency']);
    }

    /**
     * Owner defect 95 — a family may not NAME a verdict the service cannot produce.
     *
     * This is the class behind the instance, and the class has now bitten three times. The file's own
     * comments record two of them: `cpl` and `cpi` were «named as the verdict and never computed», so
     * `supportable()` struck them and «the card led with whatever came next»; and
     * `ObjectiveFamily::App` named `registrations` and `in_app_events`, «two figures that could never
     * arrive», showing «—» forever.
     *
     * The third is live: `ObjectiveFamily::Engagement` names `engagement_rate` and `cpe`, both are
     * listed in `DERIVED` as producible, and `derive()` computes neither — while `engagements` is a
     * real summed column, so both are arithmetic this service already has the inputs for. An
     * engagement creative therefore lost BOTH of its verdict metrics silently and led with spend,
     * engagements and impressions: figures true of any campaign whatever it was bought for.
     *
     * Asserted as a property over every family rather than as three more cases, because the next
     * family to be given a metric nobody wired up is the one no case covers. A row carrying every
     * column is fed through `aggregate()` — which calls the same `derive()` — and every metric any
     * family names must come back with a value.
     */
    public function test_every_metric_a_family_names_is_one_this_service_can_produce(): void
    {
        /*
         * One row with every column a provider could ever send, at both grains.
         *
         * Deliberately unrealistic: no single creative answers all of these, because the creative and
         * ad tables carry different columns. The question here is not «does a real row answer this» —
         * that is availability, and it is asked per row at render time. It is «CAN this service
         * produce the figure at all», and a family naming one it cannot is promising an empty cell for
         * ever.
         */
        $everything = [
            'spend' => 1_000.0, 'impressions' => 100_000.0, 'clicks' => 2_000.0,
            'conversions' => 50.0, 'revenue' => 5_000.0, 'add_to_cart' => 80.0, 'checkout' => 60.0,
            'purchases' => 50.0, 'landing_page_views' => 1_500.0, 'engagements' => 3_000.0,
            'reach' => 60_000.0, 'video_views' => 40_000.0, 'video_views_2s' => 30_000.0,
            'video_views_3s' => 25_000.0, 'video_views_6s' => 20_000.0, 'video_p25' => 18_000.0,
            'video_p50' => 15_000.0, 'video_p75' => 12_000.0, 'video_p100' => 10_000.0,
            'video_completions' => 10_000.0, 'leads' => 200.0, 'sign_ups' => 150.0,
            'installs' => 120.0, 'app_opens' => 90.0, 'page_views' => 4_000.0,
            'frequency' => 1.6, 'video_avg_watch_seconds' => 4.2, 'active_days' => 30,
        ];

        $produced = app(CreativeMetrics::class)->aggregate([$everything]);

        $this->assertNotNull($produced);

        $unproducible = [];

        foreach (ObjectiveFamily::cases() as $family) {
            foreach ($family->headlineMetrics() as $metric) {
                if (($produced[$metric] ?? null) === null) {
                    $unproducible[] = $family->value.'/'.$metric;
                }
            }
        }

        $this->assertSame(
            [],
            $unproducible,
            'these families name a verdict this service cannot compute, so the card promises an empty '
            .'cell for ever: '.implode(', ', $unproducible),
        );
    }

    /**
     * And the two the Engagement family is judged by are the arithmetic they should be.
     *
     * Named separately from the property above because a property can be satisfied by a wrong number.
     * 3,000 engagements over 100,000 impressions is 3%; 1,000 spent on 3,000 engagements is a third
     * of a unit each.
     */
    public function test_engagement_rate_and_cost_per_engagement_are_derived_from_their_own_figures(): void
    {
        $produced = app(CreativeMetrics::class)->aggregate([[
            'spend' => 1_000.0, 'impressions' => 100_000.0, 'engagements' => 3_000.0, 'active_days' => 7,
        ]]);

        $this->assertNotNull($produced);
        $this->assertEqualsWithDelta(0.03, (float) $produced['engagement_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.3333, (float) $produced['cpe'], 0.0001);
    }

    /** And neither is invented where the platform reported no engagement at all. */
    public function test_neither_engagement_figure_is_invented_without_engagements(): void
    {
        $produced = app(CreativeMetrics::class)->aggregate([[
            'spend' => 1_000.0, 'impressions' => 100_000.0, 'active_days' => 7,
        ]]);

        $this->assertNotNull($produced);
        $this->assertNull($produced['engagement_rate'], 'an engagement rate was invented from no engagements');
        $this->assertNull($produced['cpe'], 'a cost per engagement was invented from no engagements');
    }

    /**
     * Which metrics a shaped row can actually answer — `reported` plus the non-null derived figures.
     *
     * The provenance fields are excluded deliberately: they describe the money rather than being
     * figures a reader acts on, and `shape()` keeps them out of `reported` for the same reason.
     *
     * @param  array<string, mixed>  $figures
     * @return list<string>
     */
    private function answered(array $figures): array
    {
        $keys = [];

        foreach ($figures as $key => $value) {
            if (in_array($key, ['reported', 'grain', 'active_days', 'creatives'], true)) {
                continue;
            }

            if (str_ends_with($key, '_withheld_rows') || str_ends_with($key, '_original')
                || str_starts_with($key, 'money_original')) {
                continue;
            }

            if ($value !== null) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    /** @return array{Carbon, Carbon} */
    private function window(): array
    {
        return [Carbon::today()->subDays(7), Carbon::today()];
    }

    private function creative(string $externalId): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'external_creative_id' => $externalId,
            'name' => $externalId,
            'format' => 'image',
            'status' => 'active',
            'source_type' => 'api',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function creativeRow(
        ExternalCreative $creative,
        float $spend,
        float $impressions,
        float $clicks,
        bool $isDemo,
        array $extra = [],
    ): void {
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => Carbon::today()->subDays($isDemo ? 2 : 1)->toDateString(),
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'is_demo' => $isDemo,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function adWithMetrics(
        ExternalCreative $creative,
        float $spend,
        float $impressions,
        float $clicks,
        array $extra = [],
    ): void {
        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-'.Str::random(6),
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        DB::table('entity_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => (string) $ad->external_id,
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
