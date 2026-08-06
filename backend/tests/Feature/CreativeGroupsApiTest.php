<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\CreativeGroup;
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
 * §15.8 and §15.13 — the same asset on more than one platform, and the person who says so.
 *
 * An agency uploads one film to Snapchat, TikTok and Meta and gets three creative ids back. Read as
 * three rows it is three pieces of content each holding a third of the budget; read as one it is the
 * thing somebody actually made and is deciding about. The claims that matter here:
 *
 *   - **A merge is a judgement, and it is bounded by the same ceiling as a read.** The ids arrive
 *     straight from a browser, so an id outside the caller's reach is dropped on the way IN rather
 *     than merged because it was asked for.
 *   - **Two projects are two clients' books.** Merging across them would put one client's spend
 *     inside another's roll-up, and no later split takes that back out of a report already sent.
 *   - **The roll-up adds what adds.** Spend and impressions sum across platforms; CPA and ROAS do
 *     not sum across OBJECTIVES, so a group whose members disagree about the objective says so and
 *     offers no headline figure at all.
 *   - **The trail outlives the group.** A split that dissolves a group must still have its record,
 *     or the only history kept is of decisions nobody reversed.
 */
final class CreativeGroupsApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $client;

    private Project $project;

    private UnifiedCampaign $sales;

    private UnifiedCampaign $awareness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Group Co', 'slug' => 'group-co', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@group.local', 'password' => 'secret123', 'email_verified_at' => now(),
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

        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);
        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $this->client->getKey(), 'name' => 'Brand', 'objective' => 'awareness', 'status' => 'active',
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
            'name' => 'The film',
            'format' => 'video',
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
            'project_id' => $creative->project_id,
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

    /** @return array{0: ExternalCreative, 1: ExternalCreative} */
    private function twoPlatforms(): array
    {
        $meta = $this->creative(['provider' => 'meta', 'name' => 'The film · Meta']);
        $tiktok = $this->creative(['provider' => 'tiktok', 'name' => 'The film · TikTok']);

        $this->day($meta, now()->subDays(3)->toDateString(), [
            'spend' => 400, 'impressions' => 40000, 'clicks' => 800, 'conversions' => 10, 'revenue' => 3000,
        ]);
        $this->day($tiktok, now()->subDays(3)->toDateString(), [
            'spend' => 100, 'impressions' => 25000, 'clicks' => 300, 'conversions' => 4, 'revenue' => 1200,
        ]);

        return [$meta, $tiktok];
    }

    private function merge(array $ids, ?string $name = null): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')->postJson(
            '/api/v1/creatives/group',
            array_filter(['creative_ids' => $ids, 'name' => $name]),
        );
    }

    // ---- merging ---------------------------------------------------------------------------------

    /** The address the LIBRARY can reach: no project id, because a card does not carry one. */
    public function test_two_platforms_merge_into_one_asset_from_the_library_address(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();

        $body = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()], 'The film')
            ->assertCreated()->json('data');

        $this->assertSame('The film', $body['name']);
        $this->assertSame('manual', $body['method']);
        $this->assertSame((string) $body['id'], (string) $meta->fresh()->creative_group_id);
        $this->assertSame((string) $body['id'], (string) $tiktok->fresh()->creative_group_id);
    }

    /**
     * An unnamed merge takes the name the READER sees, not the raw one.
     *
     * Found live: a group defaulted to «Creative 0 — video» while both its members were labelled
     * «Hero Video» on the same screen, because the cards show `client_display_name` and the group
     * fell back to `name`. One asset appearing under two names is the exact confusion a group exists
     * to remove.
     */
    public function test_an_unnamed_merge_takes_the_name_the_library_displays(): void
    {
        $meta = $this->creative(['provider' => 'meta', 'name' => 'Creative 0 — video', 'client_display_name' => 'Hero Video']);
        $tiktok = $this->creative(['provider' => 'tiktok', 'name' => 'Creative 1 — video', 'client_display_name' => 'Hero Video']);

        $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hero Video');
    }

    /**
     * A selection spanning two projects is refused.
     *
     * Two projects are two clients' books. A group is «the same asset», and one asset cannot belong
     * to two clients — merging them would move one client's spend into the other's roll-up, which no
     * later split takes back out of a report that has already been sent.
     */
    public function test_a_selection_spanning_two_projects_is_refused(): void
    {
        $mine = $this->creative();

        $otherProject = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(),
            'name' => 'P2', 'status' => 'active',
        ]);
        $theirs = $this->creative(['project_id' => $otherProject->getKey(), 'campaign_id' => null]);

        $this->merge([(string) $mine->getKey(), (string) $theirs->getKey()])
            ->assertStatus(422);

        $this->assertNull($mine->fresh()->creative_group_id);
        $this->assertNull($theirs->fresh()->creative_group_id);
    }

    /** A creative outside the caller's ceiling is dropped from the selection, not merged. */
    public function test_a_creative_outside_reach_is_dropped_from_the_selection(): void
    {
        $mine = $this->creative();

        $otherClient = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'P2', 'status' => 'active',
        ]);
        $theirs = $this->creative(['project_id' => $otherProject->getKey(), 'campaign_id' => null]);

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Confined', 'slug' => 'confined-'.uniqid()]);
        $role->givePermissionTo('campaigns.view', 'campaigns.link');
        $confined = User::create([
            'name' => 'AM', 'email' => 'am@group.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($confined, $this->tenant, clientIds: [(string) $this->client->getKey()]);
        $confined->assignRole($role);

        // Two ids asked for, one reachable — so the merge has fewer than two candidates and refuses.
        $this->actingAs($confined, 'sanctum')->postJson('/api/v1/creatives/group', [
            'creative_ids' => [(string) $mine->getKey(), (string) $theirs->getKey()],
        ])->assertStatus(422);

        $this->assertNull($theirs->fresh()->creative_group_id);
    }

    /** Merging is `campaigns.link` — the permission that already means «these two records are one thing». */
    public function test_merging_is_refused_without_the_link_permission(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Viewer', 'slug' => 'viewer-'.uniqid()]);
        $role->givePermissionTo('campaigns.view');
        $viewer = User::create([
            'name' => 'V', 'email' => 'v@group.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/creatives/group', [
            'creative_ids' => [(string) $meta->getKey(), (string) $tiktok->getKey()],
        ])->assertForbidden();
    }

    /** A creative already in a group MOVES, and the group it emptied is dissolved rather than left as a group of one. */
    public function test_moving_a_creative_out_of_a_pair_dissolves_the_group_it_left(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $first = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])->json('data.id');

        $snap = $this->creative(['provider' => 'snapchat']);
        $this->merge([(string) $tiktok->getKey(), (string) $snap->getKey()])->assertCreated();

        $this->assertNull(CreativeGroup::query()->find($first), 'the emptied group survived as a group of one');
        $this->assertNull($meta->fresh()->creative_group_id, 'the last member kept a badge that promises company');
    }

    // ---- the roll-up -----------------------------------------------------------------------------

    /**
     * §15.17 — the group's figures ARE the library's rows, summed.
     *
     * Asserted against the library rather than against a literal, because a literal would still pass
     * if both surfaces drifted together, and the defect this rules out is exactly the two of them
     * disagreeing about one asset.
     */
    public function test_the_group_total_is_the_sum_of_the_rows_the_library_shows(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])->json('data.id');

        $library = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives'.$this->window())->assertOk()->json('data.creatives');
        $fromLibrary = array_sum(array_map(
            static fn (array $r): float => (float) ($r['metrics']['spend'] ?? 0),
            array_filter($library, static fn (array $r): bool => in_array($r['id'], [(string) $meta->getKey(), (string) $tiktok->getKey()], true)),
        ));

        $group = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertOk()->json('data');

        $this->assertEqualsWithDelta($fromLibrary, (float) $group['metrics']['spend'], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $group['metrics']['spend'], 0.001);
        $this->assertEqualsWithDelta(65000.0, (float) $group['metrics']['impressions'], 0.001);
        $this->assertSame(2, $group['creative_count']);
    }

    /** The platform lines add back to the total, because they are the same summation one level down. */
    public function test_the_per_platform_lines_add_back_to_the_group_total(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])->json('data.id');

        $group = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertOk()->json('data');

        $providers = array_column($group['by_platform'], 'provider');
        sort($providers);
        $this->assertSame(['meta', 'tiktok'], $providers);

        $summed = array_sum(array_map(
            static fn (array $line): float => (float) ($line['metrics']['spend'] ?? 0),
            $group['by_platform'],
        ));
        $this->assertEqualsWithDelta((float) $group['metrics']['spend'], $summed, 0.001);
    }

    /**
     * «لا تعلن فائزًا عامًا عند اختلاف الهدف أو المسار» — one level up, at the roll-up.
     *
     * An awareness cut and a sales cut of the same film are the same asset and are not the same
     * question. A blended CPA over the two is the mixing §14 forbids, so the group declares the
     * disagreement and offers NO headline set — the per-platform table is the answer instead.
     */
    public function test_a_group_whose_members_chase_different_objectives_states_no_headline_figure(): void
    {
        $sales = $this->creative(['provider' => 'meta']);
        $brand = $this->creative(['provider' => 'tiktok', 'campaign_id' => $this->awareness->getKey()]);
        $this->day($sales, now()->subDay()->toDateString(), ['spend' => 300, 'impressions' => 9000, 'conversions' => 9, 'revenue' => 2000]);
        $this->day($brand, now()->subDay()->toDateString(), ['spend' => 200, 'impressions' => 80000, 'reach' => 50000]);

        $id = $this->merge([(string) $sales->getKey(), (string) $brand->getKey()])->json('data.id');

        $group = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertOk()->json('data');

        $this->assertTrue($group['mixed_objectives']);
        $this->assertSame([], $group['headline_metrics']);
        $this->assertNull($group['objective']);
        $this->assertNotNull($group['mixed_reason_ar']);
        // The additive figures still add — what is withheld is the JUDGEMENT, not the arithmetic.
        $this->assertEqualsWithDelta(500.0, (float) $group['metrics']['spend'], 0.001);
    }

    /** Sharing one objective is what earns the group that objective's own headline metrics. */
    public function test_a_group_sharing_one_objective_carries_that_objectives_headline_metrics(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])->json('data.id');

        $group = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertOk()->json('data');

        $this->assertFalse($group['mixed_objectives']);
        $this->assertSame('sales', $group['objective']);
        $this->assertNotEmpty($group['headline_metrics']);
        $this->assertContains('roas', $group['headline_metrics']);
        // A sales group is never headlined on a figure its objective does not answer.
        $this->assertNotContains('reach', $group['headline_metrics']);
    }

    /** A metric neither platform reported stays absent from the roll-up rather than summing to zero. */
    public function test_a_metric_neither_platform_reported_stays_null_in_the_roll_up(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])->json('data.id');

        $group = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertOk()->json('data');

        $this->assertNull($group['metrics']['video_p100'], 'a metric nobody sent became a zero');
        $this->assertFalse($group['metrics']['reported']['video_p100']);
        $this->assertTrue($group['metrics']['reported']['spend']);
    }

    // ---- listing and isolation -------------------------------------------------------------------

    public function test_the_groups_list_carries_its_members_platforms_and_its_total(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()], 'The film')->json('data.id');

        $body = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives/groups'.$this->window())->assertOk()->json('data');

        $this->assertSame(1, $body['total']);
        $row = $body['groups'][0];
        $this->assertSame($id, $row['id']);
        $this->assertSame('The film', $row['name']);
        $this->assertTrue($row['confirmed']);
        $this->assertSame(2, $row['creative_count']);
        sort($row['providers']);
        $this->assertSame(['meta', 'tiktok'], $row['providers']);
        $this->assertEqualsWithDelta(500.0, (float) $row['metrics']['spend'], 0.001);
    }

    /**
     * A group in another client is not found — 404, not 403.
     *
     * The group is derived from the members that survived the reach, so there is no second check to
     * forget: a caller who can see none of the members has not found a group.
     */
    public function test_a_group_in_another_client_is_not_found(): void
    {
        $otherClient = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'P2', 'status' => 'active',
        ]);
        $a = $this->creative(['project_id' => $otherProject->getKey(), 'campaign_id' => null, 'provider' => 'meta']);
        $b = $this->creative(['project_id' => $otherProject->getKey(), 'campaign_id' => null, 'provider' => 'tiktok']);
        $id = $this->merge([(string) $a->getKey(), (string) $b->getKey()])->json('data.id');

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Confined', 'slug' => 'confined-'.uniqid()]);
        $role->givePermissionTo('campaigns.view', 'campaigns.link');
        $confined = User::create([
            'name' => 'AM', 'email' => 'am2@group.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($confined, $this->tenant, clientIds: [(string) $this->client->getKey()]);
        $confined->assignRole($role);

        $this->actingAs($confined, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertNotFound();

        $this->actingAs($confined, 'sanctum')
            ->getJson('/api/v1/creatives/groups'.$this->window())->assertOk()->assertJsonPath('data.total', 0);
    }

    // ---- splitting and the trail ------------------------------------------------------------------

    /** Splitting a pair dissolves the group: one creative on one platform is not a cross-platform asset. */
    public function test_splitting_a_pair_dissolves_the_group(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey()])->json('data.id');

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/v1/creatives/{$tiktok->getKey()}/group")
            ->assertOk()->assertJsonPath('data.group_dissolved', true);

        $this->assertNull($meta->fresh()->creative_group_id);
        $this->assertNull(CreativeGroup::query()->find($id));
    }

    /** Splitting one member out of three leaves the other two grouped. */
    public function test_splitting_one_of_three_keeps_the_group(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $snap = $this->creative(['provider' => 'snapchat']);
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey(), (string) $snap->getKey()])->json('data.id');

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/v1/creatives/{$snap->getKey()}/group")
            ->assertOk()->assertJsonPath('data.group_dissolved', false);

        $this->assertNull($snap->fresh()->creative_group_id);
        $this->assertSame($id, (string) $meta->fresh()->creative_group_id);
        $this->assertNotNull(CreativeGroup::query()->find($id));
    }

    public function test_a_creative_that_is_not_grouped_cannot_be_split(): void
    {
        $lone = $this->creative();

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/v1/creatives/{$lone->getKey()}/group")
            ->assertStatus(422);
    }

    /** §15.13 — who decided, and when. Named, not merely recorded. */
    public function test_the_group_carries_the_trail_of_who_merged_it(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();
        $snap = $this->creative(['provider' => 'snapchat']);
        $id = $this->merge([(string) $meta->getKey(), (string) $tiktok->getKey(), (string) $snap->getKey()])->json('data.id');

        $this->actingAs($this->operator, 'sanctum')->deleteJson("/api/v1/creatives/{$snap->getKey()}/group")->assertOk();

        $audit = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/creatives/groups/{$id}".$this->window())->assertOk()->json('data.audit');

        $actions = array_column($audit, 'action');
        $this->assertContains('creative.group.created', $actions);
        $this->assertContains('creative.group.split', $actions);
        foreach ($audit as $entry) {
            $this->assertSame('Op', $entry['actor'], 'an audit entry with no name is not a trail');
            $this->assertNotNull($entry['at']);
        }
    }

    /** The pinned project routes still work — two addresses into one behaviour, not two behaviours. */
    public function test_the_project_pinned_merge_and_split_still_answer(): void
    {
        [$meta, $tiktok] = $this->twoPlatforms();

        $id = $this->actingAs($this->operator, 'sanctum')->postJson(
            "/api/v1/projects/{$this->project->getKey()}/creatives/group",
            ['creative_ids' => [(string) $meta->getKey(), (string) $tiktok->getKey()]],
        )->assertCreated()->json('data.id');

        $this->assertSame($id, (string) $meta->fresh()->creative_group_id);

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->getKey()}/creatives/{$tiktok->getKey()}/group")
            ->assertOk();

        $this->assertNull($tiktok->fresh()->creative_group_id);
    }
}
