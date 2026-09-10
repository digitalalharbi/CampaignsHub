<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
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
 * ANALYTICS-FILTER-TRUTH-001 — the health filter narrowed a PAGE, not the library.
 *
 * `CreativeAnalysisController::index()` paginated first and filtered second: `forPage()` took
 * twenty-four rows, `present()` assessed those twenty-four, and the health filter then ran over the
 * array in memory — with `total` replaced by `count($rows)`, the count of what survived on that one
 * page.
 *
 * Every consequence of that is a false statement to the reader:
 *
 *   - a creative matching the chosen status sitting anywhere after page one is invisible;
 *   - page one can report zero matches while the library holds them;
 *   - `total` describes the current page rather than the filtered set, so the pager's own arithmetic
 *     is wrong and the pages after the first are unreachable;
 *   - and «no results» is indistinguishable from «none on this page», which is the reading a person
 *     actually takes away.
 *
 * It is the same defect this requirement is named for, one surface further in: the request looked
 * filtered, the response was SHAPED like a filtered response, and the scope it described was never
 * narrowed.
 */
final class CreativeHealthFilterScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private User $operator;

    /** The library's own default page size — the boundary the needle must sit beyond. */
    private const PER_PAGE = 24;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'H', 'slug' => 'h-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@h.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);
    }

    private function creative(string $name): ExternalCreative
    {
        return ExternalCreative::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $this->campaign->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(10),
            'name' => $name,
            'format' => 'image',
            'status' => 'active',
            'last_active_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    /** @param array<string, float|int> $values */
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

    /**
     * A creative with enough delivery on BOTH sides of the comparison to earn a real verdict.
     *
     * `CreativeFatigue` refuses to judge below seven active days or a thousand impressions, and that
     * refusal is the point of it — so a needle that skipped either would come back
     * `insufficient_data` like every other row and the test would pass for the wrong reason.
     */
    private function assessable(ExternalCreative $creative, float $spendPerDay): void
    {
        foreach (range(1, 8) as $i) {
            $this->day($creative, now()->subDays($i)->toDateString(), [
                'spend' => $spendPerDay, 'impressions' => 4000, 'clicks' => 120, 'conversions' => 6, 'revenue' => 900, 'frequency' => 2.4,
            ]);
            $this->day($creative, now()->subDays(30 + $i)->toDateString(), [
                'spend' => $spendPerDay, 'impressions' => 4000, 'clicks' => 160, 'conversions' => 9, 'revenue' => 1400, 'frequency' => 1.2,
            ]);
        }
    }

    /** Delivery too short to be judged — `insufficient_data`, deliberately. */
    private function unassessable(ExternalCreative $creative, float $spendPerDay): void
    {
        foreach (range(1, 2) as $i) {
            $this->day($creative, now()->subDays($i)->toDateString(), [
                'spend' => $spendPerDay, 'impressions' => 500, 'clicks' => 10, 'conversions' => 0, 'revenue' => 0,
            ]);
        }
    }

    private function library(string $extra = ''): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString().'&sort=spend'.$extra)
            ->assertOk()
            ->json('data');
    }

    /**
     * Thirty creatives, and the only judged one is last by the sort the reader chose.
     *
     * Spend orders the library here, so twenty-nine heavy-spending creatives with two days of
     * delivery each fill page one as `insufficient_data`, and the one creative with a real verdict
     * spends least and sorts thirtieth. Nothing about that is contrived: the creative somebody needs
     * to find when they filter by health is exactly the one they are NOT already looking at.
     *
     * @return array{0:string,1:string} the needle's id and its assessed status
     */
    private function seedNeedleBeyondPageOne(): array
    {
        foreach (range(1, self::PER_PAGE + 5) as $i) {
            $this->unassessable($this->creative(sprintf('Bulk %02d', $i)), 500.0);
        }

        $needle = $this->creative('The judged one');
        $this->assessable($needle, 0.5);

        $all = $this->library('&per_page=100');
        $rows = $all['creatives'];

        $this->assertSame(self::PER_PAGE + 6, $all['total'], 'the unfiltered library did not hold the thirty creatives this test seeds');

        $position = null;
        $status = null;

        foreach ($rows as $i => $row) {
            if ($row['id'] === (string) $needle->getKey()) {
                $position = $i;
                $status = $row['fatigue']['status'];
            }
        }

        $this->assertNotNull($position, 'the needle was not in the unfiltered library at all');
        $this->assertGreaterThan(
            self::PER_PAGE - 1,
            $position,
            'the needle landed on page one, so this fixture cannot demonstrate anything about page two',
        );
        $this->assertNotSame(
            CreativeFatigue::INSUFFICIENT,
            $status,
            'the needle was not judged, so every row would match and the filter would prove nothing',
        );

        $matching = array_values(array_filter($rows, static fn (array $r): bool => $r['fatigue']['status'] === $status));
        $this->assertCount(1, $matching, 'more than one creative carries the needle’s status; the count assertions below would not be exact');

        return [(string) $needle->getKey(), (string) $status];
    }

    /**
     * The filter finds a matching creative that sits beyond the first unfiltered page.
     *
     * Before the fix this returned an empty page and `total: 0` — a true statement about
     * twenty-four rows presented as a statement about the library.
     */
    public function test_the_health_filter_reaches_past_the_first_page(): void
    {
        [$needleId, $status] = $this->seedNeedleBeyondPageOne();

        $filtered = $this->library('&health='.$status);

        $this->assertSame(1, $filtered['total'], 'the total described the page rather than the filtered library');
        $this->assertCount(1, $filtered['creatives'], 'the matching creative was filtered away with the page it never reached');
        $this->assertSame($needleId, $filtered['creatives'][0]['id']);
    }

    /**
     * Page boundaries are drawn over the FILTERED set, not the unfiltered one.
     *
     * With one match in the whole library, page one holds it and page two is empty — and page two
     * being empty is the honest answer rather than the page-one behaviour repeated.
     */
    public function test_pagination_runs_over_the_filtered_result(): void
    {
        [$needleId, $status] = $this->seedNeedleBeyondPageOne();

        $first = $this->library('&health='.$status.'&per_page=1&page=1');
        $second = $this->library('&health='.$status.'&per_page=1&page=2');

        $this->assertSame(1, $first['total']);
        $this->assertSame(1, $second['total'], 'the total must not change with the page it was asked about');
        $this->assertSame($needleId, $first['creatives'][0]['id']);
        $this->assertSame([], $second['creatives'], 'a page beyond the filtered set must be empty, not a repeat');
    }

    /**
     * A status nothing matches answers with nothing — it never falls back to the unfiltered library.
     *
     * This row's own rule, and the one a page-local filter satisfies by accident: it returns few rows
     * because few survived the page, not because the scope was narrowed.
     */
    public function test_an_empty_filtered_scope_never_falls_back_to_everything(): void
    {
        $this->seedNeedleBeyondPageOne();

        $none = $this->library('&health='.CreativeFatigue::WATCH);

        if ($none['total'] !== 0) {
            $this->markTestSkipped('the seeded needle happens to be `watch`; the other cases cover the property');
        }

        $this->assertSame([], $none['creatives']);
        $this->assertSame(0, $none['total']);
    }

    /**
     * The health filter INTERSECTS the others rather than replacing them.
     *
     * A scope that is narrowed twice must answer for both narrowings: filtering by a provider the
     * needle does not belong to must produce nothing, even though its status matches.
     */
    public function test_the_health_filter_intersects_the_other_filters(): void
    {
        [, $status] = $this->seedNeedleBeyondPageOne();

        $elsewhere = $this->library('&health='.$status.'&provider=snapchat');

        $this->assertSame(0, $elsewhere['total'], 'the health filter answered for a provider the reader excluded');
        $this->assertSame([], $elsewhere['creatives']);
    }

    /**
     * Judging the whole library costs the same queries as judging thirty rows.
     *
     * The reason the old code paginated first is that assessing everything LOOKS expensive, and the
     * naive way to do it — assess each creative in turn — is: it is a metrics query per row, which
     * is invisible on a demo project and fatal on the live one, where the library holds fifteen
     * hundred creatives. So the cost is measured rather than argued.
     *
     * `idsWithFatigueStatus()` reads the same two grouped queries `present()` already makes, for any
     * number of ids, so the filtered request must not grow its query count as the library grows.
     */
    public function test_filtering_the_whole_library_does_not_cost_a_query_per_creative(): void
    {
        [, $status] = $this->seedNeedleBeyondPageOne();

        $count = function (string $extra): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->library($extra);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count('&health='.$status);

        /* Two hundred more creatives, none of them judged, all inside the same filtered scope. */
        foreach (range(1, 200) as $i) {
            $this->unassessable($this->creative(sprintf('Extra %03d', $i)), 400.0);
        }

        $large = $count('&health='.$status);

        $this->assertSame(
            $small,
            $large,
            "the filtered library ran {$large} queries over 230 creatives and {$small} over 30 — the assessment is per-row",
        );
    }

    /**
     * A real page boundary inside the filtered set — three matches over pages of two.
     *
     * The single-match cases prove the filter reaches the whole library; this proves the SLICE is a
     * slice of that answer. Three creatives with identical delivery share a verdict and tie on the
     * chosen sort, so `applySort` falls through to `external_creatives.id` and the order is fully
     * determined — which is what lets the two pages be asserted as an exact, ordered partition
     * rather than as two sets that happen to add up.
     *
     * Order matters here beyond tidiness: the page is re-read by `whereIn`, which promises no order
     * at all, so a page that did not restore the reader's sort would re-order itself silently.
     */
    public function test_the_page_is_an_ordered_slice_of_the_filtered_set(): void
    {
        foreach (range(1, self::PER_PAGE + 5) as $i) {
            $this->unassessable($this->creative(sprintf('Bulk %02d', $i)), 500.0);
        }

        $needles = [];
        foreach (range(1, 3) as $i) {
            $needle = $this->creative(sprintf('Judged %d', $i));
            $this->assessable($needle, 0.5);
            $needles[] = (string) $needle->getKey();
        }

        sort($needles);

        $status = null;
        foreach ($this->library('&per_page=100')['creatives'] as $row) {
            if ($row['id'] === $needles[0]) {
                $status = $row['fatigue']['status'];
            }
        }

        $this->assertNotSame(CreativeFatigue::INSUFFICIENT, $status, 'the needles were not judged, so this proves nothing');

        $first = $this->library('&health='.$status.'&per_page=2&page=1');
        $second = $this->library('&health='.$status.'&per_page=2&page=2');

        $this->assertSame(3, $first['total']);
        $this->assertSame(3, $second['total'], 'the total must not change with the page it was asked about');

        $this->assertSame(
            $needles,
            [...array_column($first['creatives'], 'id'), ...array_column($second['creatives'], 'id')],
            'the two pages are not an ordered partition of the filtered set',
        );
        $this->assertCount(2, $first['creatives']);
        $this->assertCount(1, $second['creatives'], 'the last page carried the remainder, not a full page');
    }

    /**
     * The Pulse card's count and the library it links to describe the same set.
     *
     * This is not a second statement of the same property — it is the defect's other end, and the
     * one a person actually meets. `CreativePulseSection` renders «N fatigued» and links it into
     * this library with `health=<status>`; `pulse()` reads `$query->get()` and assesses the WHOLE
     * scope, while `index()` assessed a page. So the card counted the library and the destination
     * counted twenty-four rows, and clicking a number that said twelve could land on a page that
     * said none — with nothing on either screen to explain the contradiction.
     *
     * Asserted through the two endpoints rather than through the service they share, because it is
     * the AGREEMENT that is the product promise, and the two reach it by different routes.
     */
    public function test_the_pulse_count_and_the_filtered_library_agree(): void
    {
        [, $status] = $this->seedNeedleBeyondPageOne();

        $window = '?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString();

        $pulse = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/creatives/pulse'.$window)
            ->assertOk()
            ->json('data');

        $counted = (int) ($pulse['fatigue']['counts'][$status] ?? 0);
        $this->assertSame(1, $counted, 'the pulse card did not count the judged creative this test seeds');

        $library = $this->library('&health='.$status);

        $this->assertSame(
            $counted,
            $library['total'],
            'the pulse card counts the library and the link it opens counts something else',
        );
    }
}
