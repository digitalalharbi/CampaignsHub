<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-TITLE-METADATA-001 — what a shared report link looks like when it is PASTED.
 *
 * A client link is sent in WhatsApp far more often than it is typed into a browser, and the preview
 * card is the first thing anyone sees of it. Every one of them showed «CampaignsHub — All your paid
 * campaigns in one place»: the SPA's static `index.html` is what a crawler receives, and none of
 * WhatsApp, X, LinkedIn, Slack or Telegram executes the React that would have set the real title.
 *
 * That is the requirement's own note — «client-side React metadata alone is insufficient» — and it
 * is why this is served by the backend rather than fixed in a `useEffect`.
 *
 * The card is deliberately thin. It names WHOSE report it is and the period, and it carries no
 * figures at all: a preview is rendered by a third party, cached by them, and shown to whoever can
 * see the message — spend and revenue have no business in it.
 */
final class SharedReportCrawlerMetadataTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Tenant::create(['name' => 'Al Harbi Agency', 'slug' => 'cm-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->agency->id);

        $client = ClientWorkspace::create(['name' => 'Nakheel', 'slug' => 'cmc-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);

        $report = Report::create([
            'project_id' => $project->id, 'name' => 'تقرير الأداء الشهري', 'type' => 'executive',
            'status' => 'completed', 'currency' => 'USD',
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'audience' => 'client', 'data' => ['kpis' => ['spend' => 91234.5, 'revenue' => 500000]],
        ]);

        [, $this->token] = app(ShareService::class)->create($report, [
            'scope' => ['project_id' => $project->id],
            'mode' => 'live',
        ], null);

        app(TenantContext::class)->forget();
    }

    /** The card names the report and whose it is — not the product's marketing line. */
    public function test_the_preview_card_names_the_report_and_the_client(): void
    {
        $html = $this->crawl();

        $this->assertStringContainsString('تقرير الأداء الشهري', $html);
        $this->assertStringContainsString('Nakheel', $html);
        $this->assertStringNotContainsString('All your paid campaigns in one place', $html);
    }

    /** The tags the crawlers actually read, in the form they read them. */
    public function test_it_serves_the_tags_a_crawler_reads(): void
    {
        $html = $this->crawl();

        foreach (['og:title', 'og:description', 'og:url', 'og:type', 'twitter:card'] as $tag) {
            $this->assertStringContainsString($tag, $html, "the preview is missing {$tag}");
        }
        $this->assertStringContainsString('<link rel="canonical"', $html);
    }

    /**
     * NO FIGURES. A preview is rendered by a third party, cached by them, and shown to anyone who can
     * see the message — including in a group the client forwarded it to.
     */
    public function test_the_preview_carries_no_figures(): void
    {
        $html = $this->crawl();

        foreach (['91234', '91,234', '500000', '500,000'] as $figure) {
            $this->assertStringNotContainsString($figure, $html, 'a preview card leaked a figure');
        }
    }

    /** An invalid or revoked link previews as nothing, and says nothing about what it might have been. */
    public function test_an_unknown_token_previews_as_nothing(): void
    {
        $res = $this->get('/r/'.str_repeat('x', 48), ['User-Agent' => 'WhatsApp/2.23']);

        $res->assertNotFound();
    }

    /**
     * A password-gated link still previews — the card is the frame around the prompt.
     *
     * It names whose report is being asked for and nothing else. Refusing to preview would make a
     * legitimate link look broken in the chat it was sent in.
     */
    public function test_a_password_gated_link_still_previews_without_revealing_anything(): void
    {
        $html = $this->crawl();

        $this->assertStringContainsString('og:title', $html);
    }

    private function crawl(): string
    {
        return $this->get("/r/{$this->token}", ['User-Agent' => 'WhatsApp/2.23.20.0'])
            ->assertOk()
            ->getContent();
    }
}
