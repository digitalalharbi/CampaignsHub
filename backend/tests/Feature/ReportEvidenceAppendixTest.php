<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\PlatformEvidence;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WD-EVIDENCE-001 — the platform screenshots the Owner included travel into the report's PDF as its
 * appendix: only this report's (its project, or its client with no project named), only for a period
 * that overlaps it, only those marked «include in report». The renderer fetches each image with its
 * print token, and a token never opens a screenshot that is not in its own report's appendix.
 */
final class ReportEvidenceAppendixTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    private Project $project;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'Ev', 'slug' => 'ev-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(), 'name' => 'P', 'status' => 'active']);

        $data = ['period' => ['from' => '2026-08-01', 'to' => '2026-08-31'], 'kpis' => ['spend' => 100.0], 'platforms' => [['provider' => 'meta', 'spend' => 100.0]]];
        $data['checksum'] = ExportReadinessGate::checksum($data);
        $this->report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR', 'data' => $data,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function evidence(array $over = []): PlatformEvidence
    {
        $path = 'evidence/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, 'PNGDATA');

        return PlatformEvidence::create(array_replace([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(), 'project_id' => $this->project->getKey(),
            'provider' => 'meta', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'disk' => 'local', 'path' => $path,
            'original_name' => 'ads-manager.png', 'mime' => 'image/png', 'size' => 7, 'note' => 'Ads Manager, August', 'include_in_report' => true,
        ], $over));
    }

    private function token(): string
    {
        $token = Str::random(48);
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'watermark' => false,
        ], 300);

        return $token;
    }

    public function test_the_appendix_carries_only_this_reports_included_screenshots(): void
    {
        $mine = $this->evidence();
        $clientWide = $this->evidence(['project_id' => null, 'provider' => 'snapchat']);
        $pdf = $this->evidence(['mime' => 'application/pdf', 'original_name' => 'invoice.pdf']);
        $this->evidence(['include_in_report' => false]);
        $this->evidence(['period_start' => '2026-06-01', 'period_end' => '2026-06-30']);
        $other = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->client->getKey(), 'name' => 'Q', 'status' => 'active']);
        $this->evidence(['project_id' => $other->getKey()]);
        $stranger = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'S', 'slug' => 's-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->evidence(['project_id' => null, 'client_workspace_id' => $stranger->getKey()]);

        $token = $this->token();
        $appendix = collect($this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data.platform_evidence'))->keyBy('id');

        $this->assertEqualsCanonicalizing([(string) $mine->id, (string) $clientWide->id, (string) $pdf->id], $appendix->keys()->all());
        $this->assertStringEndsWith("/api/v1/reports/print/{$token}/evidence/{$mine->id}", $appendix[(string) $mine->id]['url']);
        $this->assertSame('Ads Manager, August', $appendix[(string) $mine->id]['note']);
        $this->assertFalse($appendix[(string) $pdf->id]['image']);
        $this->assertNull($appendix[(string) $pdf->id]['url'], 'a PDF screenshot is named, not drawn');
    }

    public function test_the_token_serves_its_own_appendix_images_and_nothing_else(): void
    {
        $mine = $this->evidence();
        $excluded = $this->evidence(['include_in_report' => false]);
        $token = $this->token();

        $response = $this->get("/api/v1/reports/print/{$token}/evidence/{$mine->id}")->assertOk();
        $this->assertSame('PNGDATA', $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));

        $this->get("/api/v1/reports/print/{$token}/evidence/{$excluded->id}")->assertNotFound();
        $this->get('/api/v1/reports/print/not-a-token/evidence/'.$mine->id)->assertNotFound();
    }

    public function test_a_report_without_screenshots_has_no_appendix(): void
    {
        $this->assertArrayNotHasKey('platform_evidence', $this->getJson('/api/v1/reports/print/'.$this->token())->assertOk()->json('data.data'));
    }
}
