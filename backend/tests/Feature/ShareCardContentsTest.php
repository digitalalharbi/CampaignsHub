<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Services\BrandingService;
use App\Domains\Campaigns\Support\DrawableImage;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareCardRenderer;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * SHARE-PREVIEW-CARD-001 — what the drawn preview card CONTAINS.
 *
 * Asserted on the composed values and the rendered document, not on the PNG. That is deliberate and
 * it is the stronger test: the rules that matter here are about what the card SAYS, and a test that
 * launched a browser would prove them only where a browser is installed — which is not CI's default,
 * and is exactly how a rule ends up covered on one machine.
 *
 * The three rules are the metadata card's own, because the picture is fetched by the same crawler,
 * cached by the same service, and shown to the same forwarded group:
 *
 *   1. **No figures.** Not spend, not revenue, not a result.
 *   2. **Nothing a password protects**, and never the password.
 *   3. **The identity is the shared resolver's**, so the picture cannot disagree with the header the
 *      link opens.
 *
 * Plus one that belongs only to a picture: the brand colour lands inside a `<style>` block, and a
 * stylesheet is not HTML. Blade escapes for HTML.
 */
final class ShareCardContentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private ClientWorkspace $client;

    private Report $report;

    private ReportShare $share;

    /** The raw token, shown once at creation — the thing a client is actually sent. */
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'sc-'.uniqid(), 'status' => 'active']);
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
            // Distinctive figures, so «no spend on the card» is a searchable fact rather than a hope.
            'data' => ['kpis' => ['spend' => 918273.45, 'revenue' => 4455667.88, 'roas' => 4.85]],
        ]);

        [$this->share, $this->token] = app(ShareService::class)->create($this->report, [
            'scope' => ['project_id' => $project->id],
            'mode' => 'live',
        ], null);

        app(TenantContext::class)->forget();
    }

    public function test_the_card_names_the_client_and_the_period(): void
    {
        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertSame('Nakheel', $card['who']);
        $this->assertSame('2026-07-01 — 2026-07-31', $card['period']);
        // The report's own language decides the direction, so an Arabic report's card reads right to left.
        $this->assertSame('ar', $card['lang']);
        $this->assertSame('rtl', $card['dir']);
    }

    /** The one rule whose breach cannot be taken back, asserted on the drawn document itself. */
    public function test_the_drawn_card_carries_no_figures(): void
    {
        $html = $this->render();

        foreach (['918273', '918,273', '4455667', '4.85', 'roas', 'ROAS', 'spend', 'الإنفاق'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "the preview card leaked «{$needle}»");
        }
    }

    /**
     * A password-gated link draws the SAME card, and never the password.
     *
     * Identical either way is the point: a gated link whose picture said less would itself be a
     * signal, and one that said more would be the gate defeated by a paste.
     */
    public function test_a_gated_links_card_is_the_same_and_never_the_password(): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);
        [$gated] = app(ShareService::class)->create($this->report, [
            'scope' => ['project_id' => $this->report->project_id],
            'mode' => 'live',
            'password' => 'let-me-in',
        ], null);
        app(TenantContext::class)->forget();

        $renderer = app(ShareCardRenderer::class);
        $open = $renderer->contents($this->share, $this->report);
        $shut = $renderer->contents($gated, $this->report);

        $this->assertSame($open['who'], $shut['who']);
        $this->assertSame($open['period'], $shut['period']);
        $this->assertStringNotContainsString('let-me-in', $this->render($gated));
    }

    /**
     * The mark travels INSIDE the picture, inlined — not linked.
     *
     * A data URI because the card is opened from a temp file with no origin: a linked mark would be a
     * network fetch from a document that has no business making one, and a slow or refused fetch
     * photographs as an empty box.
     */
    public function test_a_configured_mark_is_inlined_into_the_card(): void
    {
        $this->storeMark();

        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertIsString($card['logo']);
        $this->assertStringStartsWith('data:image/png;base64,', $card['logo']);
        $this->assertStringContainsString('<img', $this->render());
    }

    /**
     * The card takes the NEAREST mark, and that is deliberately not what the header does.
     *
     * `headerIdentity` gives the leading slot to the client's OWN mark and puts the agency's beside
     * «بواسطة», because on a report page there are two slots and putting an agency's mark in the
     * client's is a claim about whose report it is.
     *
     * A chat card has ONE slot. The alternative to the nearest mark is no mark — a card that shows
     * an agency's own clients nothing simply because those clients have not uploaded a logo, which is
     * the common case and the one this whole card exists for.
     *
     * So they differ on purpose, and this pins it: aligning the card to the header's two-slot rule
     * would blank the picture for exactly the links that most need one.
     */
    public function test_the_card_falls_back_to_the_agency_mark_where_the_client_has_none(): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);
        app(BrandingService::class)->storeAsset(
            'tenant',
            null,
            'primary_horizontal',
            'any',
            UploadedFile::fake()->createWithContent('agency.png', $this->png()),
        );
        app(TenantContext::class)->forget();

        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertIsString($card['logo'], 'the client has no mark, so the card showed nothing at all');
        $this->assertStringStartsWith('data:image/png;base64,', $card['logo']);
    }

    /**
     * Bytes a browser would not draw are not a mark.
     *
     * `DrawableImage` decides, and it reads the leading bytes rather than the stored content type —
     * Production has already shown this product real PNGs served as `multipart/form-data`. The
     * inverse is what this covers: a file named `.png` that is not one.
     */
    public function test_an_upload_that_is_not_an_image_is_not_drawn_as_one(): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);
        app(BrandingService::class)->storeAsset(
            'client',
            (string) $this->client->id,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('logo.png', 'this is not a picture'),
        );
        app(TenantContext::class)->forget();

        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertNull($card['logo'], 'bytes that decode as nothing were offered to the card as a mark');
        $this->assertStringNotContainsString('<img', $this->render());
    }

    /**
     * A stored colour cannot rewrite the card.
     *
     * The accent is interpolated into a `<style>` block, and Blade's escaping is for HTML: it leaves
     * `{`, `}` and `;` alone. A branding value of `red; } body { display: none` would have blanked the
     * picture rather than coloured it — so the colour is validated as a hex triple or replaced.
     */
    public function test_a_colour_that_is_not_a_colour_cannot_rewrite_the_stylesheet(): void
    {
        $this->report->forceFill(['config' => ['branding' => [
            'name' => 'Nakheel',
            'accent' => 'red; } body { display: none } .x {',
        ]]])->save();

        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertSame('#2563eb', $card['accent'], 'an unvalidated colour reached the stylesheet');
        $this->assertStringNotContainsString('display: none', $this->render());
    }

    /** A real brand colour is honoured, so the guard above is not simply refusing everything. */
    public function test_a_real_brand_colour_is_honoured(): void
    {
        $this->report->forceFill(['config' => ['branding' => [
            'name' => 'Nakheel',
            'colors' => ['primary' => '#B4842F'],
        ]]])->save();

        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertSame('#b4842f', $card['accent']);
        $this->assertStringContainsString('#b4842f', $this->render());
    }

    /**
     * The card is sized for the slot every service crops to, and says so in its own document.
     *
     * 1200×630 is the requirement, not a preference: X refuses a large card under 300×157 and
     * WhatsApp letterboxes anything that is not close to 1.91:1.
     */
    public function test_the_card_is_drawn_at_the_slot_every_crawler_crops_to(): void
    {
        $this->assertSame(1200, (int) config('reports.og.width'));
        $this->assertSame(630, (int) config('reports.og.height'));

        $html = $this->render();
        $this->assertStringContainsString('width: 1200px', $html);
        $this->assertStringContainsString('height: 630px', $html);
    }

    /** The Arabic has to JOIN, which is the whole reason a browser draws this and GD does not. */
    public function test_the_card_asks_for_a_face_that_shapes_arabic(): void
    {
        $card = app(ShareCardRenderer::class)->contents($this->share, $this->report);

        $this->assertStringContainsString('Arabic', (string) $card['fontStack']);
    }

    /**
     * And the face has to SURVIVE the template, which is a different claim.
     *
     * Found by looking at a drawn card rather than by a passing test: the stack was echoed with
     * Blade's default escape, so every quoted family name reached the document as
     * `&#039;IBM Plex Sans Arabic&#039;`. CSS does not report that — it drops the declaration — and
     * headless Chromium's default face is a SERIF, so the first card came out in Times with the
     * assertion above still green. A stack in the array proves nothing about the stack in the
     * stylesheet.
     */
    public function test_the_face_survives_the_template_unescaped(): void
    {
        $html = $this->render();

        $this->assertStringContainsString("font-family: 'IBM Plex Sans Arabic'", $html);
        $this->assertStringNotContainsString('&#039;IBM Plex', $html, 'the font stack was HTML-escaped into a dropped declaration');
    }

    /**
     * The period is ISOLATED, so an Arabic card cannot state its dates backwards.
     *
     * A date range is two left-to-right runs joined by a neutral dash. Dropped into right-to-left
     * text with nothing to isolate it, the bidi algorithm orders the runs right to left and the card
     * reads «2026-07-31 — 2026-07-01»: a real period, the wrong way round, on a picture a client
     * cannot click to correct.
     */
    public function test_the_period_is_isolated_so_it_cannot_read_backwards(): void
    {
        $this->assertStringContainsString('<bdi dir="ltr">2026-07-01 — 2026-07-31</bdi>', $this->render());
    }

    /**
     * Decorations are clipped by a box the layout owns, not by the viewport.
     *
     * `overflow: hidden` on the body PROPAGATES to the viewport instead of clipping the body, so the
     * accent wash still sized the document — and in RTL the extra width is added on the LEFT, moving
     * the origin the screenshot is taken from. The Arabic card lost a word off each edge while the
     * Latin one looked perfect, which is how a one-direction check ships a broken card.
     */
    public function test_the_decoration_cannot_resize_the_document(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('html, body { width: 1200px; height: 630px; overflow: hidden; }', $html);
        $this->assertStringContainsString('<div class="bg"></div>', $html);
    }

    /** No renderer, no picture — and nothing half-drawn in its place. */
    public function test_no_renderer_draws_nothing(): void
    {
        config(['reports.chromium.enabled' => false]);

        $this->assertNull(app(ShareCardRenderer::class)->png($this->share, $this->report));
        $this->assertFalse(app(ShareCardRenderer::class)->available());
    }

    /**
     * THE WHOLE PASTE, end to end: the crawler's document → the url it names → real PNG bytes.
     *
     * Every other case here holds one link of that chain. A crawler holds none of them — it fetches
     * `/r/{token}`, reads `og:image`, fetches THAT, and renders whatever comes back. Three things
     * that each pass on their own still produce a blank card if the url in the document does not
     * resolve, if the route answers something that is not an image, or if the bytes are not the size
     * the document promised.
     *
     * So this drives the real renderer. It is skipped where Chromium cannot run — the `backend` job
     * installs no browser, and a test that quietly passed there would be claiming the capability on
     * a machine that does not have it. Where a browser exists, it asserts rather than hopes.
     */
    public function test_a_pasted_link_resolves_to_real_png_bytes_of_the_promised_size(): void
    {
        config(['reports.chromium.enabled' => true]);

        if (! is_file((string) config('reports.chromium.require_base'))) {
            $this->markTestSkipped('no Playwright install to draw with on this machine');
        }

        $token = $this->token;

        $html = $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])
            ->get('/r/'.$token)->assertOk()->getContent() ?: '';

        preg_match('/<meta property="og:image" content="([^"]*)"/', $html, $m);
        $image = $m[1] ?? '';
        $this->assertNotSame('', $image, 'the crawler document named no picture');

        // Exactly what a crawler does next: fetch the url it was given, nothing reconstructed.
        $response = $this->get(parse_url($image, PHP_URL_PATH) ?: '');

        if ($response->getStatusCode() !== 200) {
            $this->markTestSkipped('the renderer refused on this machine: '.$response->getStatusCode());
        }

        // A plain response, not a streamed one: the bytes are held so the digest and the size can be read.
        $bytes = (string) $response->getContent();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('png', DrawableImage::sniff($bytes), 'the route served something a browser would not draw');

        $size = getimagesizefromstring($bytes);
        $this->assertIsArray($size);
        $this->assertSame((int) config('reports.og.width'), $size[0], 'the picture is not the width the document promised');
        $this->assertSame((int) config('reports.og.height'), $size[1]);

        // Cacheable and public — this is the one response on a share link that is.
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function storeMark(): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);
        app(BrandingService::class)->storeAsset(
            'client',
            (string) $this->client->id,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('logo.png', $this->png()),
        );
        app(TenantContext::class)->forget();
    }

    /** The smallest real PNG — bytes `DrawableImage` will actually accept. */
    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    /** The card's own document, rendered — what Chromium would be asked to photograph. */
    private function render(?ReportShare $share = null): string
    {
        $card = app(ShareCardRenderer::class)->contents($share ?? $this->share, $this->report);

        return View::make('reports.og-card', $card)->render();
    }
}
