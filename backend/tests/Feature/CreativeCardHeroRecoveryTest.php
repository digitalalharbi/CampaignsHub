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
 * CONTENT-MEDIA-RECOVERY-002 — a multi-asset ad shows the media it HAS.
 *
 * ## The gap
 *
 * `preview()` reads three columns on the creative — `asset_url`, `video_url`, `thumbnail_url` — and
 * a carousel or a collection frequently has none of them: the media lives on the CARDS, in the JSON
 * column the presenter already parses a few lines further down to build the card strip.
 *
 * Every absence arm in the match is guarded by `cards === null`, so a creative that HAS cards and no
 * hero of its own matched none of them and fell to the default: state `available`, with
 * `image_url`, `video_url` and `thumbnail_url` all null. The grid then drew an empty frame on an ad
 * we hold four real pictures for, and said nothing was wrong, because `available` is what the
 * payload claimed.
 *
 * «Use the strongest real available preview. Unavailable only after all canonical recovery paths
 * fail.» The first card IS a canonical path: it is the platform's own asset for this ad, already
 * fetched, already through the same credential guard as everything else.
 *
 * ## What recovery must not do
 *
 * It must not fire for a withheld or an expired ad. The cards came from the same response as the
 * hero and carry the same credential and the same expiry — `cards()` says so in its own comment —
 * so promoting one would be reaching around a refusal we made on purpose.
 */
final class CreativeCardHeroRecoveryTest extends TestCase
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

    /** A collection whose hero was never sent shows its first tile rather than an empty frame. */
    public function test_a_collection_shows_its_first_tile_when_it_has_no_hero(): void
    {
        $preview = $this->preview($this->creative([
            'cards' => [
                ['headline' => 'Linen shirt', 'image_url' => 'https://cdn.test/tile-1.jpg'],
                ['headline' => 'Abaya', 'image_url' => 'https://cdn.test/tile-2.jpg'],
            ],
        ]));

        $this->assertSame('available', $preview['state']);
        $this->assertSame(
            'https://cdn.test/tile-1.jpg',
            $preview['image_url'],
            'the ad carries four real pictures and the payload offered none of them',
        );
    }

    /** A carousel of films recovers a film, and the poster that goes with it. */
    public function test_a_carousel_recovers_a_video_and_its_poster(): void
    {
        $preview = $this->preview($this->creative([
            'format' => 'carousel',
            'cards' => [
                ['video_url' => 'https://cdn.test/card.mp4', 'thumbnail_url' => 'https://cdn.test/card.jpg'],
            ],
        ]));

        $this->assertSame('available', $preview['state']);
        $this->assertSame('https://cdn.test/card.mp4', $preview['video_url']);
        $this->assertSame('https://cdn.test/card.jpg', $preview['thumbnail_url']);
    }

    /** The ad's OWN hero always wins — recovery is a fallback, not a preference. */
    public function test_a_hero_of_its_own_is_not_replaced_by_a_tile(): void
    {
        $preview = $this->preview($this->creative([
            'asset_url' => 'https://cdn.test/hero.jpg',
            'cards' => [['image_url' => 'https://cdn.test/tile-1.jpg']],
        ]));

        $this->assertSame('https://cdn.test/hero.jpg', $preview['image_url']);
    }

    /**
     * Cards fetched and none usable is its own sentence — not «available» with nothing in it.
     *
     * A card whose only link carried a credential is counted as withheld by `cards()`. Before this,
     * such an ad reached the reader as `available`, because every absence arm required `cards` to be
     * null and this one's is not.
     */
    public function test_cards_that_are_all_unusable_say_so(): void
    {
        $preview = $this->preview($this->creative([
            'cards' => [['image_url' => 'https://cdn.test/a.jpg?access_token=SECRET']],
        ]));

        $this->assertNotSame('available', $preview['state'], 'an ad with nothing to draw called itself available');
        $this->assertNull($preview['image_url']);
        $this->assertNotNull($preview['note_en']);
    }

    /**
     * And recovery never reaches around a refusal.
     *
     * The cards arrived in the same response as the hero, so they carry the same credential and the
     * same expiry. Promoting one out of a withheld ad would undo the withholding on purpose.
     */
    public function test_a_withheld_ad_does_not_promote_a_card(): void
    {
        $preview = $this->preview($this->creative([
            'preview_url' => 'https://fb.me/x?access_token=SECRET',
            'asset_url' => 'https://cdn.test/hero.jpg?access_token=SECRET',
            'cards' => [['image_url' => 'https://cdn.test/tile-1.jpg']],
        ]));

        $this->assertNull($preview['image_url']);
        $this->assertNull($preview['video_url']);
    }

    /** An expired ad keeps its expiry sentence rather than borrowing a tile that expired with it. */
    public function test_an_expired_ad_does_not_promote_a_card(): void
    {
        $preview = $this->preview($this->creative([
            'asset_url' => 'https://cdn.test/hero.jpg',
            'asset_expires_at' => now()->subDay(),
            'cards' => [['image_url' => 'https://cdn.test/tile-1.jpg']],
        ]));

        $this->assertSame('expired', $preview['state']);
        $this->assertNull($preview['image_url']);
    }
}
