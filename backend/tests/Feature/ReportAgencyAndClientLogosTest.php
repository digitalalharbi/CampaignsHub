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
