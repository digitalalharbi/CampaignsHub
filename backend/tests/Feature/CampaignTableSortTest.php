<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAIGNS-TABLE-COMPARISON-001 — a comparison table sorts the SET, never the page.
 *
 * «Use strong, professional tables: sortable, filterable, searchable … use tables when comparison is
 * the job.» The campaigns table offered five columns — name, objective, status, budget, linked — and
 * no way to order them, so comparing two campaigns on what they cost meant opening both.
 *
 * The trap this is written against is the obvious fix. The list is server-paginated and
 * server-ranked (#362), so sorting the twenty-five rows the browser happens to hold would answer
 * «the most expensive of the twenty-five most relevant» while looking exactly like an answer about
 * the project. Every case below asserts on WHICH campaigns come back, not on their order within a
 * page, because only the first can tell the two apart.
 */
final class CampaignTableSortTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'Project 1',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user->assignRole($role);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
    }

    /**
     * Thirty campaigns, each spending its index — so the cheapest thirty and the dearest thirty are
     * disjoint sets and a page-local sort cannot fake either.
     */
    private function estate(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $campaign = UnifiedCampaign::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => 'meta',
                'external_id' => 'c-'.$i,
                'name' => sprintf('Campaign %02d', $i),
                'status' => 'active',
                'objective' => 'sales',
            ]);

            /*
             * `daily_metrics` is key/value, one row per metric per day — the wide-column shape a
             * first draft assumes does not exist, and the NOT NULL columns are what say so.
             */
            /*
             * Results run INVERSE to spend on purpose.
             *
             * With both ascending, «by spend» and «by results» produce the same page and the results
             * case passes against a build that only sorts by spend — which it did, before this line.
             * A fixture whose axes agree cannot tell two orderings apart.
             */
            foreach ([['spend', $i * 100], ['conversions', 31 - $i], ['impressions', 1000], ['clicks', 10]] as [$key, $value]) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(),
                    'project_id' => $this->project->id,
                    'external_account_id' => (string) Str::uuid(),
                    'external_campaign_id' => (string) Str::uuid(),
                    'unified_campaign_id' => $campaign->id,
                    'provider' => 'meta',
                    'metric_key' => $key,
                    'metric_date' => now()->subDay()->toDateString(),
                    'value' => $value,
                    'project_currency' => 'SAR',
                ]);
            }
        }
    }

    /** @return list<string> the campaign names on the page, in the order returned */
    private function page(array $params): array
    {
        $query = http_build_query($params + [
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
            'per_page' => 5,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns?{$query}")
            ->assertOk();

        return array_column($response->json('data'), 'name');
    }

    public function test_sorting_by_spend_changes_which_campaigns_are_on_the_first_page(): void
    {
        $this->estate();

        $dearest = $this->page(['sort' => 'spend', 'dir' => 'desc']);
        $cheapest = $this->page(['sort' => 'spend', 'dir' => 'asc']);

        $this->assertSame(
            ['Campaign 30', 'Campaign 29', 'Campaign 28', 'Campaign 27', 'Campaign 26'],
            $dearest,
            'sorting returned a page-local reordering rather than the project\'s dearest campaigns',
        );

        $this->assertSame(
            ['Campaign 01', 'Campaign 02', 'Campaign 03', 'Campaign 04', 'Campaign 05'],
            $cheapest,
        );

        /* The two pages share nothing — which is what makes this a SET sort rather than a page sort. */
        $this->assertSame([], array_intersect($dearest, $cheapest));
    }

    /** Results, the other axis a reader compares on, and the one a budget column cannot answer. */
    public function test_sorting_by_results_ranks_the_whole_project(): void
    {
        $this->estate();

        /* Campaign 01 has the most results and the least spend — the two orders are opposites here. */
        $this->assertSame(
            ['Campaign 01', 'Campaign 02', 'Campaign 03', 'Campaign 04', 'Campaign 05'],
            $this->page(['sort' => 'results', 'dir' => 'desc']),
        );
    }

    /** By name, so a reader looking for one campaign does not have to know what it spent. */
    public function test_sorting_by_name_is_alphabetical_across_the_project(): void
    {
        $this->estate();

        $this->assertSame(
            ['Campaign 01', 'Campaign 02', 'Campaign 03', 'Campaign 04', 'Campaign 05'],
            $this->page(['sort' => 'name', 'dir' => 'asc']),
        );
    }

    /**
     * And the TOTAL never changes, whatever the ordering.
     *
     * A sort that quietly narrowed would be the filter-truth defect wearing a different hat: the page
     * would look ordered and the project would have shrunk.
     */
    public function test_sorting_never_changes_how_many_campaigns_there_are(): void
    {
        $this->estate();

        foreach ([['sort' => 'spend'], ['sort' => 'results'], ['sort' => 'name'], []] as $params) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/v1/projects/{$this->project->id}/campaigns?".http_build_query($params + ['per_page' => 5]))
                ->assertOk();

            $this->assertSame(30, $response->json('meta.total'));
        }
    }

    /**
     * An unknown sort falls back to RELEVANCE rather than to an arbitrary column.
     *
     * A typo in a saved link must not silently reorder somebody's workspace by `created_at` — the
     * ordering #362 replaced, and the one a reader would least expect to be looking at.
     */
    public function test_an_unknown_sort_falls_back_to_relevance(): void
    {
        $this->estate();

        $this->assertSame(
            $this->page([]),
            $this->page(['sort' => 'not_a_column']),
        );
    }
}
