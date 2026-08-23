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
 * §15 over HTTP — the library, the detail view, the comparison and the grouping.
 *
 * The claims that matter here are the ones a service test cannot make:
 *
 *   - a platform link carrying a credential never reaches a browser;
 *   - filters change the FIGURES, not just the list;
 *   - a comparison across marketing paths refuses an overall winner and says why;
 *   - a wrong automatic grouping can be undone by a person;
 *   - a creative from another project is a 404, not a smaller page.
 */
final class CreativeLibraryApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Project $project;

    private UnifiedCampaign $awareness;

    private UnifiedCampaign $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Creatives HTTP', 'slug' => 'creatives-http', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@creatives.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Brand', 'objective' => 'awareness', 'status' => 'active',
        ]);
        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);
    }

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

    private function url(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/creatives".$suffix;
    }

    private function window(): string
    {
        return '?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString();
    }

    public function test_the_library_lists_creatives_with_figures_that_match_their_objective(): void
    {
        $video = $this->creative(['name' => 'Brand film', 'format' => 'video', 'campaign_id' => $this->awareness->getKey()]);
        $image = $this->creative(['name' => 'Sale image']);

        $this->day($video, now()->subDays(2)->toDateString(), ['spend' => 300, 'impressions' => 90000, 'clicks' => 300, 'video_views' => 40000, 'video_p100' => 9000]);
        $this->day($image, now()->subDays(2)->toDateString(), ['spend' => 500, 'impressions' => 20000, 'clicks' => 600, 'conversions' => 12, 'revenue' => 4200]);

        $rows = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))
            ->assertOk()
            ->json('data.creatives'))->keyBy('name');

        $this->assertContains('completion_rate', $rows['Brand film']['headline_metrics']);
        $this->assertNotContains('cpa', $rows['Brand film']['headline_metrics'], 'the awareness video was asked for a cost per order');

        $this->assertContains('cpa', $rows['Sale image']['headline_metrics']);
        $this->assertEqualsWithDelta(8.4, $rows['Sale image']['metrics']['roas'], 0.01);
    }

    /**
     * A platform link carrying a credential is withheld — never passed to a browser.
     *
     * Several providers sign asset URLs with a parameter derived from the access token. Rendering one
     * puts a credential in a URL bar, a proxy log and a referrer header — and in a client's shared
     * report, in front of somebody outside the company.
     */
    public function test_a_preview_link_carrying_a_credential_is_withheld(): void
    {
        $leaky = $this->creative([
            'name' => 'Signed asset',
            'asset_url' => 'https://cdn.example.com/asset.jpg?access_token=EAAG-secret-token',
        ]);

        $row = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))
            ->assertOk()
            ->json('data.creatives'))->firstWhere('id', (string) $leaky->getKey());

        $this->assertSame('withheld', $row['preview']['state']);
        $this->assertNull($row['preview']['image_url']);

        $body = $this->actingAs($this->operator, 'sanctum')->getJson($this->url($this->window()))->getContent();
        $this->assertStringNotContainsString('EAAG-secret-token', (string) $body, 'the token reached the response body');
    }

    /** An expired platform link says so rather than rendering a broken frame. */
    public function test_an_expired_asset_is_reported_as_expired(): void
    {
        $expired = $this->creative([
            'asset_url' => 'https://cdn.example.com/asset.jpg',
            'asset_expires_at' => now()->subHour(),
        ]);

        $row = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))
            ->assertOk()
            ->json('data.creatives'))->firstWhere('id', (string) $expired->getKey());

        $this->assertSame('expired', $row['preview']['state']);
        $this->assertNull($row['preview']['image_url']);
        $this->assertNotNull($row['preview']['note_ar']);
    }

    /** A platform that exposes no asset gets an honest placeholder, never a fabricated one. */
    public function test_a_creative_with_no_asset_says_the_platform_does_not_expose_it(): void
    {
        $bare = $this->creative(['name' => 'No asset']);

        $row = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))
            ->assertOk()
            ->json('data.creatives'))->firstWhere('id', (string) $bare->getKey());

        $this->assertSame('unavailable', $row['preview']['state']);
    }

    /** Filtering changes the list AND the figures behind it, not a label. */
    public function test_filtering_by_platform_changes_what_is_listed(): void
    {
        $meta = $this->creative(['provider' => 'meta', 'name' => 'Meta one']);
        $this->creative(['provider' => 'tiktok', 'name' => 'TikTok one']);
        $this->day($meta, now()->subDay()->toDateString(), ['spend' => 100, 'impressions' => 5000, 'clicks' => 50]);

        $names = array_column($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window().'&provider[]=meta'))
            ->assertOk()
            ->json('data.creatives'), 'name');

        $this->assertSame(['Meta one'], $names);
    }

    /** The detail view answers with the creative's trend, its peers and its own copy. */
    public function test_the_detail_view_carries_the_trend_and_the_copy(): void
    {
        $creative = $this->creative([
            'name' => 'Hero', 'headline' => 'Half price today', 'cta' => 'SHOP_NOW',
            'body' => 'Everything must go', 'width' => 1080, 'height' => 1080, 'aspect_ratio' => '1:1',
        ]);
        $this->day($creative, now()->subDays(3)->toDateString(), ['spend' => 100, 'impressions' => 5000, 'clicks' => 90, 'conversions' => 4, 'revenue' => 800]);
        $this->day($creative, now()->subDays(2)->toDateString(), ['spend' => 120, 'impressions' => 6000, 'clicks' => 95, 'conversions' => 5, 'revenue' => 900]);

        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/'.$creative->getKey().$this->window()))
            ->assertOk()
            ->json('data');

        $this->assertSame('Half price today', $data['creative']['copy']['headline']);
        $this->assertSame('SHOP_NOW', $data['creative']['copy']['cta']);
        $this->assertSame('1:1', $data['creative']['dimensions']['aspect_ratio']);
        $this->assertCount(2, $data['trend']);
        $this->assertSame('conversion', $data['path']);
        $this->assertEqualsWithDelta(220.0, $data['metrics']['spend'], 0.01);
    }

    /**
     * Comparing across marketing paths refuses an overall winner — and still answers per metric.
     *
     * «Best CTR» is a real answer between an awareness video and a sales image. «Better creative» is
     * not, and §15.7 forbids printing it.
     */
    public function test_comparing_across_objectives_refuses_an_overall_winner_but_still_ranks_each_metric(): void
    {
        $video = $this->creative(['name' => 'Brand film', 'format' => 'video', 'campaign_id' => $this->awareness->getKey()]);
        $image = $this->creative(['name' => 'Sale image']);

        $this->day($video, now()->subDay()->toDateString(), ['spend' => 300, 'impressions' => 90000, 'clicks' => 900]);
        $this->day($image, now()->subDay()->toDateString(), ['spend' => 500, 'impressions' => 20000, 'clicks' => 600, 'conversions' => 12, 'revenue' => 4200]);

        $data = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/compare'.$this->window()), [
                'creative_ids' => [(string) $video->getKey(), (string) $image->getKey()],
            ])
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['comparable']);
        $this->assertNotNull($data['reason_ar']);
        $this->assertArrayNotHasKey('winner', $data, 'an overall winner was declared across two different jobs');

        // Per-metric verdicts still stand: the image has the better CTR (3% vs 1%).
        $this->assertSame((string) $image->getKey(), $data['winners']['ctr']['creative_id']);
        $this->assertSame((string) $video->getKey(), $data['winners']['impressions']['creative_id']);
    }

    /** Two creatives doing the same job compare cleanly. */
    public function test_comparing_within_one_objective_is_comparable(): void
    {
        $a = $this->creative(['name' => 'A']);
        $b = $this->creative(['name' => 'B']);
        $this->day($a, now()->subDay()->toDateString(), ['spend' => 100, 'impressions' => 1000, 'clicks' => 50, 'conversions' => 5, 'revenue' => 700]);
        $this->day($b, now()->subDay()->toDateString(), ['spend' => 100, 'impressions' => 1000, 'clicks' => 20, 'conversions' => 2, 'revenue' => 300]);

        $data = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/compare'.$this->window()), [
                'creative_ids' => [(string) $a->getKey(), (string) $b->getKey()],
            ])
            ->assertOk()->json('data');

        $this->assertTrue($data['comparable']);
        $this->assertSame((string) $a->getKey(), $data['winners']['roas']['creative_id']);
    }

    /** The same asset on two platforms can be grouped, and read as one thing. */
    public function test_the_same_asset_on_two_platforms_can_be_grouped_and_split_again(): void
    {
        $meta = $this->creative(['provider' => 'meta', 'name' => 'Hero video', 'format' => 'video']);
        $tiktok = $this->creative(['provider' => 'tiktok', 'name' => 'Hero video', 'format' => 'video']);

        $group = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/group'), [
                'creative_ids' => [(string) $meta->getKey(), (string) $tiktok->getKey()],
                'name' => 'Hero video',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('manual', $group['method']);
        $this->assertNotNull($meta->refresh()->creative_group_id);

        // The detail view reads the pair as one asset across two platforms.
        $detail = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/'.$meta->getKey().$this->window()))
            ->assertOk()->json('data');

        $this->assertCount(2, $detail['group']['members']);
        $this->assertEqualsCanonicalizing(['meta', 'tiktok'], array_column($detail['by_platform'], 'provider'));

        // …and a wrong grouping can be undone.
        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson($this->url('/'.$meta->getKey().'/group'))
            ->assertOk();

        $this->assertNull($meta->refresh()->creative_group_id);
        // A group of one is not a group: the survivor is released too, rather than left wearing a
        // badge that promises company.
        $this->assertNull($tiktok->refresh()->creative_group_id);
        $this->assertSame(0, CreativeGroup::query()->count());
    }

    /** A creative from another project is a 404 — never a quietly smaller page. */
    public function test_a_creative_from_another_project_is_not_reachable(): void
    {
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->getKey(),
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => 'Other', 'status' => 'active',
        ]);

        $stranger = $this->creative(['project_id' => $otherProject->getKey(), 'name' => 'Not yours']);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/'.$stranger->getKey().$this->window()))
            ->assertNotFound();

        $names = array_column($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))->json('data.creatives'), 'name');

        $this->assertNotContains('Not yours', $names);
    }

    /** Without `campaigns.view` the library is closed. */
    public function test_the_library_needs_permission(): void
    {
        $stranger = User::create([
            'name' => 'S', 'email' => 's@creatives.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($stranger, $this->tenant);

        $this->actingAs($stranger, 'sanctum')->getJson($this->url($this->window()))->assertForbidden();
    }

    /**
     * A second project under a second client, for the library's own scope tests.
     *
     * @return array{0: ClientWorkspace, 1: Project, 2: ExternalCreative}
     */
    private function secondClient(): array
    {
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'Other client', 'slug' => 'other-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'Other project', 'status' => 'active',
        ]);
        $creative = $this->creative([
            'name' => 'Other creative', 'project_id' => $project->getKey(), 'campaign_id' => null,
        ]);

        return [$client, $project, $creative];
    }

    /**
     * `/creatives` spans the caller's whole reach, and the Project filter narrows it (§15.2).
     *
     * The library used to be a separate controller with no project axis at all, so «which project is
     * this creative from?» could not be asked or answered. Spanning is the point — a Client filter
     * over one project is a control with one option.
     */
    public function test_the_library_spans_the_projects_in_reach_and_the_project_filter_narrows_it(): void
    {
        $mine = $this->creative(['name' => 'This project']);
        [, $other, $theirs] = $this->secondClient();

        // The library is not a project page: whatever project this test bound, it must not leak in
        // as a hidden filter, or the assertion below would pass without the reach doing any work.
        app(ProjectContext::class)->forget();

        $names = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window())
            ->assertOk()->json('data.creatives'))->pluck('name');

        $this->assertContains($mine->name, $names);
        $this->assertContains($theirs->name, $names, 'the library stopped at one project');

        $narrowed = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window().'&project_ids[]='.$other->getKey())
            ->assertOk()->json('data.creatives'))->pluck('name');

        $this->assertSame([$theirs->name], $narrowed->all());
    }

    /**
     * The ceiling is the membership's, and asking for another client's project does not lift it.
     *
     * NEGATIVE, and the reason the library reads `ClientScopeResolver` rather than the tenant scope
     * alone: the tenant scope would have let an account manager confined to one client browse the
     * agency's entire content library, which is most of its book of business.
     */
    public function test_a_manager_confined_to_one_client_cannot_reach_another_clients_creatives(): void
    {
        $mine = $this->creative(['name' => 'In scope']);
        [$otherClient, $otherProject, $theirs] = $this->secondClient();

        $confined = User::create([
            'name' => 'Confined', 'email' => 'confined@creatives.local',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'M', 'slug' => 'm-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $confined->assignRole($role);
        $this->grantMembership(
            $confined, $this->tenant, clientIds: [(string) $this->project->client_workspace_id],
        );

        app(ProjectContext::class)->forget();

        $names = collect($this->actingAs($confined, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window())
            ->assertOk()->json('data.creatives'))->pluck('name');

        $this->assertContains($mine->name, $names);
        $this->assertNotContains($theirs->name, $names, 'a confined manager reached another client’s creative');

        // And naming the other client explicitly does not widen it — the filter narrows within the
        // ceiling, it never replaces it.
        $asked = $this->actingAs($confined, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window().'&client_ids[]='.$otherClient->getKey())
            ->assertOk()->json('data.creatives');

        $this->assertSame([], $asked);

        /*
         * Nor does the project-scoped address — and it refuses rather than answering emptily.
         *
         * `ResolveProject` rejects the binding before the controller runs, so this is 403 and not an
         * empty 200. That is the stronger of the two: an empty list is also what a project with no
         * creatives yet looks like, so a leak that regressed into «200 with rows» would have to be
         * caught by counting, whereas a refusal that regressed into a 200 is visible immediately.
         */
        $this->actingAs($confined, 'sanctum')
            ->getJson("/api/v1/projects/{$otherProject->getKey()}/creatives".$this->window())
            ->assertForbidden();

        $this->assertNotNull($theirs->getKey());
    }

    /**
     * A marketing path is a set of objectives, and combining it with an objective INTERSECTS.
     *
     * Applied as two independent `whereIn`s the pair would widen — asking for «sales» and «awareness
     * path» would answer with both, which is the opposite of what adding a second filter means.
     */
    public function test_filtering_by_marketing_path_selects_only_that_paths_objectives(): void
    {
        $brand = $this->creative(['name' => 'Brand film', 'campaign_id' => $this->awareness->getKey()]);
        $sale = $this->creative(['name' => 'Sale image', 'campaign_id' => $this->sales->getKey()]);

        $awareness = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window().'&paths[]=awareness'))
            ->assertOk()->json('data.creatives'))->pluck('name');

        $this->assertContains($brand->name, $awareness);
        $this->assertNotContains($sale->name, $awareness);

        // Contradictory pair: sales objective on the awareness path is nothing, not everything.
        $both = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window().'&paths[]=awareness&objectives[]=sales'))
            ->assertOk()->json('data.creatives');

        $this->assertSame([], $both, 'two filters that cannot both hold answered with rows');
    }

    /** The structural axes §15.2 asks for reach the query, not just the filter panel. */
    public function test_filtering_by_ad_set_and_by_creative_type_narrows_the_list(): void
    {
        $adSet = (string) Str::uuid();
        $inSet = $this->creative(['name' => 'In the ad set', 'external_ad_set_id' => $adSet, 'format' => 'video']);
        $this->creative(['name' => 'Elsewhere', 'format' => 'image']);

        $bySet = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window().'&ad_set_ids[]='.$adSet))
            ->assertOk()->json('data.creatives'))->pluck('name');

        $this->assertSame([$inSet->name], $bySet->all());

        // `kinds` matches the provider's own format string the way the card's badge does, so the
        // filter and the label can never disagree about what a creative is.
        $byKind = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window().'&kinds[]=video'))
            ->assertOk()->json('data.creatives'))->pluck('name');

        $this->assertSame([$inSet->name], $byKind->all());
    }

    /**
     * Sorting by spend orders the WHOLE set, not the page that happens to be on screen.
     *
     * Sorted in PHP after fetching, this test passes on page one and fails on page two — which is
     * exactly the bug that survives a casual check. Asking for a one-row page makes the difference
     * visible: the top spender must be the row returned, not the most recent creative.
     */
    public function test_sorting_by_spend_orders_across_pages_and_not_within_one(): void
    {
        $cheap = $this->creative(['name' => 'Cheap', 'last_active_at' => now()]);
        $dear = $this->creative(['name' => 'Dear', 'last_active_at' => now()->subDays(5)]);
        $silent = $this->creative(['name' => 'Never reported', 'last_active_at' => now()->subDay()]);

        $this->day($cheap, now()->subDay()->toDateString(), ['spend' => 10, 'impressions' => 100]);
        $this->day($dear, now()->subDay()->toDateString(), ['spend' => 9000, 'impressions' => 100]);

        $first = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window().'&sort=spend&per_page=1&page=1'))
            ->assertOk()->json('data.creatives');

        $this->assertSame($dear->name, $first[0]['name'], 'the top spender was not the first row');

        // Three rows over three single-row pages, each one distinct: a sort with ties repeats and
        // skips rows across pages, and the reader sees neither happen.
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $rows = $this->actingAs($this->operator, 'sanctum')
                ->getJson($this->url($this->window()."&sort=spend&per_page=1&page={$page}"))
                ->assertOk()->json('data.creatives');
            $seen[] = $rows[0]['name'];
        }

        $this->assertCount(3, array_unique($seen), 'paging a sorted library repeated or skipped a creative');
        // A creative the platform reported nothing for is last, not cheapest.
        $this->assertSame($silent->name, $seen[2]);
    }

    /**
     * The two addresses are one pipeline: same creative, same window, same figures (§15.21).
     *
     * This is the assertion the old `CreativeLibraryController` could not have passed. It summed the
     * same table with its own SQL and coalesced every null to `0`, so the tenant-wide page and the
     * project analysis could disagree about one creative and both look plausible.
     */
    public function test_the_library_and_the_project_endpoint_report_the_same_figures(): void
    {
        $creative = $this->creative(['name' => 'One truth']);
        $this->day($creative, now()->subDays(3)->toDateString(), [
            'spend' => 640, 'impressions' => 51000, 'clicks' => 900, 'conversions' => 16, 'revenue' => 5120,
        ]);

        $scoped = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))
            ->assertOk()->json('data.creatives'))->firstWhere('name', 'One truth');

        app(ProjectContext::class)->forget();

        $wide = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window())
            ->assertOk()->json('data.creatives'))->firstWhere('name', 'One truth');

        $this->assertNotNull($wide, 'the creative was missing from the tenant-wide library');
        $this->assertSame($scoped['metrics'], $wide['metrics']);
        $this->assertSame($scoped['headline_metrics'], $wide['headline_metrics']);
        $this->assertSame($scoped['fatigue']['status'], $wide['fatigue']['status']);
    }

    /** Grouping is a linking decision, not a viewing one — `campaigns.link`, not `campaigns.view`. */
    public function test_grouping_needs_the_manage_permission(): void
    {
        $viewer = User::create([
            'name' => 'V', 'email' => 'v@creatives.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'V', 'slug' => 'v-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);

        $a = $this->creative();
        $b = $this->creative();

        $this->actingAs($viewer, 'sanctum')
            ->postJson($this->url('/group'), ['creative_ids' => [(string) $a->getKey(), (string) $b->getKey()]])
            ->assertForbidden();
    }

    /**
     * SNAP-CREATIVE-METRICS-LIVE-001 — the library must open on the creatives that ran.
     *
     * PostgreSQL sorts NULLs FIRST under `DESC`, and `last_active_at` is null for every creative
     * that has never delivered. With the column finally being written, a plain `DESC` would have
     * opened the page on the creatives with no numbers and pushed the delivering ones underneath —
     * reproducing the empty first page by the opposite route.
     */
    public function test_creatives_that_delivered_sort_above_those_that_never_did(): void
    {
        $neverRan = $this->creative(['name' => 'Never ran', 'last_active_at' => null]);
        $ranOnce = $this->creative(['name' => 'Ran a while ago', 'last_active_at' => now()->subDays(20)]);
        $ranToday = $this->creative(['name' => 'Ran today', 'last_active_at' => now()]);

        $names = collect($this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($this->window()))
            ->assertOk()
            ->json('data.creatives'))
            ->pluck('name')
            ->all();

        $this->assertSame(
            ['Ran today', 'Ran a while ago', 'Never ran'],
            $names,
            'A creative with no delivery must never outrank one that ran.',
        );

        // Guard the fixture itself: the assertion above is only meaningful if the null is real.
        $this->assertNull($neverRan->fresh()?->last_active_at);
        $this->assertNotNull($ranOnce->fresh()?->last_active_at);
        $this->assertNotNull($ranToday->fresh()?->last_active_at);
    }
}
