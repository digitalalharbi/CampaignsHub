<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * §15.6 — the creative details page, over HTTP.
 *
 * The claims worth testing here are the ones that would otherwise be discovered by a customer:
 *
 *   - **A deep link is a link, not a permission.** The page is addressed without a project id, so
 *     the ONLY thing standing between a guessed id and someone else's creative is the reach applied
 *     to the lookup. It answers 404 rather than 403, because a 403 confirms the id exists.
 *   - **The funnel does not invent steps.** A platform that never reports basket adds must not be
 *     rendered as a creative that sold nothing, and the steps it did not send have to be NAMED —
 *     silence about them reads as «this funnel has four stages».
 *   - **The page agrees with the pages it was opened from.** Same creative, same window, same
 *     figures on the library, the dashboard section and here — §15.17's rule stated as a test rather
 *     than as an intention.
 *   - **«We could not tell» survives the round trip.** A metric nobody reported stays null and stays
 *     flagged; a peer comparison is against the same marketing path or it is not made.
 */
final class CreativeDetailPageTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Detail Co', 'slug' => 'detail-co', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@detail.local', 'password' => 'secret123', 'email_verified_at' => now(),
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

        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Brand', 'objective' => 'awareness', 'status' => 'active',
        ]);
        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function creative(array $over = []): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $this->sales->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(6),
            'name' => 'A creative',
            'format' => 'image',
            'status' => 'active',
            'last_active_at' => now(),
        ], $over));
    }

    /** @param array<string, float|int|null> $values */
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

    private function window(): string
    {
        return '?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString();
    }

    /** The address the PAGE opens — no project id, because a library card does not carry one. */
    private function open(ExternalCreative $creative): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/{$creative->getKey()}".$this->window());
    }

    public function test_the_detail_page_opens_without_a_project_id(): void
    {
        $creative = $this->creative(['name' => 'Sale image']);
        $this->day($creative, now()->subDays(2)->toDateString(), [
            'spend' => 500, 'impressions' => 20000, 'clicks' => 600, 'conversions' => 12, 'revenue' => 4200,
        ]);

        $body = $this->open($creative)->assertOk()->json('data');

        $this->assertSame('Sale image', $body['creative']['name']);
        $this->assertSame((string) $this->project->getKey(), $body['project_id']);
        $this->assertEqualsWithDelta(500.0, $body['metrics']['spend'], 0.001);
        // Everything the page needs to say what it is looking at, present rather than assumed.
        foreach (['period', 'previous_period', 'funnel', 'trend', 'weekly', 'insights', 'attribution', 'fatigue'] as $key) {
            $this->assertArrayHasKey($key, $body, "the detail payload is missing {$key}");
        }
    }

    /**
     * The pinned address and the reach-wide one are the same answer.
     *
     * Two routes into one page is exactly how a second implementation appears — and then the report
     * built on one and the page built on the other disagree about a creative while both look right.
     */
    public function test_the_project_pinned_address_answers_identically(): void
    {
        $creative = $this->creative();
        $this->day($creative, now()->subDays(3)->toDateString(), [
            'spend' => 120, 'impressions' => 9000, 'clicks' => 210, 'conversions' => 7, 'revenue' => 900,
        ]);

        $reachWide = $this->open($creative)->assertOk()->json('data');
        $pinned = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/creatives/{$creative->getKey()}".$this->window())
            ->assertOk()->json('data');

        $this->assertSame($reachWide['metrics'], $pinned['metrics']);
        $this->assertSame($reachWide['funnel'], $pinned['funnel']);
        $this->assertSame($reachWide['headline_metrics'], $pinned['headline_metrics']);
        $this->assertSame($reachWide['fatigue']['status'], $pinned['fatigue']['status']);
    }

    /** Another tenant's creative does not exist as far as this address is concerned. */
    public function test_a_creative_in_another_tenant_is_not_found(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $otherClient = ClientWorkspace::create([
            'tenant_id' => $other->getKey(), 'name' => 'OC', 'slug' => 'oc-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $other->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'OP', 'status' => 'active',
        ]);
        $foreign = ExternalCreative::withoutGlobalScopes()->forceCreate([
            'id' => (string) Str::uuid(),
            'tenant_id' => $other->getKey(),
            'project_id' => $otherProject->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-foreign',
            'name' => 'Not yours',
            'format' => 'image',
            'status' => 'active',
        ]);

        $this->open($foreign)->assertNotFound();
    }

    /**
     * A confined account manager cannot reach past their own clients by typing an id.
     *
     * 404 rather than 403 on purpose: a 403 would confirm the creative exists and merely belongs to
     * somebody else, which is precisely the fact the ceiling is there to withhold.
     */
    public function test_a_creative_in_another_client_is_not_found(): void
    {
        $otherClient = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'P2', 'status' => 'active',
        ]);
        $theirs = $this->creative(['project_id' => $otherProject->getKey(), 'campaign_id' => null, 'name' => 'Their creative']);

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Confined', 'slug' => 'confined-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $confined = User::create([
            'name' => 'AM', 'email' => 'am@detail.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        // Named clients are a CEILING that outranks any permission — here it names only the first.
        $this->grantMembership($confined, $this->tenant, clientIds: [(string) $this->client->getKey()]);
        $confined->assignRole($role);

        $this->actingAs($confined, 'sanctum')
            ->getJson("/api/v1/creatives/{$theirs->getKey()}".$this->window())
            ->assertNotFound();

        /*
         * The project-pinned address is refused one step earlier, and harder.
         *
         * Project context guards the whole `/projects/{id}` tree, so the request never reaches the
         * creative lookup — 403 on the PROJECT, which is the stronger statement: it says nothing
         * about whether that creative id exists at all.
         */
        $this->actingAs($confined, 'sanctum')
            ->getJson("/api/v1/projects/{$otherProject->getKey()}/creatives/{$theirs->getKey()}".$this->window())
            ->assertForbidden();
    }

    /**
     * §15.6 — «لا تختلق مراحل غير مرسلة».
     *
     * Meta on a search-style creative reports impressions and clicks and nothing else in the basket.
     * Drawing add-to-cart and checkout at zero would say 600 people reached a page and none of them
     * put anything in a basket, which is a claim about the creative made out of the platform's
     * silence.
     */
    public function test_the_funnel_omits_the_stages_the_platform_never_reported(): void
    {
        $creative = $this->creative();
        $this->day($creative, now()->subDays(2)->toDateString(), [
            'spend' => 400, 'impressions' => 30000, 'clicks' => 600, 'landing_page_views' => 500,
        ]);

        $funnel = $this->open($creative)->assertOk()->json('data.funnel');

        $keys = array_column($funnel['stages'], 'key');
        $this->assertSame(['impressions', 'clicks', 'landing_page_views'], $keys);

        $missing = array_column($funnel['missing'], 'key');
        $this->assertContains('add_to_cart', $missing, 'a stage nobody reported vanished silently');
        $this->assertContains('purchases', $missing);
        $this->assertContains('video_views', $missing);
    }

    /** The rate is against the previous stage that SURVIVED, never against one that is off screen. */
    public function test_a_funnel_rate_is_taken_against_the_stage_above_it(): void
    {
        $creative = $this->creative();
        // No landing-page views reported, so checkout's rate must be against add-to-cart's neighbour
        // in the RENDERED funnel — clicks — rather than against a step that is not there.
        $this->day($creative, now()->subDays(2)->toDateString(), [
            'spend' => 1000, 'impressions' => 50000, 'clicks' => 1000, 'add_to_cart' => 200, 'purchases' => 50,
        ]);

        $stages = collect($this->open($creative)->assertOk()->json('data.funnel.stages'))->keyBy('key');

        $this->assertNull($stages['impressions']['rate_from_previous'], 'the top of a funnel has no rate');
        $this->assertSame('clicks', $stages['add_to_cart']['from_stage']);
        $this->assertEqualsWithDelta(0.2, $stages['add_to_cart']['rate_from_previous'], 0.0001);
        $this->assertSame('add_to_cart', $stages['purchases']['from_stage']);
        $this->assertEqualsWithDelta(0.25, $stages['purchases']['rate_from_previous'], 0.0001);
        // Cost per step, from the same spend — not a share of it.
        $this->assertEqualsWithDelta(20.0, $stages['purchases']['cost_per'], 0.0001);
    }

    /** An awareness creative is not asked for a cost per order it was never bought to produce. */
    public function test_an_awareness_creative_is_not_judged_on_cpa_or_roas(): void
    {
        $video = $this->creative([
            'name' => 'Brand film', 'format' => 'video', 'campaign_id' => $this->awareness->getKey(),
        ]);
        $this->day($video, now()->subDays(2)->toDateString(), [
            'spend' => 900, 'impressions' => 400000, 'clicks' => 1200, 'video_views' => 180000, 'video_p100' => 40000,
        ]);

        $body = $this->open($video)->assertOk()->json('data');

        $this->assertSame('awareness', $body['path']);
        $this->assertNotContains('cpa', $body['headline_metrics']);
        $this->assertNotContains('roas', $body['headline_metrics']);
        $this->assertContains('completion_rate', $body['headline_metrics']);
    }

    /**
     * §15.17 as a contract: the library, the dashboard section and this page cannot disagree.
     *
     * Not a reconciliation — they read the same service, and this asserts that they still do. A
     * figure that drifts here is a second aggregation having appeared somewhere.
     */
    public function test_the_figures_match_the_library_and_the_dashboard_for_the_same_window(): void
    {
        $creative = $this->creative(['name' => 'Contract creative']);
        foreach ([2, 3, 4] as $ago) {
            $this->day($creative, now()->subDays($ago)->toDateString(), [
                'spend' => 100 * $ago, 'impressions' => 1000 * $ago, 'clicks' => 20 * $ago,
                'conversions' => $ago, 'revenue' => 500 * $ago,
            ]);
        }

        $detail = $this->open($creative)->assertOk()->json('data');

        $library = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window())
            ->assertOk()->json('data.creatives'))
            ->firstWhere('id', (string) $creative->getKey());

        $pulse = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives/pulse'.$this->window())
            ->assertOk()->json('data');

        foreach (['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'cpa', 'roas'] as $key) {
            $this->assertEqualsWithDelta(
                (float) $library['metrics'][$key],
                (float) $detail['metrics'][$key],
                0.0001,
                "the library and the detail page disagree about {$key}",
            );
        }

        $this->assertSame($library['fatigue']['status'], $detail['fatigue']['status']);
        $this->assertSame($library['headline_metrics'], $detail['headline_metrics']);
        // And the dashboard section's spend — the only creative in this project — is the same money.
        $dashboardSpend = array_sum(array_map(
            static fn (array $row): float => (float) $row['spend'],
            $pulse['spend_by_kind'],
        ));
        $this->assertEqualsWithDelta(
            (float) $detail['metrics']['spend'],
            $dashboardSpend,
            0.0001,
            'the dashboard section and the detail page disagree about spend',
        );
    }

    /** The weeks are rolled up from the daily rows already fetched — no zero invented for silence. */
    public function test_the_weekly_rollup_sums_the_days_and_keeps_nulls(): void
    {
        $creative = $this->creative(['format' => 'video']);
        $this->day($creative, now()->subDays(3)->toDateString(), ['spend' => 100, 'impressions' => 1000, 'clicks' => 10]);
        $this->day($creative, now()->subDays(2)->toDateString(), ['spend' => 150, 'impressions' => 2000, 'clicks' => 20]);

        $weekly = $this->open($creative)->assertOk()->json('data.weekly');

        $this->assertNotEmpty($weekly);
        $total = array_sum(array_map(static fn (array $w): float => (float) $w['spend'], $weekly));
        $this->assertEqualsWithDelta(250.0, $total, 0.0001);
        // Nobody reported a video view on either day, so no week claims zero of them.
        foreach ($weekly as $week) {
            $this->assertNull($week['video_views'], 'a week invented zero video views out of silence');
        }
    }

    /**
     * A benchmark is of content doing the SAME job, or it is not a benchmark.
     *
     * The peer set used to be «every creative in the project whose campaign exists», so an awareness
     * video's CPM was averaged against sales images and the page reported «above average» about a
     * comparison nobody should act on.
     */
    public function test_peers_are_taken_from_the_same_marketing_path_only(): void
    {
        $video = $this->creative(['name' => 'Brand film', 'format' => 'video', 'campaign_id' => $this->awareness->getKey()]);
        $sibling = $this->creative(['name' => 'Second brand film', 'format' => 'video', 'campaign_id' => $this->awareness->getKey()]);
        $salesImage = $this->creative(['name' => 'Sale image']);

        foreach ([$video, $sibling, $salesImage] as $c) {
            $this->day($c, now()->subDays(2)->toDateString(), ['spend' => 100, 'impressions' => 10000, 'clicks' => 100]);
        }

        $peers = $this->open($video)->assertOk()->json('data.peers');

        $this->assertSame('awareness', $peers['path']);
        $this->assertSame(1, $peers['count'], 'the sales image was averaged into an awareness benchmark');
    }

    /** The findings are §15.10's, for this creative, and they say what they were compared against. */
    public function test_the_findings_name_the_comparison_they_were_made_against(): void
    {
        $creative = $this->creative(['name' => 'Watched creative']);
        foreach (range(1, 20) as $ago) {
            $this->day($creative, now()->subDays($ago)->toDateString(), [
                'spend' => 200, 'impressions' => 40000, 'clicks' => $ago > 10 ? 1600 : 400,
                'conversions' => 10, 'revenue' => 2000,
            ]);
        }

        $insights = $this->open($creative)->assertOk()->json('data.insights');

        $this->assertSame('conversion', $insights['compared_against']['path']);
        $this->assertGreaterThanOrEqual(1, $insights['compared_against']['creatives']);
        $this->assertFalse($insights['compared_against']['capped'], 'a tiny project reported a capped comparison');

        // Every item is about THIS creative, and carries the context §15.10 requires of it.
        foreach ($insights['items'] as $item) {
            $this->assertSame((string) $creative->getKey(), $item['creative_id']);
            $this->assertSame('rules', $item['generated_by']);
            foreach (['objective', 'path', 'provider', 'period', 'previous_period', 'confidence'] as $key) {
                $this->assertArrayHasKey($key, $item);
            }
        }
    }

    /** Permission is still permission: reading campaigns is what opens this page. */
    public function test_the_page_is_refused_without_the_campaign_view_permission(): void
    {
        $creative = $this->creative();

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'None', 'slug' => 'none-'.uniqid()]);
        $role->givePermissionTo('clients.view');
        $stranger = User::create([
            'name' => 'S', 'email' => 's@detail.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($stranger, $this->tenant);
        $stranger->assignRole($role);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/creatives/{$creative->getKey()}".$this->window())
            ->assertForbidden();
    }
    // ---- carousels ------------------------------------------------------------------------------

    /**
     * §15 — a carousel shows its cards, or says the platform sent none.
     *
     * The columns a creative syncs into are singular, so a five-card carousel poured into them keeps
     * the FIRST card and drops the rest. Every surface then rendered a fifth of what ran with nothing
     * on screen saying so — a wrong answer, not a missing feature.
     */
    public function test_a_carousel_carries_its_cards_each_with_its_own_copy_and_destination(): void
    {
        $creative = $this->creative([
            'name' => 'Bundle carousel',
            'format' => 'carousel',
            'cards' => [
                ['image_url' => 'https://cdn.example.com/a.jpg', 'headline' => 'Card one', 'body' => 'First', 'cta' => 'SHOP_NOW', 'destination_url' => 'https://shop.example.com/a'],
                ['image_url' => 'https://cdn.example.com/b.jpg', 'headline' => 'Card two', 'body' => 'Second', 'cta' => 'SHOP_NOW', 'destination_url' => 'https://shop.example.com/b'],
                ['video_url' => 'https://cdn.example.com/c.mp4', 'headline' => 'Card three'],
            ],
        ]);
        $this->day($creative, now()->subDay()->toDateString(), ['spend' => 100, 'impressions' => 5000]);

        $preview = $this->open($creative)->assertOk()->json('data.creative.preview');

        $this->assertTrue($preview['cards_reported']);
        $this->assertCount(3, $preview['cards']);
        $this->assertSame('Card one', $preview['cards'][0]['headline']);
        $this->assertSame('https://shop.example.com/b', $preview['cards'][1]['destination_url']);
        // A video card is a video card — the kind is per-card, not per-creative.
        $this->assertSame('image', $preview['cards'][0]['kind']);
        $this->assertSame('video', $preview['cards'][2]['kind']);
        $this->assertSame(0, $preview['cards_withheld']);
    }

    /** «This platform sent no card breakdown» is not «this carousel has no cards». */
    public function test_a_creative_with_no_card_breakdown_says_so_rather_than_showing_an_empty_carousel(): void
    {
        $creative = $this->creative(['format' => 'carousel']);
        $this->day($creative, now()->subDay()->toDateString(), ['spend' => 50, 'impressions' => 900]);

        $preview = $this->open($creative)->assertOk()->json('data.creative.preview');

        $this->assertFalse($preview['cards_reported']);
        $this->assertNull($preview['cards'], 'an unreported breakdown became an empty list');
    }

    /**
     * A card link is a platform link, and the guard applies to it too.
     *
     * Withholding the parent's URL for carrying a credential and then passing the children's straight
     * through would have made the card list the leak — the same signed links, one level down.
     */
    public function test_a_card_link_carrying_a_credential_is_withheld_and_counted(): void
    {
        $creative = $this->creative([
            'format' => 'carousel',
            'cards' => [
                ['image_url' => 'https://cdn.example.com/clean.jpg', 'headline' => 'Shown'],
                ['image_url' => 'https://cdn.example.com/x.jpg?access_token=SECRET-VALUE', 'headline' => 'Withheld'],
            ],
        ]);
        $this->day($creative, now()->subDay()->toDateString(), ['spend' => 10, 'impressions' => 400]);

        $body = $this->open($creative)->assertOk();
        $preview = $body->json('data.creative.preview');

        $this->assertCount(1, $preview['cards']);
        $this->assertSame(1, $preview['cards_withheld'], 'a refused card was dropped without being counted');
        // And the credential never reaches the browser in any form.
        $body->assertDontSee('SECRET-VALUE');
    }
}
