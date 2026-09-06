<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
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
