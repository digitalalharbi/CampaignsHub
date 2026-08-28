<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 reaching the campaigns list — and ANALYTICS-FILTER-TRUTH-001's rule
 * that a filter scopes the BACKEND query.
 *
 * The dashboard and Analytics offer the five canonical objectives and send the raw objectives each
 * one covers, because the metrics API filters on raw values. The campaigns list took a single raw
 * objective and matched it with `=`, so the same product asked its reader for a canonical objective
 * on two screens and a raw one on a third — and «الوعي والتفاعل» could not be expressed here at all,
 * since it covers four raw objectives and the endpoint accepted one.
 *
 * The fix is the contract the metrics API already has: a comma-separated list of RAW objectives. A
 * single value still works, because a list of one is a list.
 */
final class CampaignObjectiveFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'objf-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'objf-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        foreach (['awareness', 'reach', 'video_views', 'engagement', 'sales', 'leads'] as $objective) {
            UnifiedCampaign::create([
                'tenant_id' => $tenant->id, 'project_id' => $this->project->id,
                'name' => "C-{$objective}", 'status' => 'active', 'objective' => $objective,
            ]);
        }

        app(TenantContext::class)->forget();
    }

    /**
     * The canonical bucket a reader actually picks — four raw objectives, one choice.
     *
     * «الوعي والتفاعل» could not be asked for at all before this: it covers awareness, reach, video
     * views and engagement, and the endpoint matched a single value with `=`.
     */
    public function test_a_list_of_objectives_returns_every_campaign_in_that_bucket(): void
    {
        $names = $this->list('awareness,reach,video_views,engagement');

        sort($names);
        $this->assertSame(['C-awareness', 'C-engagement', 'C-reach', 'C-video_views'], $names);
    }

    /** One objective still works — a list of one is a list. */
    public function test_a_single_objective_still_filters_to_that_objective(): void
    {
        $this->assertSame(['C-sales'], $this->list('sales'));
    }

    /**
     * An objective nobody bought returns nothing — never the unfiltered list.
     *
     * This is the ANALYTICS-FILTER-TRUTH-001 clause that matters most: an empty filtered scope must
     * NEVER fall back to unfiltered. Six campaigns coming back under a heading naming one objective
     * would be the page confidently answering a question it was not asked.
     */
    public function test_an_objective_with_no_campaigns_returns_none_rather_than_all_of_them(): void
    {
        $this->assertSame([], $this->list('app_installs'));
    }

    /** No filter is «every campaign» — the absent parameter has never meant «none». */
    public function test_no_objective_filter_returns_every_campaign(): void
    {
        $this->assertCount(6, $this->list(null));
    }

    /**
     * A blank value is «no filter», not «an objective named empty string».
     *
     * `?objective=` is what a cleared control sends if anything forgets to drop the key, and reading
     * it as a value would empty the page.
     */
    public function test_a_blank_objective_is_not_a_filter(): void
    {
        $this->assertCount(6, $this->list(''));
    }

    /** @return list<string> */
    private function list(?string $objective): array
    {
        $query = $objective === null ? '' : '?objective='.urlencode($objective);

        $names = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns{$query}")
            ->assertOk()
            ->json('data.*.name');

        return array_values($names ?? []);
    }
}
