<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\Delivery\Models\PlatformEvidence;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use Illuminate\Support\Collection;

/**
 * WD-EVIDENCE-001 — the platform screenshots a report's PDF carries as its appendix.
 *
 * The Owner attaches a screenshot while reviewing a delivery and decides, per screenshot, whether it goes
 * into the report (`include_in_report`). A report carries exactly those: of its own project (or attached
 * to its client with no project named), for a period that overlaps the report's. Never another client's,
 * and never a figure — a screenshot is shown to support trust, the structured provider data stays the
 * only source of every number.
 */
final class ReportEvidence
{
    /** Images print as appendix pages; a PDF screenshot is named, since it cannot be drawn on a page. */
    public const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @return Collection<int, PlatformEvidence> */
    public function forReport(Report $report): Collection
    {
        if ($report->project_id === null || $report->period_start === null || $report->period_end === null) {
            return collect();
        }
        $client = Project::withoutGlobalScopes()->whereKey($report->project_id)->value('client_workspace_id');

        return PlatformEvidence::withoutGlobalScopes()
            ->where('tenant_id', $report->tenant_id)
            ->where('include_in_report', true)
            ->where(fn ($q) => $q->where('project_id', $report->project_id)
                ->orWhere(fn ($q) => $q->whereNull('project_id')->where('client_workspace_id', $client)))
            ->whereDate('period_start', '<=', $report->period_end)
            ->whereDate('period_end', '>=', $report->period_start)
            ->orderBy('provider')->orderBy('captured_at')->orderBy('created_at')
            ->limit(24)
            ->get();
    }

    /**
     * The appendix entries for the print payload — metadata and a URL the renderer can fetch with its token.
     *
     * @param  callable(string): string  $urlFor
     * @return list<array<string, mixed>>
     */
    public function forPrint(Report $report, callable $urlFor): array
    {
        return $this->forReport($report)->map(static fn (PlatformEvidence $e): array => [
            'id' => (string) $e->getKey(),
            'provider' => $e->provider,
            'period_start' => $e->period_start?->toDateString(),
            'period_end' => $e->period_end?->toDateString(),
            'captured_at' => $e->captured_at?->toIso8601String(),
            'note' => $e->note,
            'name' => $e->original_name,
            'image' => in_array($e->mime, self::IMAGE_MIMES, true),
            'url' => in_array($e->mime, self::IMAGE_MIMES, true) ? $urlFor((string) $e->getKey()) : null,
        ])->values()->all();
    }
}
