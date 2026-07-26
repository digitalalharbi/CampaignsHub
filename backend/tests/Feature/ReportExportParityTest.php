<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportExportParity;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Export parity: CSV and XLSX must carry the same core KPI numbers as the canonical snapshot. */
final class ReportExportParityTest extends TestCase
{
    use RefreshDatabase;

    private function makeReport(array $kpis, array $platforms): Report
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $data = ['currency' => 'SAR', 'kpis' => $kpis, 'platforms' => $platforms, 'campaigns' => []];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'executive',
            'status' => 'completed', 'currency' => 'SAR', 'data' => $data,
        ]);
    }

    public function test_csv_and_xlsx_match_the_snapshot(): void
    {
        $report = $this->makeReport(
            ['spend' => 12345.67, 'revenue' => 54321.0, 'conversions' => 210.0, 'roas' => 4.4, 'cpa' => 58.79, 'ctr' => 0.0231],
            [['provider' => 'meta', 'spend' => 12345.67, 'revenue' => 54321.0, 'conversions' => 210.0]],
        );

        $result = app(ReportExportParity::class)->check($report);

        $this->assertSame('passed', $result['status'], json_encode($result['numeric_differences']));
        $this->assertSame('passed', $result['csv']);
        $this->assertSame('passed', $result['xlsx']);
        $this->assertEmpty($result['numeric_differences']);
        $this->assertNotNull($result['snapshot_checksum']);
    }
}
