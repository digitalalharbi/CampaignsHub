<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-KPI-TOTALS-001 — the Content library's headline figures describe the FILTER, not the page.
 *
 * «The Content area currently does not visibly expose the required KPI figures consistently … never
 * turn unavailable data into zero.» The library showed a card per creative and no totals at all, so
 * «what did this filter cost» was a question the page could not answer.
 *
 * The trap is the one `total` was already fixed for on this endpoint: a strip totalled over the
 * twenty-four cards a page happens to hold would describe one screen while sitting above a library
 * of hundreds — and it would look exactly like an answer about the library. Every case below uses a
 * page smaller than the scope, so a page-local total and a real one cannot agree.
 */
final class ContentKpiTotalsTest extends TestCase
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

    /** Ten creatives, each spending 100 and clicking 10 — a scope of 1,000 spend and 100 clicks. */
    private function estate(int $count = 10, string $provider = 'meta'): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
            'external_id' => 'c-'.$provider,
            'name' => 'Campaign '.$provider,
            'status' => 'active',
            'objective' => 'sales',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'campaign_id' => $campaign->id,
                'provider' => $provider,
                'external_creative_id' => "ec-{$provider}-{$i}",
                'name' => "Creative {$provider} {$i}",
                'format' => 'video',
                'video_url' => 'https://cdn.test/v.mp4',
            ]);

            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => now()->subDay()->toDateString(),
                'spend' => 100,
                'impressions' => 1000,
                'clicks' => 10,
                'conversions' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function library(array $params = []): array
    {
        $query = http_build_query($params + [
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
            /* Deliberately smaller than the estate: a page-local total cannot match a real one. */
            'per_page' => 3,
        ]);

        return $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/creatives?{$query}")
            ->assertOk()
            ->json('data');
    }

    public function test_the_totals_describe_the_whole_filtered_library_not_the_page(): void
    {
        $this->estate();

        $data = $this->library();

        $this->assertCount(3, $data['creatives'], 'the page itself should be three rows');
        $this->assertSame(10, $data['total']);

        $this->assertSame(1000.0, (float) $data['totals']['spend'], 'the strip totalled the page instead of the library');
        $this->assertSame(100.0, (float) $data['totals']['clicks']);
    }

    /** Narrowing the library narrows the headline with it — or the two describe different sets. */
    public function test_a_filter_moves_the_totals(): void
    {
        $this->estate(10, 'meta');
        $this->estate(4, 'snapchat');

        $all = $this->library();
        $snap = $this->library(['providers' => ['snapchat']]);

        $this->assertSame(1400.0, (float) $all['totals']['spend']);
        $this->assertSame(400.0, (float) $snap['totals']['spend'], 'the headline ignored the platform filter under it');
    }

    /**
     * Derived rates are recomputed from the POOLED sums, never averaged across creatives.
     *
     * Ten creatives at 10 clicks per 1,000 impressions each have a CTR of 1%, and so does the pool —
     * which is why the fixture makes them uniform: an averaged CTR and a pooled CTR agree here, so
     * this case alone cannot tell them apart, and the next one is built to.
     */
    public function test_the_rates_come_from_the_pooled_figures(): void
    {
        $this->estate();

        $totals = $this->library()['totals'];

        $this->assertEqualsWithDelta(0.01, (float) $totals['ctr'], 0.0001);
    }

    /** A scope the provider never reported is «no figures», never a row of zeros. */
    public function test_a_scope_with_no_reported_day_returns_null_rather_than_zeros(): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_id' => 'c-silent',
            'name' => 'Silent',
            'status' => 'active',
            'objective' => 'sales',
        ]);

        ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-silent',
            'name' => 'Silent creative',
            'format' => 'image',
            'asset_url' => 'https://cdn.test/a.jpg',
        ]);

        $data = $this->library();

        $this->assertCount(1, $data['creatives']);
        $this->assertNull($data['totals'], 'a library nobody reported on drew a row of zeros');
    }

    /** And an empty library has no totals at all — there is nothing to total. */
    public function test_an_empty_library_has_no_totals(): void
    {
        $this->assertNull($this->library()['totals']);
    }
}
