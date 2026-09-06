<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AD-PREVIEW-001 — the blank cards, found on the live estate and named.
 *
 * ## What production was actually doing
 *
 * `integrations:probe --media` against the bound Meta account, asking for the still each first-page
 * card would draw:
 *
 *     · {{product.name}} 2026-08-0  available  image  200  text/html  260,390 bytes  did not decode
 *       UNUSABLE  fb.me/…/2x0aR2X4NTvx2V7
 *
 * Six of twelve. Every one a dynamic product ad, every one fetching a `fb.me` share link, getting a
 * quarter of a megabyte of HTML, and decoding nothing. Six blank rectangles on the page the owner
 * opens, and no error anywhere: the request succeeded.
 *
 * ## Why the fallback could never have worked
 *
 * `CreativePresenter` read `asset_url ?? preview_url` as the image. `MetaConnector` is the only
 * writer of that column and it writes `preview_shareable_link` — the field's own name says it is a
 * link to a page. No provider in this build has ever put an image there, so the fallback's only
 * possible outcome was a web page inside an `<img>`.
 *
 * A dynamic product ad is where it always fired, because no `asset_url` resolves for one: the
 * platform composes the creative per product at delivery. So the ads most likely to hit the fallback
 * were the ads guaranteed to have nothing behind it.
 *
 * Two docblocks in this repository already said this — `CampaignCreativesController` calls the old
 * `has_preview` rule «too generous» for exactly this reason, and `adPreview.ts` says the card «asked
 * for a picture that would never arrive». The withholding rule hid it: a share link carrying a
 * credential IS suppressed, and `fb.me` carries none.
 */
final class AShareLinkIsNotAnAssetTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
    }

    /**
     * Production's exact row: a dynamic product ad whose only link is Meta's share link.
     *
     * The claim is narrow and total — that URL must not reach the browser as an image. Asserting the
     * state alone would pass on a build that still leaked it in `image_url`.
     */
    public function test_a_share_link_never_reaches_the_browser_as_an_image(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->creative([
            'name' => '{{product.name}} 2026-08-01',
            'format' => 'image',
            'preview_url' => 'https://fb.me/2x0aR2X4NTvx2V7',
        ]));

        $this->assertNull($preview['image_url'], 'A page was handed to the card as a picture.');
        $this->assertNotSame('available', $preview['state']);
    }

    /**
     * ...and the reader is told something true instead of being shown nothing.
     *
     * «Available» with no drawable URL is the state that renders as a hole. The whole point of this
     * fix is that the card gains a sentence, not that it loses a URL.
     */
    public function test_the_reader_is_given_the_reason_rather_than_an_empty_frame(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->creative([
            'format' => 'image',
            'preview_url' => 'https://fb.me/2x0aR2X4NTvx2V7',
        ]));

        $this->assertSame('unavailable', $preview['state']);
        $this->assertNotNull($preview['note_en']);
        $this->assertNotNull($preview['note_ar']);
    }

    /**
     * A row whose only link is a CREDENTIALLED share link is «no asset», not «withheld».
     *
     * `withheld` tells the reader we are holding a picture back. We are not — there was never an
     * asset on that row, and «no asset was exposed» is the fact that lets them stop waiting for one.
     */
    public function test_a_credentialled_share_link_reads_as_no_asset_rather_than_as_withheld(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->creative([
            'format' => 'image',
            'preview_url' => 'https://business.facebook.com/preview?access_token=secret',
        ]));

        $this->assertSame('unavailable', $preview['state']);
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — a catalog ad is not «unavailable», because nothing is missing.
     *
     * The platform composes one image per product at delivery. «The platform exposed no asset for
     * it» reads as a fault and sends an operator looking for a sync problem that does not exist.
     *
     * `absenceLabel` has had the right sentence since the shape was added and could never reach it:
     * the frontend only asks what KIND an ad is once the state is `available`, and an asset-less
     * catalog ad fell into the `unavailable` arm first. So the sentence existed, was tested, and was
     * unreachable — the same shape of defect as `asset_expires_at`, which no connector ever emitted.
     */
    public function test_a_catalog_ad_is_available_because_nothing_is_missing(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->creative([
            'name' => '{{product.name}} 2026-08-01',
            'format' => 'catalog',
            'preview_url' => 'https://fb.me/2x0aR2X4NTvx2V7',
        ]));

        $this->assertSame('catalog', $preview['kind']);
        $this->assertSame('available', $preview['state'], 'A catalog ad has nothing missing.');
        $this->assertNull($preview['image_url'], 'And still no page in an <img>.');
    }

    /** A real asset is untouched — this removes a fallback, not the picture. */
    public function test_a_real_asset_still_reaches_the_card(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->creative([
            'format' => 'image',
            'asset_url' => 'https://scontent-cdg4-2.xx.fbcdn.net/v/765872842_n.jpg',
            'preview_url' => 'https://fb.me/2x0aR2X4NTvx2V7',
        ]));

        $this->assertSame('available', $preview['state']);
        $this->assertSame('https://scontent-cdg4-2.xx.fbcdn.net/v/765872842_n.jpg', $preview['image_url']);
    }

    /** And a thumbnail is still a still — the video path keeps its poster. */
    public function test_a_thumbnail_is_still_offered(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->creative([
            'format' => 'video',
            'video_url' => 'https://cdn.example/a.mp4',
            'thumbnail_url' => 'https://cdn.example/cover.png',
            'preview_url' => 'https://fb.me/2x0aR2X4NTvx2V7',
        ]));

        $this->assertSame('available', $preview['state']);
        $this->assertSame('https://cdn.example/cover.png', $preview['thumbnail_url']);
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — Meta calls a dynamic product ad a `SHARE`, like any link post.
     *
     * Which is why six of the live account's twelve first-page creatives mapped to `image`. They
     * have no image and never will, so the card went looking for a still it could not have and the
     * fallback handed it the share link.
     *
     * `object_story_spec.template_data` is the platform describing its own object: a DPA carries it
     * where an ordinary link post carries `link_data`, and the template IS the creative — which is
     * the whole reason there is no fixed asset. Already fetched, so this costs no extra field.
     *
     * Read from the spec and not from the name. The stored names read `{{product.name}} 2026-08-01`,
     * Meta's own template token sitting unrendered in the database, and that is strong evidence — but
     * it is still a string an advertiser could type.
     */
    public function test_a_dynamic_product_ad_is_recognised_by_its_template(): void
    {
        $mapped = $this->mapCreative([
            'id' => 'cr-1',
            'name' => '{{product.name}} 2026-08-01',
            /* Exactly what Meta answers for a dynamic product ad. */
            'object_type' => 'SHARE',
            'object_story_spec' => ['template_data' => ['link' => 'https://shop.example']],
        ]);

        $this->assertSame('catalog', $mapped['format']);
    }

    /** An ordinary link post is still an image — the marker is the template, not the object type. */
    public function test_an_ordinary_share_is_still_an_image(): void
    {
        $mapped = $this->mapCreative([
            'id' => 'cr-2',
            'name' => 'A link post',
            'object_type' => 'SHARE',
            'object_story_spec' => ['link_data' => ['picture' => 'https://scontent.example/a.jpg']],
        ]);

        $this->assertSame('image', $mapped['format']);
    }

    /**
     * Meta's own mapping, driven through the private writer.
     *
     * @param  array<string, mixed>  $creative
     * @return array<string, mixed>
     */
    private function mapCreative(array $creative): array
    {
        $method = new \ReflectionMethod(MetaConnector::class, 'creativeFrom');
        $method->setAccessible(true);

        /** @var array<string, mixed> $mapped */
        $mapped = $method->invokeArgs(new MetaConnector, [$creative, ['preview_shareable_link' => 'https://fb.me/2x0aR2X4NTvx2V7']]);

        return $mapped;
    }

    /** @param array<string, mixed> $over */
    private function creative(array $over): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.uniqid(),
            'name' => 'An ad',
            'source_type' => 'api',
            ...$over,
        ]);
    }
}
