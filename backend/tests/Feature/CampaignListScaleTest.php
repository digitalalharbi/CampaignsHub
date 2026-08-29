<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «efficient summaries, no N+1», at realistic entity counts.
 *
 * The workspace list is the one screen an operator opens first, on the account with the most
 * campaigns. Two different failures hide behind a page that looks fine on a seeded project of three:
 *
 *   1. **A query per row.** Invisible at three campaigns and the difference between a page and a
 *      timeout at four hundred. Measured here by growing the data and watching the query count,
 *      because that is the only way to tell a constant number of queries from a linear one — a
 *      single measurement cannot.
 *   2. **An unbounded list.** `index()` ended `->get()`, so the response carried every campaign in
 *      the project. That is the cardinality this requirement names, and the harm is not only
 *      weight: a list that returns everything and a list that stops silently are indistinguishable
 *      to the reader, and one of them is about to become the other the first time somebody adds a
 *      limit for performance.
 */
final class CampaignListScaleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'cls-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'cls-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    /**
     * The query count does not grow with the number of campaigns.
     *
     * Compared against itself at two sizes rather than pinned to a literal: a fixed budget would
     * fail on the day an unrelated middleware adds a lookup, and would pass a genuine N+1 that
     * happened to fit under it. What must not change is the SHAPE.
     */
    public function test_listing_campaigns_does_not_cost_a_query_per_campaign(): void
    {
        $this->campaigns(10);
        $small = $this->queriesForListing();

        $this->campaigns(200);
        $large = $this->queriesForListing();

        $this->assertSame(
            $small,
            $large,
            "listing 10 campaigns took {$small} queries and 210 took {$large} — the count grows with the data",
        );
    }

    /**
     * The list is bounded, and says when it stopped.
     *
     * An operator whose campaign is missing has two possible explanations — it was never synced, or
     * the list stopped — and they lead to opposite actions. The response is the only place that
     * knows which.
     */
    public function test_the_list_is_bounded_and_reports_that_it_stopped(): void
    {
        $this->campaigns(501);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns")
            ->assertOk()
            ->json();

        $this->assertCount(500, $body['data'], 'the campaign list is not bounded');
        $this->assertTrue($body['meta']['truncated'], 'the cap was reached and not reported');
        $this->assertSame(500, $body['meta']['limit']);
    }

    /** Exactly at the cap is complete, not truncated — «more» is measured, never inferred. */
    public function test_exactly_at_the_cap_is_not_reported_as_truncated(): void
    {
        $this->campaigns(500);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns")
            ->assertOk()
            ->json();

        $this->assertCount(500, $body['data']);
        $this->assertFalse($body['meta']['truncated'], 'a full page was reported as truncated');
    }

    /** A small project says so too, rather than saying nothing and leaving the client to guess. */
    public function test_a_short_list_reports_that_it_is_complete(): void
    {
        $this->campaigns(3);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns")
            ->assertOk()
            ->json();

        $this->assertCount(3, $body['data']);
        $this->assertFalse($body['meta']['truncated']);
    }

    /** The bound is the QUERY's, not a slice taken after every row was already loaded. */
    public function test_the_cap_is_applied_in_the_query(): void
    {
        $this->campaigns(20);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns")->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $selects = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '"unified_campaigns"'),
        ));

        $this->assertNotEmpty($selects);
        $this->assertTrue(
            (bool) array_filter($selects, static fn (string $sql): bool => str_contains($sql, 'limit')),
            'the campaign list fetches every row and trims afterwards',
        );
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** Names are unique per project, so each call continues where the last one stopped. */
    private int $seeded = 0;

    private function campaigns(int $count): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $rows = [];
        for ($i = $this->seeded; $i < $this->seeded + $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'name' => sprintf('Campaign %04d', $i),
                'objective' => 'sales',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('unified_campaigns')->insert($chunk);
        }

        $this->seeded += $count;

        app(TenantContext::class)->forget();
    }

    private function queriesForListing(): int
    {
        /*
         * FLUSHED, not merely enabled. `enableQueryLog()` appends to whatever is already there, so a
         * second measurement in the same test reads the first one's queries too — which showed a
         * perfect doubling and looked exactly like an N+1 until the shapes were printed and every
         * one of them had doubled, including the ones that cannot depend on the data.
         */
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count($log);
    }
}
