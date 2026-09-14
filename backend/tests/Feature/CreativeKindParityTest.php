<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Support\CreativeKind;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONTENT-FILTER-TRUTH-001 — the card and the filter must agree about what a creative IS.
 *
 * The owner's report: `Project 1 · Snapchat · Content type = Video · Objective = Sales` answered «لا
 * توجد إعلانات تطابق هذا التحديد» over an estate that plainly contains Snapchat videos.
 *
 * There were two rules. `CreativePresenter::kind()` decides what the card says and reads the format
 * string, then the ASSETS when the format is unhelpful. The filter read `format ilike '%video%'` and
 * nothing else. They disagree in BOTH directions, and this asserts the rows that prove it rather than
 * the rule that produced them:
 *
 *  - `SNAP_AD` + a film: the card says «فيديو», the filter used to drop it.
 *  - `collection_video`: the card says «مجموعة», the filter used to return it under «فيديو».
 *
 * The shapes below are the ones real connectors write — `SNAP_AD`, `STORY`, `single_video`,
 * `collection_video`, `DYNAMIC_PRODUCT_AD` — not the tidy `video`/`image`/`carousel` the seed uses.
 * A fixture that only carried the tidy ones would have passed against the defect, which is why the
 * local data could not reproduce what production shows.
 */
final class CreativeKindParityTest extends TestCase
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

    /**
     * The estate, one row per shape a provider actually writes.
     *
     * @return array<string, ExternalCreative>
     */
    private function estate(): array
    {
        $make = function (string $name, string $provider, ?string $format, array $assets): ExternalCreative {
            return ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => $provider,
                'external_creative_id' => 'ext-'.$name,
                'name' => $name,
                'format' => $format,
            ] + $assets + [
                'asset_url' => null, 'video_url' => null, 'thumbnail_url' => null, 'preview_url' => null,
            ]);
        };

        return [
            /* The owner's case: Snapchat's own format string, carrying a film and nothing else. */
            'snap_ad_film' => $make('snap_ad_film', 'snapchat', 'SNAP_AD', ['video_url' => 'https://cdn.test/a.mp4']),
            'story_film' => $make('story_film', 'snapchat', 'STORY', ['video_url' => 'https://cdn.test/b.mp4']),
            /* Labelled an image, and the only file that resolved is a film — the label loses. */
            'image_label_film' => $make('image_label_film', 'snapchat', 'IMAGE', ['video_url' => 'https://cdn.test/c.mp4']),
            /* Labelled an image WITH a still as well as a film: an image ad with a preview clip. */
            'image_with_clip' => $make('image_with_clip', 'meta', 'IMAGE', [
                'asset_url' => 'https://cdn.test/d.jpg', 'video_url' => 'https://cdn.test/d.mp4',
            ]),
            'single_video' => $make('single_video', 'tiktok', 'single_video', ['video_url' => 'https://cdn.test/e.mp4']),
            /* A collection whose hero is a film: a COLLECTION, not a video. */
            'collection_video' => $make('collection_video', 'meta', 'collection_video', ['video_url' => 'https://cdn.test/f.mp4']),
            'catalog' => $make('catalog', 'meta', 'DYNAMIC_PRODUCT_AD', []),
            'carousel' => $make('carousel', 'meta', 'carousel_ad', ['asset_url' => 'https://cdn.test/g.jpg']),
            'plain_image' => $make('plain_image', 'meta', 'IMAGE', ['asset_url' => 'https://cdn.test/h.jpg']),
            /*
             * CONTENT-PREVIEW-SHAPES-001 — an ad labelled a still that carries FOUR cards.
             *
             * Every arm of this rule read the platform's label, so this resolved to `image`, and the
             * surfaces branch on it: `AdPoster` for a still, `CreativeCarousel` for a card shape. The
             * reader was shown one picture of a four-picture ad, with nothing saying the other three
             * had already been fetched, stored and guarded. `CreativePresenter` half-knew — it
             * promotes a card to be the hero when there is no hero — which fixed the blank frame and
             * left the single frame.
             *
             * It is not a claim about how a platform labels anything. It is a rule about our own
             * rows: holding more than one card is not a single still.
             */
            'still_label_four_cards' => $make('still_label_four_cards', 'meta', 'IMAGE', [
                'asset_url' => 'https://cdn.test/i.jpg',
                'cards' => [
                    ['image_url' => 'https://cdn.test/c1.jpg'],
                    ['image_url' => 'https://cdn.test/c2.jpg'],
                    ['image_url' => 'https://cdn.test/c3.jpg'],
                    ['image_url' => 'https://cdn.test/c4.jpg'],
                ],
            ]),
            /* And ONE card is not a strip: a single-card ad stays the still it looks like. */
            'still_label_one_card' => $make('still_label_one_card', 'meta', 'IMAGE', [
                'asset_url' => 'https://cdn.test/j.jpg',
                'cards' => [['image_url' => 'https://cdn.test/c1.jpg']],
            ]),
            /* No format the rule recognises, and a still — an image by its assets alone. */
            'unknown_still' => $make('unknown_still', 'snapchat', 'PROMOTED_PLACES', ['thumbnail_url' => 'https://cdn.test/i.jpg']),
        ];
    }

    /**
     * Every row, both ways: what the card says, and whether the filter for that same word returns it.
     *
     * Asserted row by row rather than as two totals — two counts can agree while naming different
     * rows, which is exactly the failure this is for.
     */
    public function test_the_filter_returns_exactly_the_rows_the_card_calls_by_that_name(): void
    {
        $estate = $this->estate();

        foreach (CreativeKind::ALL as $kind) {
            $expected = collect($estate)
                ->filter(fn (ExternalCreative $c) => CreativeKind::of($c) === $kind)
                ->map(fn (ExternalCreative $c) => $c->name)
                ->sort()->values()->all();

            $returned = ExternalCreative::query()
                ->where(fn ($q) => CreativeKind::scope($q, [$kind]))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame(
                $expected,
                $returned,
                "«{$kind}» filters to a different set than the cards it labels «{$kind}»",
            );
        }
    }

    /** The owner's row, named on its own so a failure says which case broke. */
    /**
     * CONTENT-PREVIEW-SHAPES-001 — media this product already holds is never hidden behind one still.
     *
     * The parity case above proves the filter and the card AGREE; it cannot prove either is right,
     * because it derives what it expects from the same function it is checking. This states the claim
     * outright: an ad we hold four cards for is a card shape, whatever the platform called it.
     *
     * The surfaces branch on this answer — `AdPoster` for a still, `CreativeCarousel` for a card
     * shape — so «image» here is the difference between a reader seeing one of four assets and all
     * four.
     */
    public function test_an_ad_we_hold_several_cards_for_is_not_a_single_still(): void
    {
        $estate = $this->estate();

        $this->assertSame('carousel', CreativeKind::of($estate['still_label_four_cards']));

        /* And the filter returns it under that name, or the library disagrees with its own cards. */
        $returned = ExternalCreative::query()
            ->where(fn ($q) => CreativeKind::scope($q, ['carousel']))
            ->pluck('name')->all();

        $this->assertContains('still_label_four_cards', $returned);
        $this->assertNotContains('still_label_four_cards', ExternalCreative::query()
            ->where(fn ($q) => CreativeKind::scope($q, ['image']))
            ->pluck('name')->all());
    }

    /**
     * One card is not a strip.
     *
     * A rule that promoted every card-bearing row would relabel ordinary single-asset ads as
     * carousels and put a navigation control on something with nowhere to navigate.
     */
    public function test_one_card_leaves_a_still_a_still(): void
    {
        $estate = $this->estate();

        $this->assertSame('image', CreativeKind::of($estate['still_label_one_card']));
    }

    public function test_a_snapchat_film_the_card_calls_video_survives_the_video_filter(): void
    {
        $this->estate();

        $names = ExternalCreative::query()
            ->where('provider', 'snapchat')
            ->where(fn ($q) => CreativeKind::scope($q, ['video']))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['image_label_film', 'snap_ad_film', 'story_film'], $names);
    }

    /** And the over-match: a collection is not returned under «video», however its hero is shaped. */
    public function test_a_collection_whose_hero_is_a_film_is_not_a_video(): void
    {
        $estate = $this->estate();

        $this->assertSame('collection', CreativeKind::of($estate['collection_video']));

        $names = ExternalCreative::query()
            ->where(fn ($q) => CreativeKind::scope($q, ['video']))
            ->pluck('name')->all();

        $this->assertNotContains('collection_video', $names);
    }

    /** Two kinds at once is a union, not an intersection — «video or image» must return both. */
    public function test_selecting_two_kinds_returns_both(): void
    {
        $this->estate();

        $names = ExternalCreative::query()
            ->where(fn ($q) => CreativeKind::scope($q, ['video', 'carousel']))
            ->pluck('name')->sort()->values()->all();

        $this->assertContains('carousel', $names);
        $this->assertContains('snap_ad_film', $names);
        $this->assertNotContains('plain_image', $names);
    }
}
