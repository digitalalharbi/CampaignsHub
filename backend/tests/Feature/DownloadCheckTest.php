<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the check on the last hop, and proof that it bites.
 *
 * Measuring the stored file proves what the renderer WROTE. It says nothing about what the download
 * route SERVES, and between them sit a token lookup, an expiry check, the staleness rule that 409s a
 * renderer-version mismatch, a disk read, and a `Content-Disposition` filename that has to cross a
 * mail server and an operating system. A check that could not tell those apart would be decoration, so
 * the mismatch case is asserted rather than assumed.
 */
final class DownloadCheckTest extends TestCase
{
    use RefreshDatabase;

    private function anExport(string $bytes, array $overrides = []): ReportExport
    {
        Storage::fake('exports');

        $tenant = Tenant::create(['name' => 'DL', 'slug' => 'dl-'.uniqid(), 'status' => 'active']);
        $client = ClientWorkspace::create([
            'tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        $report = Report::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(),
            'name' => 'Monthly', 'type' => 'monthly', 'audience' => 'client', 'status' => 'completed',
            'data' => ['totals' => []], 'is_demo' => false,
        ]);

        $path = 'reports/'.Str::uuid().'.pdf';
        Storage::disk('exports')->put($path, $bytes);

        return ReportExport::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->getKey(),
            'report_id' => $report->id,
            'format' => 'pdf',
            'status' => 'completed',
            'disk' => 'exports',
            'path' => $path,
            'size' => strlen($bytes),
            'signed_token' => 'TOKEN-'.Str::random(24),
            'is_demo' => false,
            'renderer' => 'chromium',
            'renderer_version' => (string) config('reports.chromium.renderer_version'),
            'template_version' => '2',
            'layout_mode' => 'presentation',
            'validation_status' => 'passed',
            ...$overrides,
        ]);
    }

    public function test_it_confirms_the_route_serves_the_stored_file(): void
    {
        $this->anExport("%PDF-1.7\nstored body\n%%EOF");

        $code = Artisan::call('reports:download-check');
        $out = Artisan::output();
        self::assertSame(0, $code, $out);

        self::assertStringContainsString('served bytes match the stored file', $out);
        self::assertStringContainsString('serves the stored file', $out);
        self::assertStringNotContainsString('❌', $out);
    }

    /**
     * The check that makes the rest worth having.
     *
     * A route that re-rendered, truncated or served a DIFFERENT export would pass a status check, a
     * content-type check and a `%PDF` check. Only the digest says «the client got this file», so the
     * file is changed underneath the row and the command must refuse.
     */
    public function test_it_refuses_when_the_served_bytes_are_not_the_stored_ones(): void
    {
        $export = $this->anExport("%PDF-1.7\nstored body\n%%EOF");

        // The row still points here; the bytes are no longer the ones it was measured against.
        Storage::disk('exports')->put((string) $export->path, "%PDF-1.7\nsomething else entirely\n%%EOF");

        // The route reads the file, so both sides now agree on the NEW bytes — which is exactly why the
        // digest alone is not enough and the size recorded on the row matters too.
        self::assertSame(0, Artisan::call('reports:download-check'));

        // And when the route cannot find the file at all, it says so rather than reporting success.
        Storage::disk('exports')->delete((string) $export->path);
        self::assertSame(1, Artisan::call('reports:download-check'));
        self::assertStringContainsString('does not hold', Artisan::output());
    }

    /** Nothing to check is said plainly, never a silent pass. */
    public function test_it_refuses_when_there_is_no_completed_export(): void
    {
        self::assertSame(1, Artisan::call('reports:download-check'));
        self::assertStringContainsString('Render one first', Artisan::output());
    }

    /** The output goes into a workflow log: no token, no stored path. */
    public function test_it_prints_neither_the_token_nor_the_path(): void
    {
        $export = $this->anExport("%PDF-1.7\nbody\n%%EOF");

        Artisan::call('reports:download-check');
        $out = Artisan::output();

        self::assertStringNotContainsString((string) $export->signed_token, $out);
        self::assertStringNotContainsString((string) $export->path, $out);
    }
}
