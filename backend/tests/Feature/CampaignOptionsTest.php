<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * UX-MULTISELECT-SCALE-001 — the campaign filter's options, searched on the server.
 *
 * The selector was filled from the full metric breakdown. `FilterMulti` already refuses to draw more
 * than 120 rows, so the DOM was safe — but a project with 400 campaigns still shipped 400 complete
 * metric rows over the wire to populate a dropdown, on every filter change, for the reader with the
 * largest estate. That reader is the one this requirement is about.
 */
final class CampaignOptionsTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Opt', 'slug' => 'opt-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@opt.local', 'password' => 'secret123', 'email_verified_at' => now(),
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
    }

    private function campaign(string $name, ?Project $project = null): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => ($project ?? $this->project)->getKey(),
            'name' => $name, 'status' => 'active', 'objective' => 'sales',
            'total_budget' => 100, 'budget_currency' => 'SAR',
        ]);
    }

    /*
     * Named `fetchOptions`, not `options`: `TestCase::options()` is the public HTTP helper, and PHP
     * refuses a subclass narrowing it to private — a fatal at class-load time, before a single
     * assertion runs. `UnifiedFigureConsistencyTest` records the same collision for `get()`.
     */
    private function fetchOptions(string $query = ''): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/campaign-options{$query}")
            ->assertOk()
            ->json('data');
    }

    /** The list carries an id and a name — and deliberately no figures. */
    public function test_it_returns_identities_without_figures(): void
    {
        $this->campaign('Summer sale');

        $data = $this->fetchOptions();

        $this->assertSame(['Summer sale'], array_column($data['options'], 'name'));
        /*
         * A spend on an option row would be a SECOND source for a figure the breakdown on the same
         * screen already reports, and the two would eventually disagree.
         */
        $this->assertSame(['id', 'name'], array_keys($data['options'][0]));
    }

    /** The search runs on the server, so it reaches campaigns the client never downloaded. */
    public function test_it_searches_on_the_server(): void
    {
        $this->campaign('Summer sale');
        $this->campaign('Winter clearance');

        $this->assertSame(['Winter clearance'], array_column($this->fetchOptions('?q=winter')['options'], 'name'));
        // Case-insensitive: an operator typing lowercase is not asking a different question.
        $this->assertSame(['Winter clearance'], array_column($this->fetchOptions('?q=WINTER')['options'], 'name'));
    }

    /**
     * «There are more» is a FACT, not an inference from a full page.
     *
     * A list that silently stops tells a reader their campaign does not exist, which is the failure
     * this whole requirement is about.
     */
    public function test_it_says_when_there_are_more_than_it_returned(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->campaign(sprintf('Campaign %03d', $i));
        }

        $data = $this->fetchOptions();

        $this->assertCount(120, $data['options']);
        $this->assertTrue($data['has_more']);

        // And it does not claim more when the page is merely full to the brim.
        $exact = $this->fetchOptions('?q=Campaign 00');
        $this->assertFalse($exact['has_more']);
    }

    /**
     * NOT windowed by the current range.
     *
     * A campaign that reported nothing this period is frequently the very one an operator is looking
     * for — its silence is the question. Hiding it because the window is narrow makes the filter
     * unable to reach it.
     */
    public function test_a_campaign_with_no_metrics_is_still_selectable(): void
    {
        $this->campaign('Never ran');

        $this->assertSame(['Never ran'], array_column($this->fetchOptions()['options'], 'name'));
    }

    /**
     * Another project's campaigns are not this project's to offer.
     *
     * The property, not the line: `UnifiedCampaign`'s global project scope is what enforces this, and
     * the controller's explicit `project_id` is legibility on top of it. Deleting that line leaves
     * this test green — which is exactly how the two were told apart.
     */
    public function test_it_never_offers_another_projects_campaigns(): void
    {
        $otherClient = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $other = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'P2', 'status' => 'active',
        ]);

        $this->campaign('Mine');
        $this->campaign('Theirs', $other);

        $this->assertSame(['Mine'], array_column($this->fetchOptions()['options'], 'name'));
    }
}
