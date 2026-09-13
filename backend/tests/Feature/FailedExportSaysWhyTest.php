<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ExportFailureReason;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — an export that failed says so, and says why.
 *
 * The owner clicked PDF in Production and nothing arrived. The export row was `failed` with the
 * reason in its `error` column the whole time; the API never published it, so the table could only
 * redraw the same button. «Nothing happened» is the one thing the interface must never say when
 * something did.
 *
 * These cases are about the CONTRACT the interface reads, not about rendering: they assert that a
 * failed export is distinguishable from one that was never started, that it carries a reason the
 * product can translate, and that the raw renderer message — paths, binaries, stderr — never
 * crosses the wire.
 */
final class FailedExportSaysWhyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    private function reportWithFailedExport(string $error): Report
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Monthly',
            'type' => 'monthly',
            'status' => 'completed',
            'currency' => 'SAR',
            'audience' => 'client',
            'data' => ['checksum' => 'x'],
            'generated_at' => now(),
        ]);

        ReportExport::create([
            'report_id' => $report->id,
            'format' => 'pdf',
            'status' => 'failed',
            'error' => $error,
            'created_by' => $this->user->id,
        ]);

        return $report;
    }

    /** @return array<string,mixed> the pdf export row as the reports table receives it */
    private function pdfRow(): array
    {
        $response = $this->actingAs($this->user)->getJson("/api/v1/projects/{$this->project->id}/reports");
        $response->assertOk();

        $exports = $response->json('data.reports.0.exports');
        self::assertIsArray($exports, 'the reports table received no exports at all');

        foreach ($exports as $row) {
            if (($row['format'] ?? null) === 'pdf') {
                return $row;
            }
        }

        self::fail('the pdf export was absent from the row the table renders');
    }

    public function test_a_failed_export_publishes_a_reason_the_interface_can_translate(): void
    {
        $this->reportWithFailedExport(
            'Client PDF export requires the Chromium renderer, which is disabled '.
            '(set REPORTS_CHROMIUM_ENABLED=true). Refusing to ship a Dompdf fallback to a client.'
        );

        $row = $this->pdfRow();

        self::assertSame('failed', $row['status']);
        self::assertSame(ExportFailureReason::RENDERER_DISABLED, $row['failure_reason'] ?? null);
    }

    public function test_the_renderer_message_itself_never_crosses_the_wire(): void
    {
        // A real failure from a box with no browser: absolute paths and a binary name.
        $this->reportWithFailedExport(
            'Chromium PDF render failed: Executable doesn\'t exist at '.
            '/home/deploy/.cache/ms-playwright/chromium-1228/chrome-linux/chrome'
        );

        $row = $this->pdfRow();

        self::assertSame(ExportFailureReason::RENDERER_UNAVAILABLE, $row['failure_reason'] ?? null);

        $wire = json_encode($row, JSON_THROW_ON_ERROR);
        foreach (['ms-playwright', '/home/deploy', 'chrome-linux', 'Executable doesn'] as $leak) {
            self::assertStringNotContainsString($leak, $wire, "the export row published «{$leak}»");
        }
    }

    public function test_an_export_that_succeeded_carries_no_reason(): void
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Monthly',
            'type' => 'monthly',
            'status' => 'completed',
            'currency' => 'SAR',
            'audience' => 'client',
            'data' => ['checksum' => 'x'],
            'generated_at' => now(),
        ]);
        ReportExport::create([
            'report_id' => $report->id,
            'format' => 'pdf',
            'status' => 'completed',
            'path' => 'reports/x.pdf',
            'size' => 1024,
            'created_by' => $this->user->id,
        ]);

        // A reason on a healthy export would draw an error beside a working download. Asserted with
        // `array_key_exists` because `?? ` cannot tell a null value from an absent key, and the
        // contract here is that the key is PRESENT and empty.
        $row = $this->pdfRow();
        self::assertArrayHasKey('failure_reason', $row);
        self::assertNull($row['failure_reason']);
    }

    /**
     * The classifier is the product's vocabulary for this failure, so each word it can say is
     * pinned to the message that actually produces it.
     */
    public function test_every_reason_is_reachable_from_a_real_message(): void
    {
        $cases = [
            ExportFailureReason::RENDERER_DISABLED => 'requires the Chromium renderer, which is disabled (set REPORTS_CHROMIUM_ENABLED=true)',
            ExportFailureReason::TEXT_LAYER_FAILED => 'Arabic text-layer validation failed — export blocked. presentation forms remain',
            ExportFailureReason::TIMED_OUT => 'The process "node scripts/report-print.mjs" exceeded the timeout of 60 seconds.',
            ExportFailureReason::RENDERER_UNAVAILABLE => 'Chromium PDF render failed: spawn node ENOENT',
            ExportFailureReason::DATA_NOT_READY => 'Export blocked: the narrative claims 0 results while the snapshot shows 1,158.',
            ExportFailureReason::UNKNOWN => 'Something nobody has classified yet.',
        ];

        foreach ($cases as $expected => $message) {
            self::assertSame($expected, ExportFailureReason::classify($message), "«{$message}» was misread");
        }

        // An empty column is «it failed» and nothing more — never a confident wrong reason.
        self::assertSame(ExportFailureReason::UNKNOWN, ExportFailureReason::classify(null));
        self::assertSame(ExportFailureReason::UNKNOWN, ExportFailureReason::classify('   '));
    }
}
