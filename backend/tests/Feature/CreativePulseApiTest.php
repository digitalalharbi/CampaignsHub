<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §15.11 — the dashboard's creative section, over HTTP.
 *
 * The claims worth pinning here are the ones that make this section safe to put on a dashboard,
 * where a figure is read in two seconds and acted on without being opened:
 *
 *   - nothing is ranked across marketing paths, so an awareness creative is never judged by CPA;
 *   - a winner from forty impressions is labelled thin rather than presented as a finding;
 *   - changing a filter changes the answer, because it is the library's own query;
 *   - a manager confined to one client sees one client's content here too;
 *   - the query count does not grow with the number of creatives.
 */
final class CreativePulseApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $client;

    private Project $project;

    private UnifiedCampaign $awareness;

    private UnifiedCampaign $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Pulse', 'slug' => 'pulse-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@pulse.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Brand',
            'objective' => 'awareness', 'status' => 'active',
        ]);
        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);
    }

    // ---- fixtures -----------------------------------------------------------------------------

    private function creative(array $over = []): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $this->sales->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'A creative',
            'format' => 'image',
            'status' => 'active',
            'last_active_at' => now(),
            'last_synced_at' => now(),
        ], $over));
    }

    private function day(ExternalCreative $creative, string $date, array $values): void
    {
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'campaign_id' => $creative->campaign_id,
            'metric_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    /** A day inside the window, and the matching day in the period immediately before it. */
    private function now(ExternalCreative $creative, array $values): void
    {
        $this->day($creative, now()->subDays(2)->toDateString(), $values);
    }

    private function before(ExternalCreative $creative, array $values): void
    {
        $this->day($creative, now()->subDays(35)->toDateString(), $values);
    }

    private function window(): string
    {
        return '?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString();
    }

    private function pulse(string $extra = '', ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->operator, 'sanctum')
            ->getJson('/api/v1/creatives/pulse'.$this->window().$extra)
            ->assertOk()
            ->json('data');
    }

    // ---- rankings -----------------------------------------------------------------------------

    /**
     * Each objective is judged on its own path's metric — the awareness creative is never asked for a CPA.
     *
     * The whole §14/§15 objective rule, at the one place it is most tempting to break: a dashboard
     * wants a single «best creative» card, and the only way to have one is to pick a metric that is
     * wrong for most of the content underneath it.
     */
    public function test_each_objective_is_ranked_on_its_own_paths_metric(): void
    {
        $cheapReach = $this->creative(['name' => 'Cheap reach', 'campaign_id' => $this->awareness->getKey()]);
        $dearReach = $this->creative(['name' => 'Dear reach', 'campaign_id' => $this->awareness->getKey()]);
        $goodSale = $this->creative(['name' => 'Good sale']);
        $poorSale = $this->creative(['name' => 'Poor sale']);

        // Awareness: the cheaper CPM wins. It also has the WORSE cost per order (it has none at all).
        $this->now($cheapReach, ['spend' => 200, 'impressions' => 200000, 'clicks' => 400]);
        $this->now($dearReach, ['spend' => 900, 'impressions' => 100000, 'clicks' => 900]);
        // Sales: the higher ROAS wins, and it is the one with the higher CPM.
        $this->now($goodSale, ['spend' => 400, 'impressions' => 20000, 'clicks' => 800, 'conversions' => 40, 'revenue' => 4000]);
        $this->now($poorSale, ['spend' => 400, 'impressions' => 90000, 'clicks' => 300, 'conversions' => 10, 'revenue' => 800]);

        $best = collect($this->pulse()['best_by_objective'])->keyBy('objective');

        $this->assertSame('cpm', $best['awareness']['metric']);
        $this->assertFalse($best['awareness']['higher_wins']);
        $this->assertSame('Cheap reach', $best['awareness']['creative']['name']);

        $this->assertSame('roas', $best['sales']['metric']);
        $this->assertTrue($best['sales']['higher_wins']);
        $this->assertSame('Good sale', $best['sales']['creative']['name']);

        // And the awareness winner carries no cost-per-order among the metrics it is presented on.
        $this->assertNotContains('cpa', $best['awareness']['creative']['headline_metrics']);
    }

    /**
     * A lead campaign has no revenue, so the conversion path falls back to cost per order.
     *
     * Ranking it by ROAS would return no winner at all for a path that has a perfectly good one —
     * an empty card on a dashboard whose data is fine.
     */
    public function test_a_conversion_path_with_no_revenue_is_ranked_by_cost_per_order(): void
    {
        $leads = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Leads',
            'objective' => 'leads', 'status' => 'active',
        ]);

        $cheap = $this->creative(['name' => 'Cheap lead', 'campaign_id' => $leads->getKey()]);
        $dear = $this->creative(['name' => 'Dear lead', 'campaign_id' => $leads->getKey()]);

        $this->now($cheap, ['spend' => 300, 'impressions' => 30000, 'clicks' => 600, 'conversions' => 60]);
        $this->now($dear, ['spend' => 300, 'impressions' => 30000, 'clicks' => 600, 'conversions' => 10]);

        $best = collect($this->pulse()['best_by_objective'])->keyBy('objective');

        $this->assertSame('cpa', $best['leads']['metric']);
        $this->assertFalse($best['leads']['higher_wins']);
        $this->assertSame('Cheap lead', $best['leads']['creative']['name']);
    }

    /**
     * Best image and best video are answered per path, and each names the path it belongs to.
     *
     * NEGATIVE half: the awareness video must not be crowned «best video» on a sales metric, and the
     * two answers must not be collapsed into one.
     */
    public function test_best_image_and_best_video_are_answered_inside_each_path(): void
    {
        $brandFilm = $this->creative(['name' => 'Brand film', 'format' => 'video', 'campaign_id' => $this->awareness->getKey()]);
        $saleFilm = $this->creative(['name' => 'Sale film', 'format' => 'video']);
        $saleImage = $this->creative(['name' => 'Sale image']);

        $this->now($brandFilm, ['spend' => 100, 'impressions' => 100000, 'clicks' => 500, 'video_views' => 40000]);
        $this->now($saleFilm, ['spend' => 900, 'impressions' => 40000, 'clicks' => 900, 'conversions' => 90, 'revenue' => 9000]);
        $this->now($saleImage, ['spend' => 500, 'impressions' => 30000, 'clicks' => 400, 'conversions' => 20, 'revenue' => 1500]);

        $data = $this->pulse();

        $videos = collect($data['best_video'])->keyBy('path');
        $this->assertSame('Sale film', $videos['conversion']['creative']['name']);
        $this->assertSame('roas', $videos['conversion']['metric']);
        $this->assertSame('Brand film', $videos['awareness']['creative']['name']);
        $this->assertSame('cpm', $videos['awareness']['metric']);

        // The heaviest-spending path leads, so the dashboard still shows one card first.
        $this->assertSame('conversion', $data['best_video'][0]['path']);

        $images = collect($data['best_image'])->keyBy('path');
        $this->assertSame('Sale image', $images['conversion']['creative']['name']);
        $this->assertArrayNotHasKey('awareness', $images, 'an image was invented for a path that ran none');
    }

    /**
     * A winner drawn from forty impressions is labelled thin rather than presented as a finding.
     *
     * Both halves matter: the evidenced creative must beat the fluke when one exists, and when NONE
     * clears the floor the section must still answer — with `low_evidence` — rather than going blank
     * on a project that plainly has data.
     */
    public function test_a_fluke_does_not_outrank_an_evidenced_creative_and_thin_evidence_is_declared(): void
    {
        $solid = $this->creative(['name' => 'Solid']);
        $fluke = $this->creative(['name' => 'Fluke']);

        $this->now($solid, ['spend' => 1000, 'impressions' => 50000, 'clicks' => 900, 'conversions' => 100, 'revenue' => 5000]);
        // One lucky order on forty impressions — a ROAS of 20, and worth nothing.
        $this->now($fluke, ['spend' => 20, 'impressions' => 40, 'clicks' => 3, 'conversions' => 1, 'revenue' => 400]);

        $sales = collect($this->pulse()['best_by_objective'])->firstWhere('objective', 'sales');

        $this->assertSame('Solid', $sales['creative']['name'], 'a 40-impression fluke was crowned');
        $this->assertFalse($sales['low_evidence']);
        $this->assertSame(2, $sales['candidates']);
        $this->assertSame(1, $sales['evidenced']);

        // With ONLY the thin creative, the answer is still given — and marked.
        $solid->delete();
        $thin = collect($this->pulse()['best_by_objective'])->firstWhere('objective', 'sales');

        $this->assertSame('Fluke', $thin['creative']['name']);
        $this->assertTrue($thin['low_evidence'], 'a ranking with no evidenced candidate was presented as solid');
    }

    // ---- movement -----------------------------------------------------------------------------

    /**
     * Growth and decline are measured against the period immediately before, on each creative's own metric.
     *
     * A creative with nothing to compare against appears in neither list — «no previous period» is
     * not «flat», and putting it in either would invent a trend from one observation.
     */
    public function test_growth_and_decline_are_measured_against_the_previous_period(): void
    {
        $rising = $this->creative(['name' => 'Rising']);
        $falling = $this->creative(['name' => 'Falling']);
        $brandNew = $this->creative(['name' => 'Brand new']);

        $this->before($rising, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 10, 'revenue' => 1000]);
        $this->now($rising, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 40, 'revenue' => 4000]);

        $this->before($falling, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 40, 'revenue' => 4000]);
        $this->now($falling, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 10, 'revenue' => 1000]);

        $this->now($brandNew, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 20, 'revenue' => 2000]);

        $data = $this->pulse();

        $growing = collect($data['fastest_growing']['items']);
        $declining = collect($data['declining']['items']);

        $this->assertSame(['Rising'], $growing->pluck('creative.name')->all());
        $this->assertSame(['Falling'], $declining->pluck('creative.name')->all());

        $this->assertSame('roas', $growing->first()['metric']);
        $this->assertEqualsWithDelta(3.0, $growing->first()['change'], 0.01);
        $this->assertEqualsWithDelta(-0.75, $declining->first()['change'], 0.01);

        $this->assertNotContains('Brand new', $growing->pluck('creative.name')->all());
        $this->assertNotContains('Brand new', $declining->pluck('creative.name')->all());
    }

    /** Ordinary variance is not growth. A 3% move is noise and belongs in neither list. */
    public function test_a_small_movement_is_not_reported_as_growth(): void
    {
        $steady = $this->creative(['name' => 'Steady']);

        $this->before($steady, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 40, 'revenue' => 4000]);
        $this->now($steady, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 41, 'revenue' => 4100]);

        $data = $this->pulse();

        $this->assertSame(0, $data['fastest_growing']['total']);
        $this->assertSame(0, $data['declining']['total']);
    }

    // ---- states and distributions -------------------------------------------------------------

    /**
     * The fatigue alert is «fatigued AND still spending» — the version of the fact that names an action.
     *
     * A creative that is fatigued and has already been switched off does not need an alert; it needs
     * a badge, which the card carries. Alerting on both makes the alert list something to scroll
     * past.
     */
    public function test_fatigue_counts_every_state_and_alerts_only_where_money_is_still_going(): void
    {
        $tired = $this->creative(['name' => 'Tired']);
        $fresh = $this->creative(['name' => 'Too new']);

        // Seven active days on each side, and a wholesale collapse between them.
        foreach (range(2, 8) as $offset) {
            $this->day($tired, now()->subDays($offset)->toDateString(), [
                'spend' => 200, 'impressions' => 20000, 'clicks' => 100, 'conversions' => 1,
                'revenue' => 100, 'frequency' => 6.0,
            ]);
            $this->day($tired, now()->subDays($offset + 30)->toDateString(), [
                'spend' => 100, 'impressions' => 20000, 'clicks' => 400, 'conversions' => 20,
                'revenue' => 2000, 'frequency' => 2.0,
            ]);
        }

        $this->now($fresh, ['spend' => 10, 'impressions' => 300, 'clicks' => 4]);

        $fatigue = $this->pulse()['fatigue'];

        $this->assertSame(1, $fatigue['counts']['fatigued']);
        $this->assertSame(1, $fatigue['counts']['insufficient_data'], 'a creative with three days of data was called stable');
        $this->assertSame('Tired', $fatigue['fatigued']['items'][0]['name']);
        $this->assertSame('Too new', $fatigue['insufficient_data']['items'][0]['name']);

        $this->assertSame(1, $fatigue['alerts']['total']);
        $this->assertSame('Tired', $fatigue['alerts']['items'][0]['creative']['name']);
        // CREATIVE-MONEY-TRUTH-001 — the total carries its provenance now; these rows are all
        // convertible, so the converted figure is the whole of it and nothing is withheld.
        $this->assertEqualsWithDelta(1400.0, $fatigue['spend_at_risk']['spend'], 0.01);
        $this->assertSame(0, $fatigue['spend_at_risk']['spend_withheld_rows']);
        $this->assertNotEmpty($fatigue['alerts']['items'][0]['signals']);
    }

    /**
     * Spend splits by creative type; a creative with no figures in the window is named, not zeroed.
     *
     * Spend is the one figure that may be summed across objectives — an awareness riyal and a sales
     * riyal are the same riyal. A RESULT summed the same way would be the blended-CPA defect.
     *
     * The fourth creative below has no metrics at all in this window, which is the ordinary shape of
     * «no reported spend»: `creative_daily_metrics.spend` is NOT NULL, so a delivering creative
     * always has a figure and a creative that did not deliver has no row. Counting it as a zero-spend
     * image would make the split's denominator include content that was never bought.
     */
    public function test_spend_splits_by_creative_type_and_a_creative_with_no_figures_is_not_a_zero(): void
    {
        $image = $this->creative(['name' => 'Image']);
        $video = $this->creative(['name' => 'Video', 'format' => 'video']);
        $carousel = $this->creative(['name' => 'Carousel', 'format' => 'carousel']);
        $this->creative(['name' => 'Never delivered']);

        $this->now($image, ['spend' => 600, 'impressions' => 10000]);
        $this->now($video, ['spend' => 300, 'impressions' => 10000]);
        $this->now($carousel, ['spend' => 100, 'impressions' => 10000]);

        $byKind = collect($this->pulse()['spend_by_kind'])->keyBy('kind');

        $this->assertEqualsWithDelta(600.0, $byKind['image']['spend'], 0.01);
        $this->assertEqualsWithDelta(0.6, $byKind['image']['share'], 0.001);
        $this->assertSame(2, $byKind['image']['creatives']);
        $this->assertSame(1, $byKind['image']['spend_not_reported'], 'a creative with no figures was counted as spending zero');
        $this->assertEqualsWithDelta(0.3, $byKind['video']['share'], 0.001);
        $this->assertEqualsWithDelta(0.1, $byKind['carousel']['share'], 0.001);
    }

    /**
     * Images against videos, INSIDE a path — never one comparison across all of them.
     *
     * The awareness impressions below are the trap: summed into the sales figures they would inflate
     * the image side's denominator and hand the videos a CTR they did not earn.
     */
    public function test_images_and_videos_are_compared_inside_a_path_and_never_across_paths(): void
    {
        $salesImage = $this->creative(['name' => 'Sales image']);
        $salesVideo = $this->creative(['name' => 'Sales video', 'format' => 'video']);
        $brandImage = $this->creative(['name' => 'Brand image', 'campaign_id' => $this->awareness->getKey()]);

        $this->now($salesImage, ['spend' => 100, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 10, 'revenue' => 500]);
        $this->now($salesVideo, ['spend' => 100, 'impressions' => 10000, 'clicks' => 400, 'conversions' => 20, 'revenue' => 2000]);
        $this->now($brandImage, ['spend' => 900, 'impressions' => 900000, 'clicks' => 100]);

        $comparison = collect($this->pulse()['image_vs_video'])->keyBy('path');

        $this->assertCount(1, $comparison, 'a path with no video on it was given an image-versus-video verdict');
        $this->assertArrayHasKey('conversion', $comparison);

        $conversion = $comparison['conversion'];
        $this->assertEqualsWithDelta(10000.0, $conversion['image']['impressions'], 0.01, 'awareness impressions leaked into the sales comparison');
        $this->assertEqualsWithDelta(0.01, $conversion['image']['ctr'], 0.0001);
        $this->assertEqualsWithDelta(0.04, $conversion['video']['ctr'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $conversion['image']['roas'], 0.01);
        $this->assertEqualsWithDelta(20.0, $conversion['video']['roas'], 0.01);
        $this->assertContains('roas', $conversion['headline_metrics']);
    }

    /**
     * «Best platform» is asked only of a grouped creative — the same asset on two platforms.
     *
     * Two DIFFERENT creatives on two platforms differ in more than the platform, so naming a winner
     * between them credits the placement for the content.
     */
    public function test_the_best_platform_is_reported_only_for_one_asset_running_in_more_than_one_place(): void
    {
        $group = CreativeGroup::create([
            'project_id' => $this->project->getKey(), 'name' => 'One asset', 'method' => 'manual',
        ]);

        $onMeta = $this->creative(['name' => 'One asset', 'provider' => 'meta', 'creative_group_id' => $group->getKey()]);
        $onTiktok = $this->creative(['name' => 'One asset', 'provider' => 'tiktok', 'creative_group_id' => $group->getKey()]);
        $alone = $this->creative(['name' => 'Only on meta']);

        $this->now($onMeta, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 10, 'revenue' => 1000]);
        $this->now($onTiktok, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 40, 'revenue' => 4000]);
        $this->now($alone, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 90, 'revenue' => 9000]);

        $best = $this->pulse()['best_platform'];

        $this->assertSame(1, $best['total'], 'an ungrouped creative was given a best platform');
        $this->assertSame('tiktok', $best['items'][0]['winner']);
        $this->assertSame('roas', $best['items'][0]['metric']);
        $this->assertCount(2, $best['items'][0]['platforms']);
        $this->assertNotContains($alone->name, array_column($best['items'], 'name'));
        $this->assertFalse($best['items'][0]['tied']);
    }

    /**
     * When both platforms ran the asset identically, neither is the winner.
     *
     * Found by opening the demo agency: the same film ran on TikTok and Snapchat at an identical CPM
     * and the card crowned TikTok — for being first in the list. On this card in particular that is a
     * verdict a sort order invented, and somebody could move a budget on it.
     */
    public function test_two_platforms_that_performed_identically_produce_no_winner(): void
    {
        $group = CreativeGroup::create([
            'project_id' => $this->project->getKey(), 'name' => 'Same everywhere', 'method' => 'manual',
        ]);

        $onMeta = $this->creative(['name' => 'Same everywhere', 'provider' => 'meta', 'creative_group_id' => $group->getKey()]);
        $onSnap = $this->creative(['name' => 'Same everywhere', 'provider' => 'snapchat', 'creative_group_id' => $group->getKey()]);

        // Not identical: a difference of 0.04% of the ROAS, which is what the demo actually looked
        // like and what both sides round to on screen.
        $this->now($onMeta, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 25, 'revenue' => 2500]);
        $this->now($onSnap, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 25, 'revenue' => 2501]);

        $best = $this->pulse()['best_platform']['items'][0];

        $this->assertTrue($best['tied']);
        $this->assertNull($best['winner'], 'a winner was declared on a difference the reader cannot see');
    }

    /** A real difference still names a winner — the tolerance must not swallow the finding. */
    public function test_a_platform_that_genuinely_did_better_is_still_named(): void
    {
        $group = CreativeGroup::create([
            'project_id' => $this->project->getKey(), 'name' => 'Clearly better somewhere', 'method' => 'manual',
        ]);

        $onMeta = $this->creative(['name' => 'Clearly better somewhere', 'provider' => 'meta', 'creative_group_id' => $group->getKey()]);
        $onSnap = $this->creative(['name' => 'Clearly better somewhere', 'provider' => 'snapchat', 'creative_group_id' => $group->getKey()]);

        $this->now($onMeta, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 25, 'revenue' => 2500]);
        $this->now($onSnap, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 25, 'revenue' => 3000]);

        $best = $this->pulse()['best_platform']['items'][0];

        $this->assertFalse($best['tied']);
        $this->assertSame('snapchat', $best['winner']);
    }

    /** The section says how old its numbers are and counts what is missing behind them. */
    public function test_the_section_reports_its_own_freshness_and_what_is_missing(): void
    {
        $synced = $this->creative(['name' => 'Synced', 'last_synced_at' => now()->subHours(2)]);
        $never = $this->creative(['name' => 'Never synced', 'provider' => 'tiktok', 'last_synced_at' => null]);
        $withheld = $this->creative([
            'name' => 'Token link', 'last_synced_at' => now()->subHours(5),
            'asset_url' => 'https://cdn.example.com/a.jpg?access_token=secret',
        ]);

        $this->now($synced, ['spend' => 100, 'impressions' => 10000]);
        $this->now($withheld, ['spend' => 100, 'impressions' => 10000]);

        $freshness = $this->pulse()['freshness'];

        $this->assertNotNull($freshness['last_synced_at']);
        $this->assertSame(1, $freshness['quality']['never_synced']);
        $this->assertSame(1, $freshness['quality']['without_metrics']);
        $this->assertSame(1, $freshness['quality']['previews_withheld'], 'a link carrying a credential was not counted as withheld');

        $providers = collect($freshness['providers'])->keyBy('provider');
        $this->assertSame(2, $providers['meta']['creatives']);
        $this->assertNull($providers['tiktok']['last_synced_at']);
        $this->assertNotNull($never->getKey());
    }

    // ---- the filters, the ceiling, and the cost ------------------------------------------------

    /**
     * Changing a filter changes the answer — it is the library's query, not a second one.
     *
     * This is §15.11's real requirement. A dashboard section on its own query looks right until the
     * operator filters by platform, clicks through to the library, and finds a different creative at
     * the top of the page the card promised.
     */
    public function test_a_filter_changes_the_answer_the_same_way_it_changes_the_library(): void
    {
        $meta = $this->creative(['name' => 'Meta image', 'provider' => 'meta']);
        $tiktok = $this->creative(['name' => 'TikTok image', 'provider' => 'tiktok']);

        $this->now($meta, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 10, 'revenue' => 1000]);
        $this->now($tiktok, ['spend' => 500, 'impressions' => 25000, 'clicks' => 500, 'conversions' => 50, 'revenue' => 5000]);

        $everything = collect($this->pulse()['best_by_objective'])->firstWhere('objective', 'sales');
        $this->assertSame('TikTok image', $everything['creative']['name']);

        $onlyMeta = $this->pulse('&providers[]=meta');
        $this->assertSame('Meta image', collect($onlyMeta['best_by_objective'])->firstWhere('objective', 'sales')['creative']['name']);
        $this->assertSame(1, $onlyMeta['totals']['creatives']);

        // The objective axis narrows it to nothing, and nothing is answered as nothing.
        $onlyAwareness = $this->pulse('&objectives[]=awareness');
        $this->assertSame(0, $onlyAwareness['totals']['creatives']);
        $this->assertSame([], $onlyAwareness['best_by_objective']);
        $this->assertSame(0, $onlyAwareness['fatigue']['counts']['fatigued']);
    }

    /** The window is a filter too: a creative that only delivered outside it has no figures here. */
    public function test_the_date_range_changes_which_figures_the_section_is_built_from(): void
    {
        $creative = $this->creative(['name' => 'Old runner']);
        $this->day($creative, now()->subDays(60)->toDateString(), ['spend' => 900, 'impressions' => 90000, 'clicks' => 900]);

        $recent = $this->pulse();
        $this->assertSame(0, $recent['totals']['with_metrics']);
        $this->assertSame(1, $recent['totals']['without_metrics']);

        $wide = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives/pulse?from='.now()->subDays(90)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data');

        $this->assertSame(1, $wide['totals']['with_metrics']);
    }

    /**
     * The ceiling is the membership's here too — a confined manager's dashboard holds one client.
     *
     * NEGATIVE: the dashboard is the surface where a leak is least likely to be noticed, because
     * nobody reads it as a list. A summary that silently averaged in another client's content would
     * be wrong in a way that never looks wrong.
     */
    public function test_a_manager_confined_to_one_client_gets_a_dashboard_for_that_client_only(): void
    {
        $mine = $this->creative(['name' => 'Mine']);
        $this->now($mine, ['spend' => 100, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 5, 'revenue' => 500]);

        $otherClient = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'Other', 'slug' => 'other-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'Other project', 'status' => 'active',
        ]);
        $theirs = $this->creative(['name' => 'Theirs', 'project_id' => $otherProject->getKey(), 'campaign_id' => null]);
        $this->now($theirs, ['spend' => 90000, 'impressions' => 900000, 'clicks' => 9000, 'conversions' => 900, 'revenue' => 90000]);

        $confined = User::create([
            'name' => 'Confined', 'email' => 'confined@pulse.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'M', 'slug' => 'm-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $confined->assignRole($role);
        $this->grantMembership($confined, $this->tenant, clientIds: [(string) $this->client->getKey()]);

        app(ProjectContext::class)->forget();

        $data = $this->pulse(as: $confined);

        $this->assertSame(1, $data['totals']['creatives']);
        $this->assertEqualsWithDelta(100.0, $data['spend_by_kind'][0]['spend'], 0.01);

        // Naming the other client explicitly does not widen it — the filter narrows within the
        // ceiling, it never replaces it.
        $asked = $this->pulse('&client_ids[]='.$otherClient->getKey(), as: $confined);
        $this->assertSame(0, $asked['totals']['creatives']);
    }

    /** Without `campaigns.view` there is no dashboard section at all. */
    public function test_the_section_is_refused_without_permission_to_view_campaigns(): void
    {
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@pulse.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($stranger, $this->tenant);

        app(ProjectContext::class)->forget();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/creatives/pulse'.$this->window())
            ->assertForbidden();
    }

    /**
     * The cost does not grow with the number of creatives (§15.14).
     *
     * Two hundred creatives cost the same handful of queries as two: the rows come back once, this
     * window and the one before it are one grouped query each, and the campaigns are one more. A
     * per-creative metric lookup would be invisible on a demo project and fatal on a real one, and
     * counting queries is the only way to see it before that happens.
     */
    public function test_two_hundred_creatives_cost_the_same_queries_as_two(): void
    {
        $count = static function (callable $body): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $body();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $two = $this->creative(['name' => 'One']);
        $this->now($two, ['spend' => 10, 'impressions' => 10000, 'clicks' => 100]);
        $this->now($this->creative(['name' => 'Two']), ['spend' => 10, 'impressions' => 10000, 'clicks' => 100]);

        $small = $count(fn () => $this->pulse());

        $rows = [];
        foreach (range(1, 198) as $i) {
            $creative = $this->creative(['name' => "Bulk {$i}", 'format' => $i % 2 === 0 ? 'video' : 'image']);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'creative_id' => $creative->getKey(),
                'campaign_id' => $creative->campaign_id,
                'metric_date' => now()->subDays(2)->toDateString(),
                'spend' => 10, 'impressions' => 10000, 'clicks' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('creative_daily_metrics')->insert($rows);

        $large = $count(fn () => $this->pulse());

        $this->assertSame(200, $this->pulse()['totals']['creatives']);
        $this->assertSame($small, $large, "the section ran {$large} queries for 200 creatives and {$small} for 2");
    }

    /** `creatives/pulse` is a section, not a creative whose id happens to be the word "pulse". */
    public function test_the_project_scoped_address_is_not_read_as_a_creative_id(): void
    {
        $this->now($this->creative(['name' => 'Anything']), ['spend' => 10, 'impressions' => 10000]);

        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/creatives/pulse".$this->window())
            ->assertOk()->json('data');

        $this->assertSame(1, $data['totals']['creatives']);
        $this->assertArrayHasKey('best_by_objective', $data);
    }

    /**
     * CREATIVE-MONEY-TRUTH-001 — the alert must survive an unconvertible currency.
     *
     * «Still spending on fatigued content» is the one line in this section that names something to
     * DO. It was gated on `spend > 0.0`, with a withheld null coerced to zero — so on production,
     * where every Snapchat figure is withheld for want of a USD→SAR rate, the gate was false for
     * every fatigued creative and the warning silently disappeared. A missing warning is worse than
     * a wrong number: nothing on the screen says it is missing.
     *
     * Delivery is the same quantity of money whichever currency it can be stated in, so the alert
     * fires and the figure is carried as an original with its currency named.
     */
    public function test_a_fatigued_creative_still_alerts_when_its_spend_cannot_be_converted(): void
    {
        $tired = $this->creative(['name' => 'Tired but unconvertible']);

        foreach (range(2, 8) as $offset) {
            $this->day($tired, now()->subDays($offset)->toDateString(), [
                // Withheld exactly as the pipeline writes it: no converted value, the original kept.
                'spend' => null, 'spend_original' => 200, 'original_currency' => 'USD', 'project_currency' => 'SAR',
                'impressions' => 20000, 'clicks' => 100, 'conversions' => 1, 'revenue' => null, 'frequency' => 6.0,
            ]);
            $this->day($tired, now()->subDays($offset + 30)->toDateString(), [
                'spend' => null, 'spend_original' => 100, 'original_currency' => 'USD', 'project_currency' => 'SAR',
                'impressions' => 20000, 'clicks' => 400, 'conversions' => 20, 'revenue' => null, 'frequency' => 2.0,
            ]);
        }

        $fatigue = $this->pulse()['fatigue'];

        $this->assertSame(1, $fatigue['counts']['fatigued']);
        $this->assertSame(
            1,
            $fatigue['alerts']['total'],
            'The warning vanished for every creative on an unquoted currency.',
        );

        $risk = $fatigue['spend_at_risk'];

        $this->assertNull($risk['spend'], 'There is no figure in the project currency to state.');
        $this->assertEqualsWithDelta(1400.0, (float) $risk['spend_original'], 0.01);
        $this->assertSame('USD', $risk['money_original_currency']);
        $this->assertSame(1, $risk['money_original_currencies'], 'One currency, so it can be named exactly.');
        $this->assertSame(7, $risk['spend_withheld_rows']);
    }
}
