<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Support\ReportFileName;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-TITLE-METADATA-001 — what the file is called when it lands in somebody's Downloads folder.
 *
 * The download served `basename($export->path)`, and that path is built from `Str::slug($report->name)`
 * — which TRANSLITERATES. «تقرير أداء أغسطس» becomes `tkryr-adaaa-aghsts`, so an Arabic-speaking
 * client's report arrived as `tkryr-adaaa-aghsts-20260830-044500.pdf`.
 *
 * Nothing about the file is wrong except the only thing a filename is for. Saved beside four others
 * it is unidentifiable; forwarded to a colleague it says nothing; and the timestamp — its one legible
 * part — is when we rendered it rather than the period it covers.
 */
final class ReportDownloadIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'متجر الرياض', 'slug' => 'riyadh-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    /** The report's own name, in its own script, with the client and the period it covers. */
    public function test_the_filename_carries_the_report_the_client_and_the_period(): void
    {
        $name = ReportFileName::for($this->report('تقرير أداء أغسطس'), 'pdf');

        $this->assertSame('تقرير أداء أغسطس — متجر الرياض — 2026-08-01 - 2026-08-31.pdf', $name);
    }

    /**
     * The period it COVERS, not the minute it was rendered.
     *
     * The old name carried `now()->format('Ymd-His')`, which answers a question nobody asks of a
     * report they have been sent: two exports of the same August differ by when somebody pressed the
     * button.
     */
    public function test_the_filename_does_not_carry_the_moment_it_was_rendered(): void
    {
        $name = ReportFileName::for($this->report('August performance'), 'pdf');

        $this->assertStringContainsString('2026-08-01 - 2026-08-31', $name);
        $this->assertDoesNotMatchRegularExpression('/\d{8}-\d{6}/', $name, 'a render timestamp is not part of a report’s identity');
    }

    /** A report with no period still says what it is and whose it is. */
    public function test_a_report_with_no_period_still_names_itself_and_its_client(): void
    {
        $report = $this->report('تقرير أداء أغسطس');
        $report->forceFill(['period_start' => null, 'period_end' => null])->save();

        $this->assertSame('تقرير أداء أغسطس — متجر الرياض.pdf', ReportFileName::for($report, 'pdf'));
    }

    /** Characters a filesystem reads as structure are stripped, not escaped into the name. */
    public function test_path_separators_never_reach_the_filename(): void
    {
        $name = ReportFileName::for($this->report('Q3/Q4 "review": ads*'), 'csv');

        foreach (['/', '\\', ':', '*', '"'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $name);
        }
        $this->assertStringEndsWith('.csv', $name);
    }

    /** Long enough for a real name, bounded for every filesystem — and the REPORT's name survives. */
    public function test_a_very_long_name_is_trimmed_from_the_end(): void
    {
        $name = ReportFileName::for($this->report(str_repeat('تقرير ', 40)), 'pdf');

        $this->assertLessThanOrEqual(125, mb_strlen($name));
        $this->assertStringStartsWith('تقرير', $name);
    }

    /** And the download serves it, rather than the storage key. */
    public function test_the_download_is_named_for_the_report_not_for_the_bucket_key(): void
    {
        Storage::fake('local');
        $report = $this->report('تقرير أداء أغسطس');
        Storage::disk('local')->put('reports/tkryr-adaaa-aghsts-20260830-044500.pdf', 'x');

        $export = ReportExport::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'format' => 'pdf',
            'status' => 'completed',
            'disk' => 'local',
            'path' => 'reports/tkryr-adaaa-aghsts-20260830-044500.pdf',
            'signed_token' => 'tok_'.Str::random(20),
            'expires_at' => now()->addDay(),
            'renderer' => 'chromium',
            'renderer_version' => (string) config('reports.chromium.renderer_version', 'chromium-1228'),
            'template_version' => ReportExporter::TEMPLATE_VERSION,
            'validation_status' => 'passed',
        ]);

        $response = $this->get("/api/v1/reports/download/{$export->signed_token}");
        $response->assertOk();

        $disposition = (string) $response->headers->get('content-disposition');

        /*
         * RFC 5987: the UTF-8 form carries the real name and an ASCII fallback sits beside it for
         * anything that cannot read one. Asserting the encoded form rather than the raw string is
         * what proves the header is well-formed rather than merely containing the characters.
         */
        $this->assertStringContainsString("filename*=utf-8''", strtolower($disposition));
        $this->assertStringContainsString(rawurlencode('تقرير أداء أغسطس'), $disposition);
        $this->assertStringNotContainsString('tkryr-adaaa-aghsts', $disposition, 'the transliteration is the storage key, not the client’s file');
    }

    private function report(string $name): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => $name,
            'type' => 'monthly',
            'status' => 'completed',
            'currency' => 'SAR',
            'audience' => 'client',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'data' => ['period' => ['from' => '2026-08-01', 'to' => '2026-08-31']],
            'generated_at' => now(),
        ]);
    }
}
