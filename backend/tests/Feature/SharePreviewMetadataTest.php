<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
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

        $this->assertStringContainsString('<title>تقرير الأداء الشهري — Nakheel</title>', $html);
        $this->assertSame('تقرير الأداء الشهري — Nakheel', $this->meta($html, 'og:title'));
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

        $this->assertSame('تقرير الأداء الشهري — Nakheel', $this->meta($html, 'og:title'));
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

    /** The identity is the shared resolver's, so the card cannot disagree with the page it opens. */
    public function test_the_card_uses_the_clients_own_mark_when_one_is_set(): void
    {
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
        $this->assertNotSame('', $image, 'the card offered no image at all');
        $this->assertSame('summary_large_image', $this->metaName($html, 'twitter:card'));
    }

    /**
     * With no mark anywhere, the card degrades to a text card rather than to a broken one.
     *
     * `summary_large_image` with no `og:image` is how a preview renders as an empty grey rectangle in
     * WhatsApp — the chat-card spelling of the broken image BRANDING-HIERARCHY-001 forbids.
     */
    public function test_without_a_mark_the_card_is_a_text_card_not_a_broken_one(): void
    {
        $html = $this->preview();

        $this->assertNull($this->meta($html, 'og:image'), 'an image tag was emitted with no asset');
        $this->assertSame('summary', $this->metaName($html, 'twitter:card'));
    }

    /** The canonical URL is the link that was shared, so a crawler files the card under it. */
    public function test_the_card_points_back_at_the_link_that_was_shared(): void
    {
        $html = $this->preview();

        $this->assertStringContainsString('/r/'.$this->token, (string) $this->meta($html, 'og:url'));
        $this->assertStringContainsString('rel="canonical"', $html);
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
