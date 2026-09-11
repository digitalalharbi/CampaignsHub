<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CanonicalObjective;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONTENT-FILTER-TRUTH-001 — the Content Library speaks the product's objectives, not the platforms'.
 *
 * The owner's screenshot shows the objective picker offering «الوعي · التحويلات · المبيعات ·
 * الزيارات» — RAW provider objectives, with `conversions` standing beside `sales` as if a reader had
 * to choose between them. `canonicalObjectives.ts` already records why that is wrong and what
 * replaced it everywhere else: five product objectives, each expanding into the raw values the server
 * actually filters by, `sales` covering `sales / conversions / add_to_cart / purchases`.
 *
 * Content never made that move. It offered the raw list AND a second «المسار التسويقي» control over
 * the same axis, which is the exact duplication ANALYTICS-OBJECTIVE-SYSTEM-001 removed from Analytics
 * — so the two surfaces disagreed about what an objective even is, and a reader choosing «المبيعات»
 * on Content silently excluded every conversions campaign.
 *
 * That is the mechanism behind the report: `Snapchat · Video · Sales` returned nothing partly because
 * «Sales» meant one raw value out of four.
 */
final class ContentObjectiveTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    /** One Snapchat video per raw objective in the sales family, plus a leads campaign to exclude. */
    private function estate(): void
    {
        foreach (['sales', 'conversions', 'add_to_cart', 'purchases', 'leads'] as $objective) {
            $campaign = UnifiedCampaign::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => 'snapchat',
                'external_id' => 'c-'.$objective,
                'name' => 'Campaign '.$objective,
                'status' => 'active',
                'objective' => $objective,
            ]);

            ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'campaign_id' => $campaign->id,
                'provider' => 'snapchat',
                'external_creative_id' => 'ec-'.$objective,
                'name' => 'Creative '.$objective,
                /* Snapchat's own format string, carrying a film — what the owner's estate holds. */
                'format' => 'SNAP_AD',
                'video_url' => 'https://cdn.test/'.$objective.'.mp4',
            ]);
        }
    }

    /** The filter bar's own options, under the filters currently applied. */
    private function filterBarOptions(array $filters): array
    {
        return app(CreativeRows::class)->filterOptions(fn () => ExternalCreative::query(), $filters);
    }

    private function rows(array $filters): array
    {
        $query = ExternalCreative::query();
        app(CreativeRows::class)->applyFilters($query, $filters);

        return $query->pluck('name')->sort()->values()->all();
    }

    public function test_canonical_sales_reaches_every_raw_objective_in_the_sales_family(): void
    {
        $this->estate();

        $names = $this->rows(['objectives' => ['sales']]);

        $this->assertSame(
            ['Creative add_to_cart', 'Creative conversions', 'Creative purchases', 'Creative sales'],
            $names,
            'canonical «Sales» reached one raw objective instead of its family — a conversions campaign is a sale',
        );
    }

    /** The owner's exact scope, end to end: Snapchat + Video + Sales must not be empty. */
    public function test_the_owners_scope_returns_the_snapchat_sales_videos(): void
    {
        $this->estate();

        $names = $this->rows([
            'providers' => ['snapchat'],
            'kinds' => ['video'],
            'objectives' => ['sales'],
        ]);

        $this->assertCount(4, $names, 'Snapchat + Video + Sales answered empty over an estate that holds four');
    }

    /** Removing one filter WIDENS the result — a narrowing that cannot be undone is not a filter. */
    public function test_dropping_the_objective_widens_the_result(): void
    {
        $this->estate();

        $withObjective = $this->rows(['providers' => ['snapchat'], 'kinds' => ['video'], 'objectives' => ['sales']]);
        $without = $this->rows(['providers' => ['snapchat'], 'kinds' => ['video']]);

        $this->assertGreaterThan(count($withObjective), count($without));
        $this->assertContains('Creative leads', $without);
    }

    /** A raw value still works: other callers send them, and a canonical-only reader would break them. */
    public function test_a_raw_objective_still_narrows_to_itself(): void
    {
        $this->estate();

        $this->assertSame(['Creative conversions'], $this->rows(['objectives' => ['conversions']]));
    }

    /**
     * And the OPTIONS offer the five product objectives, never the raw ones.
     *
     * Asserted on what the picker is handed rather than on what it draws: a control can only offer
     * what it is given, and this is where «التحويلات» came from.
     */
    public function test_the_options_offer_canonical_objectives_and_no_raw_conversions(): void
    {
        $this->estate();

        $options = $this->filterBarOptions([]);

        $keys = array_column($options['objectives'], 'key');

        $this->assertSame(
            ['awareness_engagement', 'traffic', 'leads', 'app_promotion', 'sales'],
            array_values(array_intersect(
                array_map(static fn (CanonicalObjective $c): string => $c->value, CanonicalObjective::selectable()),
                $keys,
            )),
        );

        $this->assertNotContains('conversions', $keys, 'raw «conversions» is offered beside «sales» as a competing choice');
        $this->assertNotContains('purchases', $keys);
        $this->assertNotContains('add_to_cart', $keys);
    }

    /** The «المسار التسويقي» control is gone: one axis, one question. */
    public function test_the_marketing_path_control_is_no_longer_offered(): void
    {
        $options = $this->filterBarOptions([]);

        $this->assertArrayNotHasKey('paths', $options, 'Content still offers a second primary control over the objective axis');
    }

    /** Each canonical option carries how many rows it can actually reach in the current scope. */
    public function test_each_objective_option_carries_its_reachable_count(): void
    {
        $this->estate();

        $options = $this->filterBarOptions(['providers' => ['snapchat'], 'kinds' => ['video']]);

        $byKey = collect($options['objectives'])->keyBy('key');

        $this->assertSame(4, $byKey['sales']['count']);
        $this->assertSame(1, $byKey['leads']['count']);
        $this->assertSame(0, $byKey['app_promotion']['count'], 'an option with nothing behind it must say so rather than look selectable');
    }
}
