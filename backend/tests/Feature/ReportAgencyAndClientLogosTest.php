<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Models\BrandingAsset;
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
 * REPORT BRANDING — a client's report carries BOTH marks: the issuing agency's and the client's.
 *
 * `SharedLinkBranding` resolved ONE logo from the nearest scope, so a client logo hid the agency's
 * and the agency survived only as the words «by X». The Owner's report shows the agency that issued it
 * and the client it is about, side by side. Each mark is its own layer's — the agency's never falls
 * back to the platform's, and the client's never borrows the agency's — because a report showing the
 * agency's logo twice, or CampaignsHub's in the agency's place, is wrong in a way a reader can see.
 *
 * The bytes are still addressed by the TOKEN and a ROLE, never an id, and a second tenant's marks
 * cannot come out under either role.
 */
final class ReportAgencyAndClientLogosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private Tenant $rival;

    private ClientWorkspace $client;

    private Report $report;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Tenant::create(['name' => 'Razah Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        $this->rival = Tenant::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid(), 'status' => 'active']);

        app(TenantContext::class)->setTenantId($this->agency->id);
        $this->client = ClientWorkspace::create(['name' => 'Nakheel', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);
        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'تقرير الأداء الشهري', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => ['kpis' => []],
            'config' => ['locale' => 'ar'],
        ]);
        [, $this->token] = app(ShareService::class)->create($this->report, ['scope' => ['project_id' => $project->id], 'mode' => 'live'], null);
        app(TenantContext::class)->forget();
    }

    public function test_a_report_names_and_serves_both_the_agency_and_the_client_logo(): void
    {
        $this->logo($this->agency, 'tenant', null, 'AGENCY-BYTES');
        $this->logo($this->agency, 'client', (string) $this->client->id, 'CLIENT-BYTES');

        $body = $this->branding();

        $this->assertSame('Razah Agency', $body['agency']['name'] ?? null);
        $this->assertNotNull($body['agency']['logo_url'] ?? null, 'the issuing agency’s logo was not named');
        $this->assertSame('Nakheel', $body['client']['name'] ?? null);
        $this->assertNotNull($body['client']['logo_url'] ?? null, 'the client’s logo was not named');

        $this->assertStringContainsString('AGENCY-BYTES', $this->bytes($body['agency']['logo_url']));
        $this->assertStringContainsString('CLIENT-BYTES', $this->bytes($body['client']['logo_url']));
    }

    public function test_each_mark_is_its_own_layers_and_never_borrows_another(): void
    {
        // Only the client has a logo: the agency's mark is absent rather than the client's shown twice.
        $this->logo($this->agency, 'client', (string) $this->client->id, 'CLIENT-BYTES');
        $body = $this->branding();
        $this->assertNull($body['agency']['logo_url'], 'the agency mark borrowed another layer’s logo');
        $this->assertSame('Razah Agency', $body['agency']['name']);
        $this->get($this->logoPath('agency'))->assertNotFound();

        // Only the agency has one: the client's mark is absent, not the agency's in its place.
        Storage::fake('local');
        BrandingAsset::withoutGlobalScopes()->delete();
        $this->logo($this->agency, 'tenant', null, 'AGENCY-BYTES');
        $body = $this->branding();
        $this->assertNull($body['client']['logo_url'], 'the client mark borrowed the agency’s logo');
        $this->get($this->logoPath('client'))->assertNotFound();
    }

    public function test_a_report_with_no_logos_names_both_and_links_no_image(): void
    {
        $body = $this->branding();

        $this->assertSame(['name' => 'Razah Agency', 'logo_url' => null], $body['agency']);
        $this->assertSame(['name' => 'Nakheel', 'logo_url' => null], $body['client']);
    }

    public function test_another_tenants_marks_never_come_out_under_either_role(): void
    {
        $this->logo($this->rival, 'tenant', null, 'RIVAL-AGENCY');
        $this->logo($this->rival, 'client', (string) $this->client->id, 'RIVAL-CLIENT');

        $this->get($this->logoPath('agency'))->assertNotFound();
        $this->get($this->logoPath('client'))->assertNotFound();

        $this->logo($this->agency, 'tenant', null, 'OWN-AGENCY');
        $bytes = $this->get($this->logoPath('agency'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('RIVAL', $bytes);
    }

    public function test_the_shared_payload_states_the_reports_language(): void
    {
        $this->assertSame('ar', $this->getJson("/api/v1/reports/shared/{$this->token}")->assertOk()->json('data.locale'));
    }

    /**
     * The card is the CLIENT's, in the report's language — and this assertion changed with it.
     *
     * It used to read «تقرير الأداء الشهري — كامبينز هب»: the report's name ending with the
     * product's. SHARE-PREVIEW-CLIENT-IDENTITY-001 overrides that — «Do NOT use CampaignsHub as the
     * primary report-card identity when the Client has a logo» — because a crawler renders this
     * line first and largest, and a client's first sight of their own report should not be our
     * name.
     *
     * The LANGUAGE claim this test is really about is untouched and still asserted: the card, the
     * document's `lang` and `og:site_name` all follow the report's own language.
     */
    public function test_a_reports_card_leads_with_the_client_in_the_reports_own_language(): void
    {
        $this->assertSame('Nakheel — تقرير الأداء الشهري', $this->meta($this->preview(), 'og:title'));

        app(TenantContext::class)->setTenantId($this->agency->id);
        $this->report->update(['name' => 'Monthly performance', 'config' => ['locale' => 'en']]);
        app(TenantContext::class)->forget();

        $html = $this->preview();
        $this->assertSame('Nakheel — Monthly performance', $this->meta($html, 'og:title'));
        /*
         * The `<title>` matches, and that is right rather than an oversight.
         *
         * This document is the CRAWLER's — it is served only to a bot user-agent — and several
         * crawlers read `<title>` when `og:title` is absent or when they prefer it. A card whose
         * two titles disagreed would render differently depending on which one the reader's app
         * happened to use. The HUMAN's browser tab is set by the SPA (`reportPageTitle`), which is
         * where keeping the product's name still earns its place: it tells two open report links
         * apart.
         */
        $this->assertStringContainsString('<title>Nakheel — Monthly performance</title>', $html);
        $this->assertStringContainsString('lang="en"', $html);
        $this->assertSame('CampaignsHub', $this->meta($html, 'og:site_name'));
    }

    private function preview(): string
    {
        app(TenantContext::class)->forget();

        return (string) $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])->get("/r/{$this->token}")->assertOk()->getContent();
    }

    private function meta(string $html, string $property): ?string
    {
        return preg_match('/<meta (?:property|name)="'.preg_quote($property, '/').'" content="([^"]*)"/u', $html, $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES)
            : null;
    }

    /** @return array<string, mixed> */
    private function branding(): array
    {
        app(TenantContext::class)->forget();

        return $this->getJson("/api/v1/reports/shared/{$this->token}/branding")->assertOk()->json('data');
    }

    private function logoPath(string $role): string
    {
        return "/api/v1/reports/shared/{$this->token}/branding/logo/{$role}";
    }

    private function bytes(string $url): string
    {
        app(TenantContext::class)->forget();

        return $this->get((string) parse_url($url, PHP_URL_PATH))->assertOk()->streamedContent();
    }

    private function logo(Tenant $tenant, string $scope, ?string $scopeId, string $marker): BrandingAsset
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $asset = app(BrandingService::class)->storeAsset(
            $scope, $scopeId, $scope === 'client' ? 'client_logo' : 'report_logo', 'any',
            UploadedFile::fake()->createWithContent('logo.png', $marker),
        );
        app(TenantContext::class)->forget();

        return $asset;
    }
}
