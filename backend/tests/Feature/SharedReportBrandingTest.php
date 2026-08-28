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
 * BRANDING-HIERARCHY-001 on the surface where it matters most — a link read by somebody with no
 * session, no way to cross-check and nobody to ask.
 *
 * Two things are being asserted, and the second is the one worth being careful about.
 *
 * **The hierarchy.** A client's report carries the client's identity, falling back to the agency and
 * then to CampaignsHub. The chain is `BrandingService::resolve()`, which already existed for
 * AGENCY-005 — this reuses it rather than growing a second branding engine that would drift.
 *
 * **The isolation.** The link resolves everything from its TOKEN. No asset id, tenant id or client
 * id is accepted from the caller, because an endpoint that takes one is an endpoint somebody will
 * enumerate — and a shared report link is exactly where a stranger has a URL and time. The test
 * proves the token cannot be made to serve another tenant's logo, and it is written the way an
 * attacker would try it.
 */
final class SharedReportBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private Tenant $otherAgency;

    private ClientWorkspace $client;

    private Report $report;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'br-'.uniqid(), 'status' => 'active']);
        $this->otherAgency = Tenant::create(['name' => 'Rival', 'slug' => 'br2-'.uniqid(), 'status' => 'active']);

        app(TenantContext::class)->setTenantId($this->agency->id);

        $this->client = ClientWorkspace::create(['name' => 'Nakheel', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);

        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'USD', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => ['kpis' => []],
        ]);

        [, $this->token] = app(ShareService::class)->create($this->report, [
            'scope' => ['project_id' => $project->id],
            'mode' => 'live',
        ], null);

        app(TenantContext::class)->forget();
    }

    /** The identity a client sees is their own, when their own has been set. */
    public function test_a_shared_link_carries_the_clients_identity_when_one_is_set(): void
    {
        $this->asset('client', (string) $this->client->id, 'Nakheel logo');

        $body = $this->read();

        $this->assertSame('client', $body['logo_source']);
        $this->assertNotNull($body['logo_url']);
    }

    /** With no client logo, the agency's stands in — never a blank header. */
    public function test_it_falls_back_to_the_agency_when_the_client_has_no_logo(): void
    {
        $this->asset('tenant', null, 'Agency logo');

        $body = $this->read();

        $this->assertSame('tenant', $body['logo_source']);
        $this->assertNotNull($body['logo_url']);
    }

    /**
     * With neither, the product's own identity — and never a broken image.
     *
     * A missing logo must resolve to «no logo, use the name», not to a URL that 404s. A broken image
     * in a client's report is worse than no image: it looks like the report itself failed.
     */
    public function test_it_falls_back_to_the_product_rather_than_to_a_broken_image(): void
    {
        $body = $this->read();

        // No logo anywhere — so no logo, and no URL that would 404.
        $this->assertSame('none', $body['logo_source']);
        $this->assertNull($body['logo_url']);

        /*
         * …but the NAME still follows client → agency → CampaignsHub. The client's own name is what
         * this report is; falling all the way to «CampaignsHub» because no image was uploaded would
         * put the product's identity on an agency's client report.
         */
        $this->assertSame('Nakheel', $body['name']);
    }

    /**
     * THE ISOLATION TEST — a link cannot be made to serve another tenant's logo.
     *
     * Written as an attacker would: the rival's asset exists, its id is known, and every parameter
     * an enumerator would reach for is appended to the request. None of them may change the answer,
     * because the token is the only thing the endpoint reads.
     */
    public function test_no_parameter_can_make_a_link_serve_another_tenants_logo(): void
    {
        app(TenantContext::class)->setTenantId($this->otherAgency->id);
        $rival = $this->asset('tenant', null, 'Rival logo');
        app(TenantContext::class)->forget();

        $this->asset('tenant', null, 'Agency logo');

        $mine = $this->read();

        foreach ([
            ['asset_id' => $rival->id],
            ['branding_asset' => $rival->id],
            ['tenant_id' => $this->otherAgency->id],
            ['scope' => 'tenant', 'scope_id' => $this->otherAgency->id],
            ['client_workspace_id' => $this->otherAgency->id],
        ] as $attempt) {
            $body = $this->read($attempt);

            $this->assertSame(
                $mine,
                $body,
                'a query parameter changed which tenant’s branding a shared link returned: '.json_encode($attempt),
            );
        }
    }

    /**
     * The bytes the link serves are THIS tenant's — asserted on the content, not on the absence of a
     * parameter.
     *
     * The invariance test above is worth keeping and is not sufficient on its own: it passes today
     * even if the resolver is made to honour a caller-supplied scope, because the asset model's
     * tenant scope keeps the rival's row unreachable anyway. It therefore proves «the query string is
     * ignored» and NOT «another tenant's logo cannot come out». This proves the second, by giving the
     * two tenants distinguishable bytes and reading what actually arrives.
     */
    public function test_the_bytes_a_link_serves_belong_to_its_own_tenant(): void
    {
        app(TenantContext::class)->setTenantId($this->otherAgency->id);
        $this->assetWith('tenant', null, 'RIVAL-BYTES');
        app(TenantContext::class)->forget();

        app(TenantContext::class)->setTenantId($this->agency->id);
        $this->assetWith('tenant', null, 'OWN-BYTES');
        app(TenantContext::class)->forget();

        app(TenantContext::class)->forget();

        $bytes = $this->get("/api/v1/reports/shared/{$this->token}/branding/logo")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('OWN-BYTES', $bytes);
        $this->assertStringNotContainsString('RIVAL-BYTES', $bytes, 'a shared link served another tenant’s logo');
    }

    /** And the asset bytes themselves are reached through the token, never by asset id. */
    public function test_the_logo_bytes_are_served_through_the_token_and_not_by_asset_id(): void
    {
        app(TenantContext::class)->setTenantId($this->otherAgency->id);
        $rival = $this->asset('tenant', null, 'Rival logo');
        app(TenantContext::class)->forget();

        // The Branding Center's own file route is authenticated; a stranger with a share token has no
        // session at all, so it must refuse rather than serve.
        $this->getJson("/api/v1/branding/assets/{$rival->id}/file")->assertUnauthorized();
    }

    /**
     * Branding must not spend the quota that protects the FIGURES.
     *
     * This is a regression, and it was found by running the report E2E specs locally rather than by
     * reasoning: adding one branding request per page load to the shared `share:{ip}` bucket pushed
     * the public report over its 60/minute ceiling, and it began rendering «تعذّر فتح التقرير — Too
     * many requests» on a link that had been fine. On this surface that means a paying client is told
     * their report is broken.
     */
    public function test_asking_for_branding_does_not_use_up_the_reports_own_request_quota(): void
    {
        // Well past the figures ceiling of 60/minute, on branding alone.
        for ($i = 0; $i < 70; $i++) {
            $this->read();
        }

        // The report itself must still answer — its bucket was never touched.
        $this->getJson("/api/v1/reports/shared/{$this->token}")->assertOk();
    }

    /** @return array<string,mixed> */
    private function read(array $query = []): array
    {
        /*
         * No tenant in context — a public link has none, and an earlier version of this file passed
         * only because the asset helper left one behind. A test that reads ambient state proves the
         * state, not the code.
         */
        app(TenantContext::class)->forget();

        $url = "/api/v1/reports/shared/{$this->token}/branding";
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->getJson($url)->assertOk()->json('data');
    }

    /** `storeAsset` writes under the tenant in context, so the caller's tenant is what it belongs to. */
    /** Same as `asset()`, with content that says which tenant it belongs to. */
    private function assetWith(string $scope, ?string $scopeId, string $marker): BrandingAsset
    {
        if (app(TenantContext::class)->tenantId() === null) {
            app(TenantContext::class)->setTenantId($this->agency->id);
        }

        return app(BrandingService::class)->storeAsset(
            $scope,
            $scopeId,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('logo.png', $marker),
        );
    }

    private function asset(string $scope, ?string $scopeId, string $name): BrandingAsset
    {
        if (app(TenantContext::class)->tenantId() === null) {
            app(TenantContext::class)->setTenantId($this->agency->id);
        }

        return app(BrandingService::class)->storeAsset(
            $scope,
            $scopeId,
            'report_logo',
            'any',
            UploadedFile::fake()->create($name.'.png', 10, 'image/png'),
        );
    }
}
