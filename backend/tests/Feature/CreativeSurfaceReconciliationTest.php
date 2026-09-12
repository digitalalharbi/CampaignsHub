<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportCreativeMedia;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CREATIVE-SURFACE-RECONCILIATION-001 — one creative, one window, one set of answers.
 *
 * ## Why this is asserted between surfaces rather than against constants
 *
 * «For the SAME creative and SAME scope verify: Content card = quick popup = Content Analytics =
 * Analytics content table = Detailed Report, for preview identity, Spend, Impressions, Clicks, CTR,
 * Results, Cost per Result, Revenue and ROAS.»
 *
 * A test that checked each surface against 3,000 would pass while every surface returned 3,000 by a
 * different route and disagreed the moment one of those routes changed. What the owner asked for is
 * AGREEMENT, so agreement is what is asserted: the figures are read off the surfaces and compared
 * with each other. The one constant is the fixture, and it is deliberately awkward — a spend that
 * does not divide evenly, a revenue that makes ROAS a recurring decimal — because two surfaces that
 * round differently agree on 100 and disagree on 3,333.33.
 *
 * ## The preview is part of the identity, not a decoration
 *
 * REPORT-CREATIVE-MEDIA-001 was an owner production defect: a report claiming «لا يوجد غلاف» for a
 * creative the library was showing. The fix resolves report media through the same
 * `CreativePresenter` the library uses, and the property that makes it a FIX rather than a
 * coincidence is that the two envelopes are identical. That is asserted here, between the surfaces,
 * so a second resolver added later cannot pass by being merely plausible.
 *
 * ## What «no false zero» means here
 *
 * A metric nobody reported must be absent or null on every surface, never 0 on one and null on
 * another — a zero is a claim that something was measured and came to nothing, and a reader cannot
 * tell it from «we do not know» once one surface has written it down.
 */
final class CreativeSurfaceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    private ExternalCreative $creative;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00', 'UTC'));

        $this->from = Carbon::now()->subDays(14)->toDateString();
        $this->to = Carbon::now()->toDateString();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active', 'currency' => 'SAR',
        ]);

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider' => 'meta', 'external_id' => 'c-1', 'name' => 'Campaign',
            'status' => 'active', 'objective' => 'sales',
        ]);

        $this->creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-1',
            'name' => 'Eid film',
            'format' => 'image',
            'asset_url' => 'https://cdn.test/hero.jpg',
        ]);

        /*
         * Figures chosen so a rounding disagreement shows up: CTR is 3/700, CPA is 3333.33/7 and
         * ROAS is 7,777.77/3,333.33 — none of them terminates, and two surfaces that format rather than
         * carry the number will part company on the third decimal.
         */
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $this->creative->id,
            'campaign_id' => $campaign->id,
            'metric_date' => Carbon::now()->subDays(3)->toDateString(),
            'spend' => 3333.33, 'impressions' => 70000, 'clicks' => 300,
            'conversions' => 7, 'revenue' => 7777.77,
            'created_at' => now(), 'updated_at' => now(),
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The Content library listing — the card, and the row the list view draws. */
    private function library(): array
    {
        $query = http_build_query(['from' => $this->from, 'to' => $this->to, 'per_page' => 10]);

        $rows = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/creatives?{$query}")
            ->assertOk()
            ->json('data.creatives');

        return collect($rows)->firstWhere('id', (string) $this->creative->id) ?? [];
    }

    /** The Content Analytics page — what the quick popup's trend also reads. */
    private function detail(): array
    {
        $query = http_build_query(['from' => $this->from, 'to' => $this->to]);

        return $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/creatives/{$this->creative->id}?{$query}")
            ->assertOk()
            ->json('data');
    }

    /** The Detailed Report, through the read path a reader actually gets. */
    private function report(): array
    {
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R', 'type' => 'performance', 'status' => 'completed', 'form' => 'detailed',
            'audience' => 'client', 'generated_at' => now(),
            'period_start' => $this->from, 'period_end' => $this->to,
            'currency' => 'SAR', 'scope' => [],
        ]);

        return app(ReportCreativeMedia::class)->refresh(app(ReportGenerator::class)->generate($report));
    }

    /** The roster row and the ranked row for our creative, out of one generated report. */
    private function reportRows(): array
    {
        $data = $this->report();

        $roster = collect($data['ads_roster'] ?? [])->firstWhere('id', (string) $this->creative->id);
        $ranked = collect($data['ads'] ?? [])->firstWhere('id', (string) $this->creative->id);

        $this->assertIsArray($roster, 'the report roster did not carry the creative at all');
        $this->assertIsArray($ranked, 'the ranked list did not carry the creative at all');

        return [$roster, $ranked];
    }

    /**
     * The preview envelope is IDENTICAL between the library and the report.
     *
     * Not «both have an image» — identical. Two resolvers that happen to agree about a JPEG today
     * are what REPORT-CREATIVE-MEDIA-001 was, one state later.
     */
    public function test_the_report_and_the_library_carry_the_same_preview(): void
    {
        $library = $this->library();
        [$roster, $ranked] = $this->reportRows();

        $this->assertIsArray($library['preview'] ?? null, 'the library row carried no preview');

        $this->assertSame($library['preview'], $roster['preview'] ?? null, 'roster preview differs from the library');
        $this->assertSame($library['preview'], $ranked['preview'] ?? null, 'ranked preview differs from the library');
        $this->assertSame($library['preview'], $this->detail()['creative']['preview'] ?? null, 'detail preview differs');

        // And it is a real one, so the case is not satisfied by three identical absences.
        $this->assertSame('available', $library['preview']['state']);
        $this->assertSame('https://cdn.test/hero.jpg', $library['preview']['image_url']);
    }

    /**
     * The nine figures the owner named, read off each surface and compared with each other.
     */
    public function test_every_surface_reports_the_same_nine_figures(): void
    {
        $library = $this->library()['metrics'] ?? null;
        $detail = $this->detail()['metrics'] ?? null;
        [$roster, $ranked] = $this->reportRows();

        $this->assertIsArray($library, 'the library carried no figures');
        $this->assertIsArray($detail, 'the analytics page carried no figures');

        $surfaces = [
            'content library' => $library,
            'content analytics' => $detail,
            'report roster' => $roster['metrics'] ?? null,
            'report ranked' => $ranked['metrics'] ?? $ranked,
        ];

        foreach (['spend', 'impressions', 'clicks', 'ctr', 'conversions', 'cpa', 'revenue', 'roas'] as $metric) {
            $seen = [];

            foreach ($surfaces as $name => $figures) {
                $this->assertIsArray($figures, "«{$name}» carried no figures at all");

                $value = $figures[$metric] ?? null;

                // «No false zero»: a metric nobody reported is null everywhere or a figure everywhere.
                $seen[$name] = $value === null ? null : round((float) $value, 4);

                $this->assertFalse(
                    is_float($value) && is_nan($value),
                    "«{$name}» reported {$metric} as NaN",
                );
            }

            $distinct = array_values(array_unique($seen, SORT_REGULAR));

            $this->assertCount(
                1,
                $distinct,
                "the surfaces disagree about «{$metric}»: ".json_encode($seen, JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * And the awkward figures really are awkward, or the case above proves nothing.
     *
     * If the fixture happened to produce round numbers, every surface would agree by arithmetic
     * rather than by reading one source, and the comparison would hold with two pipelines in place.
     */
    public function test_the_fixture_is_the_kind_that_would_expose_a_second_pipeline(): void
    {
        $m = $this->library()['metrics'];

        $this->assertEqualsWithDelta(3333.33, (float) $m['spend'], 0.001);

        /*
         * CTR is 300/70,000 and ROAS is 7,777.77/3,333.33 — neither terminates, so a surface that
         * formats rather than carries the number parts company with one that does.
         *
         * CPA is deliberately NOT checked here: 3,333.33/7 is 476.19 exactly, which the first draft
         * of this case asserted was awkward and it is not. An assertion about a fixture has to be
         * true of the fixture.
         */
        $this->assertNotEquals(round((float) $m['ctr'], 2), (float) $m['ctr'], 'CTR terminates — pick a worse fixture');
        $this->assertNotEquals(round((float) $m['roas'], 2), (float) $m['roas'], 'ROAS terminates — pick a worse fixture');
    }

    /**
     * A metric nobody reported is absent on every surface — never zero on one of them.
     *
     * The creative above has no `leads`, and the danger is the COALESCE that turns an unmeasured
     * column into 0 on the surface that sums it while another surface leaves it out. A reader
     * comparing the two has been told two different things about the same silence.
     */
    public function test_an_unreported_metric_is_never_zero_on_one_surface_and_absent_on_another(): void
    {
        $library = $this->library()['metrics'];
        $detail = $this->detail()['metrics'];

        foreach (['leads'] as $metric) {
            $inLibrary = $library[$metric] ?? null;
            $inDetail = $detail[$metric] ?? null;

            $this->assertSame(
                $inLibrary === null,
                $inDetail === null,
                "«{$metric}» is null on one surface and a figure on the other",
            );
        }
    }
}
