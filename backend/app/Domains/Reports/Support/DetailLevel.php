<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * REPORT-DETAIL-LEVEL-001 — how deep a report goes: «مختصر», «قياسي», «تفصيلي».
 *
 * Three levels the Owner chooses in report setup, each of which materially changes what the report
 * contains — a level that only relabelled the same document would be a placebo control.
 *
 *  - summary:  the primary KPIs, the trend, the platform comparison, the best content and a short
 *              reading. Nothing a reader has to scroll past to reach the answer.
 *  - standard: the summary plus the performance by objective and the campaign-level breakdown.
 *  - detailed: the standard report plus per-platform depth — the conversion funnel, budget pacing and
 *              the deeper segmentation — and the expanded content sections.
 *
 * It is stored beside the report's `form` (summary → `executive_summary`, the others → `detailed`),
 * because the form is what every renderer and export already understands; the level adds the middle
 * rung and decides which sections start switched on. The Owner's own section toggles still win.
 */
enum DetailLevel: string
{
    case Summary = 'summary';
    case Standard = 'standard';
    case Detailed = 'detailed';

    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** The two-valued form every existing renderer reads. */
    public function form(): string
    {
        return $this === self::Summary ? 'executive_summary' : 'detailed';
    }

    /**
     * The section keys this level switches ON or OFF at creation (ReportSectionRegistry keys).
     * Only keys the level has an opinion about are listed; every other section keeps its own default.
     *
     * @return array<string, bool>
     */
    public function sections(): array
    {
        $always = ['kpis' => true, 'trends' => true, 'platform_comparison' => true, 'content_performance' => true, 'recommendations' => true];

        return match ($this) {
            self::Summary => $always + [
                'objective_breakdown' => false,
                'detailed_tables' => false,
                'funnel' => false,
                'budget_pacing' => false,
                'advanced_segmentation' => false,
            ],
            self::Standard => $always + [
                'objective_breakdown' => true,
                'detailed_tables' => true,
                'funnel' => false,
                'budget_pacing' => false,
                'advanced_segmentation' => false,
            ],
            self::Detailed => $always + [
                'objective_breakdown' => true,
                'detailed_tables' => true,
                'funnel' => true,
                'budget_pacing' => true,
                'advanced_segmentation' => true,
            ],
        };
    }

    /** @return list<string> the section keys this level leaves OUT — the shape a link's overrides take. */
    public function offSections(): array
    {
        return array_keys(array_filter($this->sections(), static fn (bool $on): bool => $on === false));
    }

    /** @return array{ar:string,en:string} */
    public function label(): array
    {
        return match ($this) {
            self::Summary => ['ar' => 'مختصر', 'en' => 'Summary'],
            self::Standard => ['ar' => 'قياسي', 'en' => 'Standard'],
            self::Detailed => ['ar' => 'تفصيلي', 'en' => 'Detailed'],
        };
    }
}
