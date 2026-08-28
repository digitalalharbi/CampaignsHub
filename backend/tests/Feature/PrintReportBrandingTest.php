<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BRANDING-HIERARCHY-001 on the EXPORTED document.
 *
 * The print surface hardcoded «CampaignsHub» in the PDF title, in the slide meta and in the footer,
 * so every agency's client PDF was stamped with the product's name — the same defect as the shared
 * link's header, on the artefact a client keeps and forwards.
 *
 * The print route is sessionless by design: headless Chromium fetches it with a short-lived token and
 * no cookie. So the identity has to arrive in the payload, resolved server-side from that token —
 * the same rule as the share route, for the same reason.
 */
final class PrintReportBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private ClientWorkspace $client;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Tenant::create(['name' => 'Al Harbi Agency', 'slug' => 'pb-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->agency->id);

        $this->client = ClientWorkspace::create(['name' => 'Nakheel', 'slug' => 'pc-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);

        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'USD', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'audience' => 'client', 'data' => ['kpis' => []],
        ]);
    }

    /** The document carries the client's identity, not the product's. */
    public function test_the_print_payload_carries_the_clients_identity(): void
    {
        $body = $this->print();

        $this->assertSame('Nakheel', $body['branding']['name']);
        $this->assertSame('Al Harbi Agency', $body['branding']['by']);
    }

    /** With a logo, the payload names a URL the renderer can actually fetch without a session. */
    public function test_the_logo_is_addressed_by_the_print_token(): void
    {
        $this->logo();

        $body = $this->print();

        $this->assertNotNull($body['branding']['logo_url']);
        $this->assertStringContainsString('/reports/print/', $body['branding']['logo_url']);
        // No asset id anywhere in it — there is nothing for a caller to change.
        $this->assertStringNotContainsString('assets/', $body['branding']['logo_url']);
    }

    /** With no logo, no URL — an <img> that 404s in a PDF is a permanent broken box. */
    public function test_no_logo_means_no_url_rather_than_one_that_will_not_load(): void
    {
        $this->assertNull($this->print()['branding']['logo_url']);
    }

    /** The bytes are served to a sessionless renderer, and only through a live print token. */
    public function test_the_logo_bytes_need_a_valid_print_token(): void
    {
        $this->logo();

        $token = $this->token();
        $this->get("/api/v1/reports/print/{$token}/logo")->assertOk();

        // An expired or invented token gets nothing — the same gate the payload itself is behind.
        $this->get('/api/v1/reports/print/'.Str::random(48).'/logo')->assertNotFound();
    }

    /** @return array<string,mixed> */
    private function print(): array
    {
        app(TenantContext::class)->forget();

        return $this->getJson("/api/v1/reports/print/{$this->token()}")->assertOk()->json('data');
    }

    private function token(): string
    {
        $token = Str::random(48);
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->id,
            'type' => 'presentation',
            'theme' => 'light',
            'audience' => 'client',
        ], 300);

        return $token;
    }

    private function logo(): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);
        app(BrandingService::class)->storeAsset(
            'client',
            (string) $this->client->id,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('logo.png', 'CLIENT-MARK'),
        );
        app(TenantContext::class)->forget();
    }
}
