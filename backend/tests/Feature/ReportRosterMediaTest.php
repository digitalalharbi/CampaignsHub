<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-CREATIVE-MEDIA-001 — a Detailed Report shows the picture the Content library can show.
 *
 * ## The owner's production report
 *
 * A real Detailed Report, opened on the live site: creative previews missing, and rows reading
 * «لا يوجد غلاف» for creatives whose media the product displays perfectly well in Content. A client
 * reads that document. It says the agency ran ads nobody has a picture of.
 *
 * ## The cause, and it is not a rendering bug
 *
 * `ReportCreativeRoster` draws `<AdPoster preview={row.preview ?? null} />`, and `RosterRow.preview`
 * is OPTIONAL in its type. `CreativeRows::lean()` — which builds every roster row — never sets it.
 * So the field was always `undefined`, `AdPoster` was always handed `null`, and the absence state
 * fired for every creative in the roster whatever its media. Nothing in the type system objected,
 * because `preview?:` says the field may be missing and the renderer's `?? null` says that is fine.
 *
 * `lean()` leaving it out was DELIBERATE and its reason is sound: «no preview, so nothing here can
 * leak a signed URL into a stored document that outlives it». A report snapshot is stored and read
 * months later, and a signed platform URL written into it is dead on arrival — worse, it is a
 * credential sitting in a document shared with a client.
 *
 * ## So the media is resolved when the report is READ, not when it is stored
 *
 * Both constraints are real and they do not conflict once the question is asked at the right time.
 * The snapshot keeps the FIGURES, which must stay historical — a report is a claim about a period
 * and re-pricing it today would be a different claim. The MEDIA is resolved on every open, through
 * `CreativePresenter`, the same resolver the Content library uses and the one that already owns the
 * recovery chain the owner's requirement lists: hero, then the first usable card for a carousel or
 * a collection, then a stated absence. One resolver, no report-only copy, and nothing expiring
 * inside a stored document.
 *
 * Every case below gives the creative media the library would show, and asserts the CLIENT's own
 * payload carries it — through the real share route, past the entity boundary that strips the ids
 * this resolution is keyed on, which is why the refresh has to happen before that boundary runs.
 */
final class ReportRosterMediaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private Report $report;

    private string $token;

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
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider' => 'meta', 'external_id' => 'c-1', 'name' => 'Campaign',
            'status' => 'active', 'objective' => 'sales',
        ]);
    }

    private function creative(array $over = []): ExternalCreative
    {
        $creative = ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $this->campaign->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-'.Str::random(8),
            'name' => 'Creative '.Str::random(4),
            'format' => 'image',
            'asset_url' => 'https://cdn.test/hero.jpg',
        ], $over));

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $creative->id,
            'metric_date' => now()->subDay()->toDateString(),
            'spend' => 100, 'impressions' => 1000, 'clicks' => 20, 'conversions' => 2, 'revenue' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $creative;
    }

    /** Generate a DETAILED report and share it as a snapshot, the way the owner's link was made. */
    private function sharedRoster(): array
    {
        $this->report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R', 'type' => 'performance', 'status' => 'completed', 'form' => 'detailed',
            'audience' => 'client', 'generated_at' => now(),
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'SAR',
            'scope' => [],
        ]);

        $this->report->update(['data' => app(ReportGenerator::class)->generate($this->report)]);

        [, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'snapshot',
            'settings' => ['creatives' => CreativeVisibility::fromArray([
                'creatives' => true, 'video' => true, 'image_zoom' => true,
            ])->toArray()],
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        $this->token = $raw;

        return $this->openRoster();
    }

    /** The roster as the CLIENT receives it, re-reading the same stored snapshot each time. */
    private function openRoster(): array
    {
        return $this->getJson("/api/v1/reports/shared/{$this->token}")
            ->assertOk()
            ->json('data.data.ads_roster') ?? [];
    }

    /**
     * The owner's exact defect: media the library shows, a report row claiming there is none.
     */
    public function test_a_roster_row_carries_the_media_the_content_library_would_show(): void
    {
        $this->creative();

        $roster = $this->sharedRoster();

        $this->assertNotEmpty($roster, 'the report listed no creatives at all');

        $preview = $roster[0]['preview'] ?? null;

        $this->assertIsArray($preview, 'the roster row carried no preview envelope — the client sees «no cover»');
        $this->assertSame('available', $preview['state']);
        $this->assertSame('https://cdn.test/hero.jpg', $preview['image_url']);
    }

    /** A film's poster, so the row shows a frame rather than «فيديو بلا غلاف». */
    public function test_a_video_carries_its_poster(): void
    {
        $this->creative([
            'format' => 'video',
            'asset_url' => null,
            'video_url' => 'https://cdn.test/film.mp4',
            'thumbnail_url' => 'https://cdn.test/frame.jpg',
        ]);

        $preview = $this->sharedRoster()[0]['preview'] ?? null;

        $this->assertIsArray($preview);
        $this->assertSame('https://cdn.test/film.mp4', $preview['video_url']);
        $this->assertSame('https://cdn.test/frame.jpg', $preview['thumbnail_url']);
    }

    /**
     * A carousel whose hero is empty and whose CARDS hold the media — the recovery chain the
     * owner's requirement names, reached through the report rather than only through Content.
     */
    public function test_a_carousel_falls_back_to_its_first_usable_card(): void
    {
        $this->creative([
            'format' => 'carousel',
            'asset_url' => null,
            'cards' => [
                ['image_url' => null],
                ['image_url' => 'https://cdn.test/card-2.jpg'],
            ],
        ]);

        $preview = $this->sharedRoster()[0]['preview'] ?? null;

        $this->assertIsArray($preview);
        $this->assertSame('https://cdn.test/card-2.jpg', $preview['image_url']);
    }

    /**
     * And a creative with genuinely nothing says so — the absence state is not removed, it is
     * earned. A test that only proved pictures appear would be satisfied by inventing one.
     */
    public function test_a_creative_with_no_media_anywhere_still_states_its_absence(): void
    {
        $this->creative(['asset_url' => null, 'video_url' => null, 'thumbnail_url' => null]);

        $preview = $this->sharedRoster()[0]['preview'] ?? null;

        $this->assertIsArray($preview, 'even an absence travels as an envelope the renderer can read');
        $this->assertNull($preview['image_url']);
        $this->assertNull($preview['video_url']);
    }

    /**
     * The boundary still holds: media arrives, internal identifiers do not.
     *
     * The refresh is keyed on the creative id and therefore runs BEFORE the client boundary strips
     * it. A fix that attached the picture by keeping the id in the client payload would trade one
     * owner requirement for another.
     */
    public function test_the_media_arrives_without_the_identifiers_the_client_may_not_have(): void
    {
        $this->creative();

        $row = $this->sharedRoster()[0];

        $this->assertArrayHasKey('preview', $row);

        foreach (['id', 'campaign_id', 'campaign_name', 'external_account_id', 'ads'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "the client roster carried «{$key}»");
        }
    }

    /**
     * The figures stay the snapshot's. Media is refreshed on read; money is not.
     *
     * «Snapshot metrics remain historical — do not silently replace historical KPIs with today's
     * values.» This is the case that stops the fix overreaching: the obvious way to give a stored
     * report fresh pictures is to regenerate it on open, and that would quietly re-price a March
     * report in September. Spend lands after the report was made, the SAME link is opened again,
     * and the figure must not have moved while the picture is allowed to.
     */
    public function test_refreshing_the_media_does_not_re_price_the_report(): void
    {
        $creative = $this->creative();

        $before = $this->sharedRoster()[0];
        $this->assertSame(100.0, (float) ($before['metrics']['spend'] ?? 0));

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $creative->id,
            'metric_date' => now()->toDateString(),
            'spend' => 9999, 'impressions' => 1, 'clicks' => 1, 'conversions' => 0, 'revenue' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $after = $this->openRoster()[0];

        $this->assertSame(
            100.0,
            (float) ($after['metrics']['spend'] ?? 0),
            'the stored snapshot was re-priced with spend that landed after it was generated',
        );

        // And the media still came through, so the figure did not stay still by the refresh failing.
        $this->assertSame('https://cdn.test/hero.jpg', $after['preview']['image_url'] ?? null);
    }
}
