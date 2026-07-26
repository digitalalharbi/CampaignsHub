<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;

/**
 * Single authority that decides whether a report may be exported. It runs the data validator over the
 * stored snapshot and (optionally) verifies the snapshot checksum. No format (PDF/XLSX/CSV/print) may
 * render until this passes — that is what prevents a broken/contradictory PDF from ever being produced.
 */
final class ExportReadinessGate
{
    public function __construct(private readonly ReportDataValidator $validator) {}

    /**
     * @return array{ready:bool, status:string, errors:list<array<string,mixed>>, warnings:list<array<string,mixed>>, checksum:?string}
     */
    public function evaluate(Report $report): array
    {
        $data = $report->data ?? [];
        $issues = $this->validator->validate($data);
        $errors = array_values(array_filter($issues, fn ($i) => $i['level'] === 'error'));
        $warnings = array_values(array_filter($issues, fn ($i) => $i['level'] === 'warning'));

        $ready = $report->status === 'completed' && $errors === [];

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : ($errors !== [] ? 'validation_failed' : 'not_completed'),
            'errors' => $errors,
            'warnings' => $warnings,
            'checksum' => $data['checksum'] ?? null,
        ];
    }

    /** Throw a 422 with structured details when export must be blocked. */
    public function ensureReady(Report $report): void
    {
        $result = $this->evaluate($report);
        if ($result['ready']) {
            return;
        }
        abort(422, 'Report failed validation and cannot be exported: '.
            implode('; ', array_map(fn ($e) => $e['message'], $result['errors']) ?: ['report is not completed']));
    }

    /** Deterministic checksum of the metric-bearing parts of a snapshot (excludes volatile metadata). */
    public static function checksum(array $data): string
    {
        $canonical = [
            'kpis' => $data['kpis'] ?? [],
            'platforms' => $data['platforms'] ?? [],
            'campaigns' => $data['campaigns'] ?? [],
            'timeseries' => $data['timeseries'] ?? [],
            'funnel' => $data['funnel'] ?? [],
            'budget' => $data['budget'] ?? [],
            'period' => $data['period'] ?? [],
            'currency' => $data['currency'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }
}
