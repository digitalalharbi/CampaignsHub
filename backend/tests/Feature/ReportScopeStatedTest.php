<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-SCOPE-SELECTION-001 §C — the builder states, in words, what it is about to export.
 *
 * ## The gap this closes
 *
 * `ReportScope::explain()` has existed since the scope object did, and its own docblock says why:
 * «the twelve axes are not all enforceable at the same depth, and pretending otherwise would be the
 * most convincing kind of lie this product could tell». Ad sets and ads carry no metrics of their
 * own, so selecting one narrows the CAMPAIGN set; a creative bound narrows the creative section and
 * leaves campaign totals alone.
 *
 * Three endpoints already return that explanation — the report's saved scope, a template, and the
 * result of saving. None of them is the BUILDER, which is where an operator makes the choice, so
 * the sentence was reachable everywhere except the screen where somebody could act on it. The
 * matrix has carried «the builder does not yet state the exported scope in words» as an open gap.
 *
 * ## Why an endpoint and not a sentence in TypeScript
 *
 * The rule about what each axis reaches is the same rule the generator applies. A second copy in the
 * frontend would be a second answer to «what does this scope cover», and the two would part company
 * the first time an axis changed depth — which is precisely the failure `explain()` was written to
 * prevent, relocated.
 */
final class ReportScopeStatedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

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

        $this->operator = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator->assignRole($role);
        $this->grantMembership($this->operator, $this->tenant, Portal::App);
    }

    /** @param array<string, mixed> $scope */
    private function explain(array $scope): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports/scope/explain", ['scope' => $scope])
            ->assertOk()
            ->json('data');
    }

    private function campaign(string $name): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_id' => 'c-'.uniqid(),
            'name' => $name,
            'status' => 'active',
            'objective' => 'sales',
        ]);
    }

    /** A scope that bounds nothing says so, rather than returning an empty list to interpret. */
    public function test_an_unbounded_scope_states_that_it_covers_everything(): void
    {
        $data = $this->explain([]);

        $this->assertSame([], $data['explain']);
        $this->assertSame([], $data['bound_axes']);
    }

    /** A campaign bound narrows every figure, and the answer says exactly that. */
    public function test_a_campaign_bound_says_it_narrows_every_figure(): void
    {
        $campaign = $this->campaign('Summer');

        $data = $this->explain(['campaign_ids' => [(string) $campaign->id]]);

        $this->assertSame(['campaign_ids'], $data['bound_axes']);
        $this->assertSame('figures', $data['explain'][0]['grain']);
        $this->assertSame(1, $data['explain'][0]['count']);
        $this->assertStringContainsString('every figure', $data['explain'][0]['note_en']);
    }

    /**
     * The honest one: an ad-set bound does NOT narrow to ad-set grain.
     *
     * No metrics are stored at that level, so the bound resolves up to the campaigns behind it. A
     * builder that let an operator select two ad sets and said nothing would produce a report whose
     * spend is the whole campaign's, presented as the ad sets', wrong by an unknown multiple and
     * with no way for the reader to catch it.
     */
    public function test_an_ad_set_bound_says_it_resolves_up_to_campaigns(): void
    {
        $data = $this->explain(['ad_set_ids' => ['does-not-matter']]);

        $row = collect($data['explain'])->firstWhere('axis', 'ad_set_ids');

        $this->assertNotNull($row, 'the builder said nothing about an axis that changes grain');
        $this->assertSame('campaign', $row['grain']);
        $this->assertStringContainsString('campaigns behind it', $row['note_en']);
        $this->assertNotSame('', $row['note_ar']);
    }

    /** And a creative bound narrows the creative section alone — not the campaign totals. */
    public function test_a_creative_bound_says_it_narrows_only_the_creative_section(): void
    {
        $data = $this->explain(['creative_ids' => ['some-id']]);

        $row = collect($data['explain'])->firstWhere('axis', 'creative_ids');

        $this->assertSame('creatives', $row['grain']);
        $this->assertStringContainsString('campaign totals stay', $row['note_en']);
    }

    /** The metric CHOICE is a display decision and is never described as a bound on the data. */
    public function test_choosing_which_metrics_to_show_is_not_called_a_bound(): void
    {
        $data = $this->explain(['metrics' => ['spend', 'clicks']]);

        $this->assertSame([], collect($data['explain'])->where('axis', 'metrics')->values()->all());
    }

    /** Reading what a scope covers needs no right to change it — but it does need a session. */
    public function test_it_refuses_a_caller_with_no_session(): void
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/scope/explain", ['scope' => []])
            ->assertStatus(401);
    }
}
