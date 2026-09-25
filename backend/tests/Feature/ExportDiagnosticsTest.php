<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — asking production why a PDF did not arrive.
 *
 * ## Why the preflight was not enough
 *
 * `reports:health` already answered «could the renderer work»: Node, the print script, Playwright, a
 * Chromium binary, the print URL, an Arabic face, the text-layer normaliser, python, disk, queue.
 * Every one of those can pass while every export still fails, because a dependency present on the
 * box is not a PDF in somebody's hands. That is precisely the gap between «the image contains a
 * browser» — which CI proves — and the owner's «PDF export does not work in the real product».
 *
 * So the command now also answers «what did it actually DO»: the recent attempts, their status, the
 * bytes they produced, the renderer version that produced them, whether the Arabic text layer
 * validated, and the reason a failure carried.
 *
 * ## What this test is really protecting
 *
 * Not the happy path — the shape of what a diagnostic is allowed to say. This output goes into a
 * workflow log, and a workflow log is not a place to put a client's report name, the path to their
 * file, or the signed token that would fetch it. The assertions below are mostly about absence, and
 * that is deliberate: the leak is the failure mode worth a test, not the table formatting.
 */
final class ExportDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** The reasons a renderer gives are ours, and they are what the diagnostic exists to surface. */
    public function test_it_reports_why_recent_exports_ended_as_they_did(): void
    {
        $this->export('failed', size: null, error: 'Chromium PDF render failed: net::ERR_CONNECTION_REFUSED');
        $this->export('ready', size: 284_113, error: null);

        $json = $this->healthJson(recent: 10);

        $reasons = array_column($json['recent_exports'], 'error');
        $this->assertContains('Chromium PDF render failed: net::ERR_CONNECTION_REFUSED', $reasons);

        $statuses = array_column($json['recent_exports'], 'status');
        $this->assertContains('failed', $statuses);
        $this->assertContains('ready', $statuses);
    }

    /**
     * **The rule.** A diagnostic names no report, no path and no token.
     *
     * An export row carries all three. They are the client's, they are an authorisation path, and a
     * workflow log is readable by anyone who can read the repository's actions.
     */
    public function test_it_never_prints_a_report_name_a_path_or_a_token(): void
    {
        $this->export('ready', size: 1_024, error: null);

        $json = $this->healthJson(recent: 10);
        $printed = json_encode($json, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('حملة رمضان', (string) $printed, 'a client’s report name reached the log');
        $this->assertStringNotContainsString('reports/secret-path', (string) $printed, 'a stored path reached the log');
        $this->assertStringNotContainsString('tok_shouldnotleak', (string) $printed, 'a signed token reached the log');

        foreach ($json['recent_exports'] as $row) {
            $this->assertArrayNotHasKey('path', $row);
            $this->assertArrayNotHasKey('signed_token', $row);
            $this->assertArrayNotHasKey('report_id', $row);
        }
    }

    /** Asking for none is a real answer, and the default. */
    public function test_it_lists_nothing_unless_asked(): void
    {
        $this->export('ready', size: 1_024, error: null);

        $this->assertSame([], $this->healthJson(recent: 0)['recent_exports']);
    }

    /** A stack trace in a workflow log hides the one line that helps. */
    public function test_a_long_reason_is_truncated_rather_than_pasted_whole(): void
    {
        $this->export('failed', size: null, error: str_repeat('stack frame ', 200));

        $json = $this->healthJson(recent: 5);

        $this->assertLessThan(350, strlen((string) $json['recent_exports'][0]['error']));
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** A project for the report to belong to — reports are project-owned. */
    private function project(): string
    {
        $client = DB::table('client_workspaces')->insertGetId([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'C', 'slug' => 'c-'.Str::random(8), 'mode' => 'managed', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], 'id');

        $projectId = (string) Str::uuid();
        DB::table('projects')->insert([
            'id' => $projectId,
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client,
            'name' => 'P', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $projectId;
    }

    /** @return array<string,mixed> */
    private function healthJson(int $recent): array
    {
        /*
         * `Artisan::call`, not `$this->artisan()`.
         *
         * The test helper returns a PendingCommand that runs on assertion and whose output never
         * reaches `Artisan::output()` — so a test written against it reads an empty string, decodes
         * to nothing, and passes every «does not contain» assertion for the wrong reason. Which is
         * exactly what the first version of this file did.
         */
        Artisan::call('reports:health', ['--json' => true, '--recent' => (string) $recent]);

        $output = Artisan::output();

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($output, true) ?? [];

        return $decoded + ['recent_exports' => []];
    }

    private function export(string $status, ?int $size, ?string $error): void
    {
        $reportId = (string) Str::uuid();
        $projectId = $this->project();

        DB::table('reports')->insert([
            'id' => $reportId,
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId,
            'name' => 'حملة رمضان',
            'type' => 'performance',
            'form' => 'detailed',
            'audience' => 'client',
            'mode' => 'snapshot',
            'status' => 'completed',
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_exports')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'report_id' => $reportId,
            'format' => 'pdf',
            'status' => $status,
            'disk' => 'local',
            'path' => 'reports/secret-path/'.Str::random(8).'.pdf',
            'size' => $size,
            'signed_token' => 'tok_shouldnotleak',
            'renderer' => 'chromium',
            'renderer_version' => 'alpine-chromium-136',
            'validation_status' => $status === 'ready' ? 'passed' : 'unknown',
            'error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
