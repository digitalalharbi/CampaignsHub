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
use Tests\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the download a CLIENT clicks, which nothing covered.
 *
 * `/reports/shared/{token}/download/{format}` is the file a client actually receives: no session,
 * no operator, just the link they were sent. It renders through `ReportExporter::render()` — the
 * same path whose client sanitiser refused every export until #417 — and had no test at all, so the
 * claim that the fix reached this surface rested on both calls going through one function rather
 * than on the surface answering.
 *
 * ## The fixture is the shape that used to fail
 *
 * The payload carries `ads_platform_groups`, the section added by #409 and missed by the sanitiser,
 * with a `campaign_id` on the ad inside it. Before that fix this exact document produced
 * «Client export blocked — internal content detected: campaign_management_entity» in all three
 * formats. If the sanitiser ever stops walking every `ads` list at any depth, this fails here first
 * — on the surface a client is looking at.
 */
final class SharedLinkDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'sd-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($owner, $tenant);
        $owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $this->report = Report::create([
            'project_id' => $project->id,
            'name' => 'التقرير الشهري',
            'type' => 'executive',
            'status' => 'completed',
            'currency' => 'SAR',
            'audience' => 'client',
            'data' => [
                'kpis' => ['spend' => 1000, 'revenue' => 4000, 'roas' => 4.0, 'impressions' => 50000, 'clicks' => 900],
                'platforms' => [['provider' => 'meta', 'spend' => 1000, 'revenue' => 4000]],
                /*
                 * The section that used to block every client export, with the key that did it.
                 * `campaign_id` must not survive into the file, and the file must still be produced.
                 */
                'ads_platform_groups' => [[
                    'provider' => 'meta',
                    'groups' => [[
                        'key' => 'sales',
                        'ads' => [[
                            'name' => 'Story A',
                            'campaign_id' => '56f56d91-1496-4743-aec1-5d41e0732a6f',
                            'campaign_name' => 'Meta — Lead Gen (burner)',
                            'spend' => 400.0,
                        ]],
                    ]],
                ]],
            ],
        ]);

        app(TenantContext::class)->forget();
    }

    /** The spreadsheet a client is sent is a real OOXML package, named after the report. */
    public function test_the_shared_link_delivers_a_real_xlsx(): void
    {
        $response = $this->get("/api/v1/reports/shared/{$this->link()}/download/xlsx");
        $response->assertOk();

        $body = $response->streamedContent();

        // PK.. — a zip. An HTML error page with a spreadsheet extension is the failure this catches.
        $this->assertSame("PK\x03\x04", substr($body, 0, 4), 'the xlsx is not a zip');
        $this->assertStringContainsString('xl/workbook.xml', $body, 'the zip is not a workbook');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    /** And the CSV, which is the format a client opens in whatever they already have. */
    public function test_the_shared_link_delivers_a_csv_carrying_the_figures(): void
    {
        $response = $this->get("/api/v1/reports/shared/{$this->link()}/download/csv");
        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('Platforms', $body, 'the csv names no platform section');
        $this->assertStringContainsString('meta', $body);
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    /**
     * The figure on the page and the figure in the file are the SAME figure.
     *
     * The brief's own words: «headline metrics must reconcile» across every output. It is not
     * satisfied by the file merely being produced — a client reads a spend on the link, downloads
     * the spreadsheet, and the two numbers are put side by side by the person least able to explain
     * a difference. The existing cases here assert that the CSV names a platform and carries no
     * campaign identity; neither would notice the figures drifting.
     *
     * They CAN drift. The two paths are not one call: the page is served by
     * `PublicReportController::show`, while the download replicates the report, merges the share's
     * render flags, refreshes creative media and runs `sanitize()` before the exporter reads it.
     * Sharing a source is the reason they SHOULD agree; it is not the same as agreeing, which is
     * the argument `LiveAndDetailedAgreeTest` already makes for the other pair.
     *
     * Asserted between the two SURFACES rather than against a literal. A test pinned to «1000»
     * passes while both drift together, and drifting together is exactly what happens when a
     * transformation is added to the shared step.
     */
    public function test_the_downloaded_file_states_the_same_headline_the_link_does(): void
    {
        $token = $this->link();

        $onThePage = $this->get("/api/v1/reports/shared/{$token}")
            ->assertOk()
            ->json('data.data.kpis.spend');

        $this->assertNotNull($onThePage, 'the link published no headline spend to reconcile against');

        $csv = $this->get("/api/v1/reports/shared/{$token}/download/csv")
            ->assertOk()
            ->streamedContent();

        $this->assertMatchesRegularExpression(
            '/^spend,'.preg_quote((string) (float) $onThePage, '/').'(\.0+)?$/m',
            $csv,
            'the client reads one spend on the link and a different one in the file they downloaded',
        );
    }

    /**
     * The whole reason this surface is worth a test: the file is produced, AND it is clean.
     *
     * A client export that succeeds by shipping campaign identity is worse than one that refuses.
     * Both halves are asserted on the same bytes.
     */
    public function test_the_delivered_file_carries_no_campaign_identity(): void
    {
        $body = $this->get("/api/v1/reports/shared/{$this->link()}/download/csv")->assertOk()->streamedContent();

        $this->assertStringNotContainsString('56f56d91-1496-4743-aec1-5d41e0732a6f', $body, 'a campaign id reached a client file');
        $this->assertStringNotContainsString('burner', mb_strtolower($body), 'a campaign name reached a client file');
    }

    /** A link whose owner disabled downloads does not serve one, whatever format is asked for. */
    public function test_a_link_with_downloads_disabled_refuses(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, ['allow_download' => false], null);

        $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertForbidden();
    }

    /** An unknown format is not a file this product produces, and is refused rather than guessed. */
    public function test_an_unknown_format_is_refused(): void
    {
        $this->get("/api/v1/reports/shared/{$this->link()}/download/docx")->assertNotFound();
    }

    private function link(): string
    {
        [, $raw] = app(ShareService::class)->create($this->report, ['allow_download' => true], null);

        return $raw;
    }
}
