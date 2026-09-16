<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The watermark a share asks for must reach the FILE, not only the page.
 *
 * ## The defect
 *
 * `report_shares.watermark` was validated, stored, returned in the share payload, drawn on the
 * snapshot page by `PublicReport`, and announced to the client in their own portal as a badge
 * reading «يحمل علامة مائية / Watermarked». The PDF carried none.
 *
 * The absence was STRUCTURAL rather than data-dependent, which is why no unlucky sample was needed
 * to find it and no lucky one could have hidden it: `PublicReportController::download` held the
 * share and applied `allow_download`, `hide_spend`, `hide_revenue` and `hide_campaign_names` — and
 * never `watermark`; `ChromiumPdfRenderer` built its print URL from `type` and `theme` alone; and
 * neither print renderer contained the word. There was no path by which a watermark could appear.
 *
 * The PDF is the artefact a watermark is FOR — the copy that is downloaded, kept and forwarded. A
 * link carrying both `watermark` and `allow_download` told a client their document was marked and
 * handed them a clean one, which is the product making a false statement about what it just
 * produced.
 *
 * ## What is asserted, and why in two halves
 *
 * The chain is share → download → report config → minted print token → print payload → renderer.
 * The last hop needs headless Chromium, which is not a dependency a unit test should acquire, so the
 * two links that can silently break are each covered by a real surface:
 *
 *  1. `ShareService::renderConfig()` — what a share implies for the page, called by the download.
 *  2. `GET /reports/print/{token}` — what the renderer is actually told, over HTTP.
 *
 * The drawing itself is `ReportWatermark.test.tsx`, and the flag reaches it as a prop.
 *
 * XLSX and CSV are deliberately NOT covered: a spreadsheet has no page to mark, and the export
 * manifest already carries the attribution. That is an exclusion with a reason, not an omission.
 */
final class WatermarkReachesTheFileTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'W', 'slug' => 'wm-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@w.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $this->report = Report::create([
            'tenant_id' => $tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Watermarked report',
            'type' => 'executive',
            'form' => 'executive_summary',
            'audience' => 'client',
            'status' => 'completed',
            'period_start' => now()->subDays(29)->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'SAR',
            /*
             * The platforms must ADD UP to the headline, because `ExportReadinessGate` checks that
             * before it will mint a print token — «Sum of platform spend does not match the summary
             * total.» A fixture that skips it is refused 422, which is the gate working rather than
             * a defect, and a fixture the product would never produce proves nothing about it.
             */
            'data' => [
                'kpis' => ['spend' => 1000.0],
                'platforms' => [
                    ['provider' => 'meta', 'spend' => 600.0],
                    ['provider' => 'snapchat', 'spend' => 400.0],
                ],
            ],
        ]);
    }

    public function test_a_link_that_asked_for_a_watermark_carries_it_into_the_exported_file(): void
    {
        [$share] = app(ShareService::class)->create(
            $this->report,
            ['watermark' => true, 'allow_download' => true],
            null,
        );

        $config = app(ShareService::class)->renderConfig((array) ($this->report->config ?? []), $share);

        $this->assertTrue($config['watermark'], 'a link that asked for a watermark exported a clean file');
    }

    /** A link that did not ask for one must not acquire it from the report it points at. */
    public function test_a_link_that_asked_for_none_exports_a_clean_file(): void
    {
        [$share] = app(ShareService::class)->create($this->report, ['allow_download' => true], null);

        $config = app(ShareService::class)->renderConfig((array) ($this->report->config ?? []), $share);

        $this->assertFalse($config['watermark']);
    }

    /**
     * The flag travels WITHOUT disturbing the render decisions already on the config.
     *
     * `pdf_type` decides between the slide deck and the A4 document, and it is read from this same
     * array — a merge that replaced it rather than adding to it would silently change which layout a
     * client receives, which is a larger defect than the one being fixed.
     */
    public function test_carrying_the_watermark_does_not_disturb_the_layout_the_report_asked_for(): void
    {
        [$share] = app(ShareService::class)->create($this->report, ['watermark' => true], null);

        $config = app(ShareService::class)->renderConfig(['pdf_type' => 'document'], $share);

        $this->assertSame('document', $config['pdf_type']);
        $this->assertTrue($config['watermark']);
    }

    /**
     * What the RENDERER is actually told, over HTTP.
     *
     * The flag rides the server-minted token rather than the query string, for the reason `audience`
     * already does: a mark that a caller can drop by editing a URL is a mark removable by the person
     * it exists to deter.
     */
    public function test_the_print_payload_tells_the_renderer_to_draw_the_watermark(): void
    {
        $this->report->update(['config' => ['watermark' => true]]);

        $token = $this->mintToken();

        $this->actingAs($this->owner)
            ->getJson("/api/v1/reports/print/{$token}")
            ->assertOk()
            ->assertJsonPath('data.watermark', true);
    }

    public function test_the_print_payload_asks_for_no_watermark_when_the_link_wanted_none(): void
    {
        $token = $this->mintToken();

        $this->actingAs($this->owner)
            ->getJson("/api/v1/reports/print/{$token}")
            ->assertOk()
            ->assertJsonPath('data.watermark', false);
    }

    /**
     * The hop the other guards cannot see: DOWNLOAD -> report config -> the minted print context.
     *
     * `renderConfig()` is asserted directly above and the print payload over HTTP below, but neither
     * proves the download controller CALLS one or that the renderer READS it — and that one missing
     * line is the whole of the original defect. `ChromiumPdfRenderer::render()` mints its context
     * into the cache BEFORE it spawns Chromium, so pointing the renderer at a binary that does not
     * exist lets the real chain run to the point that matters and fail after it. What is asserted is
     * the context the renderer actually minted, not a value handed to a stub.
     */
    public function test_a_watermarked_link_mints_a_print_context_that_carries_it(): void
    {
        config([
            'reports.chromium.enabled' => true,
            'reports.chromium.node_bin' => '/nonexistent/node-that-cannot-run',
        ]);

        [, $raw] = app(ShareService::class)->create(
            $this->report,
            ['watermark' => true, 'allow_download' => true],
            null,
        );

        Cache::spy();

        // The render is EXPECTED to fail — Chromium cannot run here. What matters happened before it.
        $this->get("/api/v1/reports/shared/{$raw}/download/pdf");

        Cache::shouldHaveReceived('put')->withArgs(
            fn ($key, $value) => is_string($key)
                && str_starts_with($key, 'report-print:')
                && is_array($value)
                && ($value['watermark'] ?? null) === true,
        );
    }

    private function mintToken(): string
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/print-token")
            ->assertOk();

        return (string) $response->json('data.token');
    }
}
