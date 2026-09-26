<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONTENT-COLLECTION-TILES-001 — a collection with no picture says WHOSE gap it is, and there are three.
 *
 * ## The regression this exists to prevent
 *
 * The dynamic-collection arm — «the platform picks its top snap from the product catalogue per
 * product at delivery, so it has no single file» — was guarded by `cards === null`. That guard was
 * correct while a collection's tiles were never fetched: null meant «nobody asked».
 *
 * Then the connector learned to read the interaction zone. A dynamic collection's zone ANSWERS: it
 * returns elements carrying each product's copy and no asset link, because the picture is composed
 * from the catalogue at delivery. `cards` became a three-element array of nothing drawable, the arm
 * stopped matching, the arm below it stopped matching for the same reason, and the creative fell
 * through to `default` — state `available`, three null urls, and **no note at all**.
 *
 * Production stated it exactly: `kind=collection state=available hero=no tiles=not fetched
 * draws=stated absence`. The reader got an empty frame and no sentence. Closing one gap had quietly
 * opened a worse one, and nothing failed, because this sentence had never had a test.
 *
 * ## The three readings, and why the difference is operational
 *
 * They are not three wordings of one fact. They are three different next moves:
 *
 *   1. **Dynamic** — nothing is missing. Do nothing. A re-sync cannot produce a file that does not
 *      exist. Proven against the live payload for `72f9ae37-c58c-4988-a021-0f9457a721c4`, whose body
 *      carries `render_type: DYNAMIC`, `dynamic_render_properties.product_set_id` and a
 *      `top_snap_crop_position` — and no `top_snap_media_id` at all.
 *   2. **Zone read, nothing drawable** — we asked, the platform answered, what it sent carries no
 *      picture. Re-syncing returns the same elements.
 *   3. **Never asked** — the tiles were not fetched. This one IS a re-sync.
 *
 * Each is asserted on the note the reader is actually shown, not on the state alone: two of the
 * three are `available`, and a state without a sentence is the defect above.
 */
final class CollectionAbsenceSaysWhoseGapItIsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

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
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'external_id' => 'c-1',
            'name' => 'Campaign',
            'status' => 'active',
            'objective' => 'sales',
        ]);
    }

    /**
     * THE REGRESSION. A dynamic collection whose zone answered still states the platform's fact.
     *
     * The cards here are the shape the live zone actually returns for a dynamic collection: a
     * headline, a destination, and no asset link of any kind. Restore `&& cards === null` on the
     * dynamic arm and this fails with an empty note — which is what Production was serving.
     */
    public function test_a_dynamic_collection_whose_tiles_were_fetched_still_says_nothing_is_missing(): void
    {
        $preview = $this->preview($this->creative([
            'format' => 'collection_dynamic',
            'cards' => [
                ['headline' => 'Linen shirt', 'destination_url' => 'https://shop.test/1'],
                ['headline' => 'Abaya', 'destination_url' => 'https://shop.test/2'],
                ['headline' => 'Sandals', 'destination_url' => 'https://shop.test/3'],
            ],
        ]));

        $this->assertSame('available', $preview['state'], 'nothing is missing, so nothing is unavailable');
        $this->assertNotNull($preview['note_en'], 'the reader was given an empty frame and no sentence');
        $this->assertStringContainsString('catalogue', (string) $preview['note_en']);
        $this->assertStringContainsString('كتالوج', (string) $preview['note_ar']);
    }

    /** The same ad before the tiles were ever fetched reads identically — the fetch changed no fact. */
    public function test_the_same_dynamic_collection_reads_the_same_before_its_tiles_were_fetched(): void
    {
        $fetched = $this->preview($this->creative([
            'format' => 'collection_dynamic',
            'cards' => [['headline' => 'Linen shirt']],
        ]));
        $unfetched = $this->preview($this->creative([
            'format' => 'collection_dynamic',
            'cards' => null,
        ]));

        $this->assertSame($unfetched['state'], $fetched['state']);
        $this->assertSame($unfetched['note_en'], $fetched['note_en']);
        $this->assertSame($unfetched['note_ar'], $fetched['note_ar']);
    }

    /**
     * A dynamic collection that DOES resolve a picture keeps it.
     *
     * The arm is reached only when there is nothing to draw, so it can never suppress a real tile.
     * Without this, «nothing is missing» would become a way to hide media the product holds.
     */
    public function test_a_dynamic_collection_with_a_usable_tile_draws_it(): void
    {
        $preview = $this->preview($this->creative([
            'format' => 'collection_dynamic',
            'cards' => [
                ['headline' => 'No picture'],
                ['headline' => 'Linen shirt', 'image_url' => 'https://cdn.test/tile.jpg'],
            ],
        ]));

        $this->assertSame('available', $preview['state']);
        $this->assertSame('https://cdn.test/tile.jpg', $preview['image_url']);
    }

    /**
     * A STATIC collection whose zone answered with nothing drawable names the ZONE.
     *
     * A refinement rather than a repair, and the difference is worth stating precisely. This case
     * already reached the general cards-present arm and read «this ad's cards were fetched and none
     * of them carried a usable asset» — correct, and `unavailable` either way. What it did not say is
     * HOW a collection's tiles are fetched, which is the fact that tells an operator a re-sync will
     * return the same elements rather than a different answer.
     *
     * So the state is asserted as unchanged, and the sentence as more specific than the general one.
     */
    public function test_a_static_collection_whose_zone_answered_with_nothing_names_the_zone(): void
    {
        $preview = $this->preview($this->creative([
            'format' => 'collection',
            'cards' => [['headline' => 'Linen shirt'], ['headline' => 'Abaya']],
        ]));

        $this->assertSame('unavailable', $preview['state'], 'this reading was already unavailable and must stay so');
        $this->assertStringContainsString('interaction zone was read', (string) $preview['note_en']);
        // Not the generic card sentence: a collection is read through its zone, and saying so is the point.
        $this->assertStringNotContainsString('none of them carried a usable asset', (string) $preview['note_en']);
        // And never the re-sync sentence, which belongs only to tiles nobody asked for.
        $this->assertStringNotContainsString('did not arrive', (string) $preview['note_en']);
    }

    /** And the one that really is a re-sync keeps saying so. */
    public function test_a_collection_nobody_asked_about_still_reads_as_never_fetched(): void
    {
        $preview = $this->preview($this->creative(['format' => 'collection', 'cards' => null]));

        $this->assertSame('shape_not_fetched', $preview['state']);
    }

    /**
     * Every collection with no picture carries a sentence.
     *
     * The sweep, rather than three cases: falling through to `default` is precisely how the
     * regression above happened, and a fourth shape nobody thought of would do it again silently.
     */
    public function test_no_collection_ever_shows_an_empty_frame_without_a_reason(): void
    {
        $shapes = [
            'dynamic, zone answered' => ['collection_dynamic', [['headline' => 'x']]],
            'dynamic, never asked' => ['collection_dynamic', null],
            'dynamic, empty answer' => ['collection_dynamic', []],
            'static, zone answered' => ['collection', [['headline' => 'x']]],
            'static, never asked' => ['collection', null],
            'static, empty answer' => ['collection', []],
        ];

        foreach ($shapes as $label => [$format, $cards]) {
            $preview = $this->preview($this->creative(['format' => $format, 'cards' => $cards]));

            $this->assertNull($preview['image_url'], "{$label}: the fixture was supposed to have nothing to draw");
            $this->assertNotNull($preview['note_en'], "{$label}: an empty frame with no sentence");
            $this->assertNotNull($preview['note_ar'], "{$label}: an empty frame with no Arabic sentence");
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** @param array<string, mixed> $over */
    private function creative(array $over = []): ExternalCreative
    {
        return ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $this->campaign->id,
            'provider' => 'snapchat',
            'external_creative_id' => 'ec-'.uniqid(),
            'name' => 'Summer collection',
            'format' => 'collection',
            'asset_url' => null,
            'video_url' => null,
            'thumbnail_url' => null,
            ...$over,
        ]);
    }

    /** @return array<string, mixed> */
    private function preview(ExternalCreative $creative): array
    {
        return app(CreativePresenter::class)->preview($creative);
    }
}
