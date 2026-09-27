<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareCardRenderer;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * REPORT-TITLE-METADATA-001 — the card a client link renders as when it is PASTED.
 *
 * `SharePreviewController` and its view shipped with three rules written in their docblocks and none
 * of them under test. Docblocks do not fail. This is the evidence bar the row actually asks for —
 * server-rendered metadata assertions over a crawler-shaped fetch — and each rule below is a way the
 * card can leak or mislead rather than merely look wrong:
 *
 *   1. **No figures.** A preview is rendered by a third party, cached by them, and shown to everyone
 *      who can see the message — including a group the client forwarded it into. A password-gated
 *      link must not preview what the password protects.
 *   2. **An invalid link previews as nothing.** A 404, not a card describing a report that may have
 *      been revoked: «this was Nakheel's July report» said to somebody holding a dead token is a
 *      disclosure, however small.
 *   3. **The identity comes from the same resolver as the header the link opens**, so the card and
 *      the page cannot disagree about whose report it is.
 *
 * Production behaviour was checked directly before this was written, and the routing this depends on
 * is live: `https://campaignshub.io/r/<token>` answers a browser user-agent with the SPA (200, the
 * product's Arabic shell title) and a `facebookexternalhit` user-agent from the backend
 * (`host: backend:8000`, Laravel's own 404 for an unknown token). So the crawler half is reached in
 * production; what had never been proved is what it SAYS when the token is real.
 */
final class SharePreviewMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private ClientWorkspace $client;

    private Report $report;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'sp-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->agency->id);

        $this->client = ClientWorkspace::create(['name' => 'Nakheel', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);

        $this->report = Report::create([
            'project_id' => $project->id,
            'name' => 'تقرير الأداء الشهري',
            'type' => 'executive',
            'status' => 'completed',
            'currency' => 'SAR',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            /*
             * Figures the card must not carry. A distinctive number, so «no spend in the preview» is
             * a searchable fact about this response rather than a hopeful assertion.
             */
            'data' => ['kpis' => ['spend' => 918273.45, 'revenue' => 4455667.88, 'roas' => 4.85]],
        ]);

        [, $this->token] = app(ShareService::class)->create($this->report, [
            'scope' => ['project_id' => $project->id],
            'mode' => 'live',
        ], null);

        app(TenantContext::class)->forget();
    }

    public function test_the_card_names_the_report_and_the_client(): void
    {
        $html = $this->preview();

        // REPORT BRANDING (Owner): the title is the report's name ending with the product's, in the
        // report's language; whose report it is travels in the description below.
        $this->assertStringContainsString('<title>تقرير الأداء الشهري — كامبينز هب</title>', $html);
        $this->assertSame('تقرير الأداء الشهري — كامبينز هب', $this->meta($html, 'og:title'));
        $this->assertSame('كامبينز هب', $this->meta($html, 'og:site_name'));
        $this->assertSame('article', $this->meta($html, 'og:type'));
    }

    /** The period, because «which report is this?» is the only question a chat card has to answer. */
    public function test_the_card_states_the_period_and_nothing_more(): void
    {
        $description = (string) $this->meta($this->preview(), 'og:description');

        $this->assertSame('Nakheel · 2026-07-01 — 2026-07-31', $description);
    }

    /**
     * NO FIGURES. The one rule whose breach cannot be taken back: a preview is cached and re-shown by
     * a third party to everybody in the conversation.
     */
    public function test_the_card_carries_no_figures(): void
    {
        $html = $this->preview();

        foreach (['918273', '918,273', '4455667', '4.85', 'roas', 'ROAS', 'spend'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "the preview card leaked «{$needle}»");
        }
    }

    /**
     * A link protected by a password must not preview what the password protects.
     *
     * The card is identical either way — which is the point. A gated link whose card said less would
     * itself be a signal, and one that said more would be the gate defeated by a paste.
     */
    public function test_a_password_gated_link_previews_the_same_and_no_more(): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);
        [, $gated] = app(ShareService::class)->create($this->report, [
            'scope' => ['project_id' => $this->report->project_id],
            'mode' => 'live',
            'password' => 'let-me-in',
        ], null);
        app(TenantContext::class)->forget();

        $html = $this->preview($gated);

        $this->assertSame('تقرير الأداء الشهري — كامبينز هب', $this->meta($html, 'og:title'));
        $this->assertStringNotContainsString('918273', $html);
        $this->assertStringNotContainsString('let-me-in', $html);
    }

    /**
     * An unknown token previews as NOTHING.
     *
     * Not an empty card and not the product's marketing line: a 404. A card rendered for a dead token
     * tells whoever is holding it that the report existed and whose it was.
     */
    public function test_an_unknown_token_is_refused_rather_than_described(): void
    {
        $response = $this->get('/r/'.str_repeat('z', 22));

        $response->assertNotFound();
        $this->assertStringNotContainsString('Nakheel', $response->getContent() ?: '');
    }

    /**
     * SHARE-PREVIEW-CARD-001 — the large image is the DRAWN CARD, and never the mark.
     *
     * This assertion changed, and the behaviour it used to describe was the defect. `og:image` was
     * `$identity['logo_url']` — whatever file the agency uploaded to the Branding Center — beside a
     * `summary_large_image` declaration. A mark is square or tall, frequently transparent, often a
     * few hundred pixels; the slot is 1200×630 on a dark chat bubble. WhatsApp letterboxed it, X
     * refuses anything under 300×157, and a transparent PNG arrived as an empty rectangle.
     *
     * So the picture is now composed for the slot, and the mark's job is to appear INSIDE it —
     * asserted on the card's own contents in `ShareCardContentsTest`, where it can be checked
     * without a browser.
     */
    public function test_the_large_image_is_the_drawn_card_and_not_the_uploaded_mark(): void
    {
        $this->rendererOrSkip();

        app(TenantContext::class)->setTenantId($this->agency->id);
        app(BrandingService::class)->storeAsset(
            'client',
            (string) $this->client->id,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('logo.png', 'nakheel-bytes'),
        );
        app(TenantContext::class)->forget();

        $image = (string) $this->meta($this->preview(), 'og:image');

        $this->assertStringContainsString('/r/'.$this->token.'/preview.png', $image);
        $this->assertStringNotContainsString('branding/logo', $image, 'the mark is being sent as the preview image again');
        $this->assertSame('summary_large_image', $this->metaName($this->preview(), 'twitter:card'));
    }

    /**
     * The card declares its SIZE, so a crawler can lay it out without fetching it first.
     *
     * Several of them give up rather than wait for an image whose dimensions they have to measure,
     * and a card with an empty picture slot is the same outcome as having no image at all. The
     * numbers come from the config the renderer draws at, so what is declared cannot drift from what
     * is served — asserted here against the config rather than against two literals.
     */
    public function test_the_card_declares_the_size_it_was_drawn_at(): void
    {
        $this->rendererOrSkip();

        $html = $this->preview();

        $this->assertSame((string) config('reports.og.width'), $this->meta($html, 'og:image:width'));
        $this->assertSame((string) config('reports.og.height'), $this->meta($html, 'og:image:height'));
        $this->assertSame('image/png', $this->meta($html, 'og:image:type'));
    }

    /** And it carries an alt, for the reader who is told about the card rather than shown it. */
    public function test_the_picture_has_an_accessible_description(): void
    {
        $this->rendererOrSkip();

        $html = $this->preview();

        $this->assertSame('Nakheel · 2026-07-01 — 2026-07-31', $this->meta($html, 'og:image:alt'));
        $this->assertSame('Nakheel · 2026-07-01 — 2026-07-31', $this->metaName($html, 'twitter:image:alt'));
    }

    /**
     * The card is offered for a link with NO mark too, because it draws an identity rather than a logo.
     *
     * Under the old behaviour this link had no `og:image` at all: no upload, no picture. The agency
     * that has not got round to uploading a mark is the common case, and it is the one whose links
     * were previewing as bare text.
     */
    public function test_a_link_with_no_mark_still_gets_a_drawn_card(): void
    {
        $this->rendererOrSkip();

        $html = $this->preview();

        $this->assertStringContainsString('/r/'.$this->token.'/preview.png', (string) $this->meta($html, 'og:image'));
        $this->assertSame('summary_large_image', $this->metaName($html, 'twitter:card'));
    }

    /**
     * With no renderer, the card degrades to a text card rather than to a broken one.
     *
     * `summary_large_image` with no `og:image` is how a preview renders as an empty grey rectangle in
     * WhatsApp — the chat-card spelling of the broken image BRANDING-HIERARCHY-001 forbids. And a
     * large card pointing at an image route the server has decided not to answer is the same defect
     * with an extra request in front of it.
     */
    public function test_without_a_renderer_the_card_is_a_text_card_not_a_broken_one(): void
    {
        config(['reports.chromium.enabled' => false]);

        $html = $this->preview();

        $this->assertNull($this->meta($html, 'og:image'), 'an image tag was emitted with no renderer to draw it');
        $this->assertSame('summary', $this->metaName($html, 'twitter:card'));

        // And nothing ABOUT a picture that is not there. A size or an alt with no url is how a
        // crawler is told to expect an image and then given none.
        foreach (['og:image:width', 'og:image:height', 'og:image:type', 'og:image:alt'] as $property) {
            $this->assertNull($this->meta($html, $property), "«{$property}» was emitted with no image");
        }
        $this->assertNull($this->metaName($html, 'twitter:image:alt'));
    }

    /**
     * WITH NO RENDERER BUT A MARK, the mark is offered — because a 404 is worse than a small picture.
     *
     * `SharedReportCrawlerMetadataTest` is the reason this case exists. It fetches `og:image` exactly
     * as a crawler does, with no session, and requires an image back: a crawler caches whatever it
     * gets, so a dead url follows the link into every chat it is forwarded to, and nothing inside
     * this product would ever show it.
     *
     * A mark is not a preview image — that is why the card exists — so it goes out under `summary`,
     * never the large layout that would crop it, and without the size tags that describe a card it
     * is not.
     */
    public function test_with_no_renderer_a_configured_mark_is_offered_rather_than_a_dead_url(): void
    {
        config(['reports.chromium.enabled' => false]);

        app(TenantContext::class)->setTenantId($this->agency->id);
        app(BrandingService::class)->storeAsset(
            'client',
            (string) $this->client->id,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('logo.png', 'nakheel-bytes'),
        );
        app(TenantContext::class)->forget();

        $html = $this->preview();

        $image = (string) $this->meta($html, 'og:image');
        $this->assertStringContainsString('branding/logo', $image, 'no picture at all was offered');
        $this->assertSame('summary', $this->metaName($html, 'twitter:card'));

        // Nothing describing a 1200x630 card, because this is not one.
        $this->assertNull($this->meta($html, 'og:image:width'));
        $this->assertNull($this->meta($html, 'og:image:type'));
    }

    /**
     * And the image route itself refuses when it cannot draw — a 404, never a placeholder.
     *
     * A crawler that asked for the picture and got a redirect to a logo, or a 200 carrying an empty
     * PNG, would cache that as the link's face. A refusal leaves it with the summary card it can
     * always render.
     */
    public function test_the_image_route_refuses_rather_than_inventing_a_placeholder(): void
    {
        config(['reports.chromium.enabled' => false]);

        $this->get('/r/'.$this->token.'/preview.png')->assertNotFound();
    }

    /** An unknown token has no picture either, for the same reason it has no card. */
    public function test_an_unknown_token_has_no_picture(): void
    {
        config(['reports.chromium.enabled' => true]);

        $response = $this->get('/r/'.str_repeat('z', 22).'/preview.png');

        $response->assertNotFound();
        $this->assertStringNotContainsString('Nakheel', $response->getContent() ?: '');
    }

    /** The canonical URL is the link that was shared, so a crawler files the card under it. */
    public function test_the_card_points_back_at_the_link_that_was_shared(): void
    {
        $html = $this->preview();

        $this->assertStringContainsString('/r/'.$this->token, (string) $this->meta($html, 'og:url'));
        $this->assertStringContainsString('rel="canonical"', $html);
    }

    /**
     * A DRAWN card needs a browser, and the `backend` job installs none.
     *
     * These cases are about the card, not about the fallback, and a test that quietly passed on a
     * machine that cannot draw would be claiming the capability. Where there is no renderer the
     * behaviour is covered by its own case below — the agency's mark is offered instead, and the
     * layout stays `summary`.
     */
    private function rendererOrSkip(): void
    {
        config(['reports.chromium.enabled' => true]);

        /*
         * ASK THE RENDERER, not the filesystem.
         *
         * A first cut looked for `require_base` — the frontend's package.json — and that file is in
         * every checkout, including the `backend` CI job, which installs no browser. So it answered
         * «yes» where nothing could draw, and the card cases failed there instead of skipping.
         *
         * Drawing one card is the only honest question, and it is cheap after the first: the answer
         * is cached, so the case that follows serves it from disk rather than launching Chromium
         * again.
         */
        $share = app(ShareService::class)->resolveActive($this->token);

        if ($share === null || app(ShareCardRenderer::class)->png($share, $this->report) === null) {
            $this->markTestSkipped('this machine cannot draw a card — no browser, or the renderer refused');
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function preview(?string $token = null): string
    {
        return $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])
            ->get('/r/'.($token ?? $this->token))
            ->assertOk()
            ->getContent() ?: '';
    }

    /** The content of an `og:`-style `property` meta tag, or null when it was not emitted. */
    private function meta(string $html, string $property): ?string
    {
        preg_match('/<meta property="'.preg_quote($property, '/').'" content="([^"]*)"/', $html, $m);

        return $m[1] ?? null;
    }

    /** The content of a `name`-style meta tag (`twitter:*`), or null. */
    private function metaName(string $html, string $name): ?string
    {
        preg_match('/<meta name="'.preg_quote($name, '/').'" content="([^"]*)"/', $html, $m);

        return $m[1] ?? null;
    }
}
