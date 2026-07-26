<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportDataValidator;
use App\Domains\Reports\Services\ReportExporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Regression guard for the exact PDF defects: a report with results but zero spend, or platform sums
 * that disagree with the summary, must fail validation and BLOCK export — never render a broken file.
 */
final class ReportValidationTest extends TestCase
{
    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'SAR',
            'period' => ['from' => '2026-07-01', 'to' => '2026-07-26'],
            'generated_at' => '2026-07-26T00:00:00+00:00',
            'kpis' => ['spend' => 1000, 'revenue' => 4000, 'conversions' => 50, 'roas' => 4.0, 'cpa' => 20.0],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 600, 'revenue' => 2400, 'conversions' => 30],
                ['provider' => 'google', 'spend' => 400, 'revenue' => 1600, 'conversions' => 20],
            ],
            'campaigns' => [],
        ], $overrides);
    }

    public function test_consistent_snapshot_passes(): void
    {
        $this->assertTrue(app(ReportDataValidator::class)->passes($this->snapshot()));
    }

    public function test_results_with_zero_spend_is_blocked(): void
    {
        $bad = $this->snapshot([
            'kpis' => ['spend' => 0, 'revenue' => 0, 'conversions' => 307, 'roas' => null, 'cpa' => null],
            'platforms' => [['provider' => 'meta', 'spend' => 0, 'revenue' => 0, 'conversions' => 307]],
        ]);
        $validator = app(ReportDataValidator::class);
        $this->assertFalse($validator->passes($bad));
        $codes = array_column($validator->validate($bad), 'code');
        $this->assertContains('results_without_spend', $codes);
    }

    public function test_summary_mismatch_is_blocked(): void
    {
        $bad = $this->snapshot(['platforms' => [['provider' => 'meta', 'spend' => 100, 'revenue' => 4000, 'conversions' => 50]]]);
        $validator = app(ReportDataValidator::class);
        $this->assertFalse($validator->passes($bad));
        $this->assertContains('summary_mismatch', array_column($validator->validate($bad), 'code'));
    }

    public function test_roas_definition_mismatch_is_blocked(): void
    {
        $bad = $this->snapshot(['kpis' => ['spend' => 1000, 'revenue' => 4000, 'conversions' => 50, 'roas' => 9.9, 'cpa' => 20.0]]);
        $this->assertContains('roas_mismatch', array_column(app(ReportDataValidator::class)->validate($bad), 'code'));
    }

    public function test_organic_source_allows_results_without_spend(): void
    {
        $ok = $this->snapshot([
            'results_source' => 'organic',
            'kpis' => ['spend' => 0, 'revenue' => 0, 'conversions' => 307, 'roas' => null, 'cpa' => null],
            'platforms' => [['provider' => 'meta', 'spend' => 0, 'revenue' => 0, 'conversions' => 307]],
        ]);
        $this->assertTrue(app(ReportDataValidator::class)->passes($ok));
    }

    public function test_exporter_refuses_to_render_invalid_report(): void
    {
        $report = new Report(['name' => 'R', 'type' => 'executive', 'currency' => 'SAR']);
        $report->status = 'completed';
        $report->setRawAttributes(array_merge($report->getAttributes(), [
            'data' => json_encode($this->snapshot([
                'kpis' => ['spend' => 0, 'revenue' => 0, 'conversions' => 307, 'roas' => null, 'cpa' => null],
                'platforms' => [['provider' => 'meta', 'spend' => 0, 'revenue' => 0, 'conversions' => 307]],
            ])),
        ]));
        $report->syncOriginal();

        $this->expectException(HttpException::class);
        app(ReportExporter::class)->render($report, 'csv');
    }

    public function test_checksum_is_deterministic_and_scoped(): void
    {
        $a = ExportReadinessGate::checksum($this->snapshot());
        $b = ExportReadinessGate::checksum($this->snapshot(['generated_at' => 'different']));
        $c = ExportReadinessGate::checksum($this->snapshot(['kpis' => ['spend' => 999]]));
        $this->assertSame($a, $b); // volatile metadata excluded
        $this->assertNotSame($a, $c); // metric change alters checksum
    }
}
