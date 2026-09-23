<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;

/**
 * REPORT-DRILLDOWN-001 — the OPTIONAL breakdowns a report can open on request.
 *
 * A breakdown is not a section. A section is on the page by default and an operator switches it off
 * ({@see ShareSections}); a breakdown is deeper analysis that stays out of the default view and is
 * reached by opening something — a platform from the comparison, a piece of content from a list.
 * Keeping them in their own registry is what keeps the default dashboard simple: adding a breakdown
 * adds nothing to the page until a reader asks for it.
 *
 * Two facts per breakdown:
 *
 * - `requires` — the section it deepens. A breakdown can never be reachable where its parent section
 *   is off: a link that does not show the platform comparison has no platform to open, and knowing
 *   the drill-down's address must not bring the comparison back one level down.
 * - `surfaces` — the default per surface. Live offers both; the PDF carries neither unless the
 *   operator turns one on, because a document someone keeps should be the short one.
 *
 * SEAM — lane `report-section-controls` owns the section registry this belongs to. When it lands,
 * these entries move into it as `optional` breakdowns and this class becomes a thin read of that
 * registry; the keys and defaults below are the contract to keep.
 */
final class ReportBreakdowns
{
    public const PLATFORM = 'platform_drilldown';

    public const CONTENT = 'content_drilldown';

    public const SURFACES = ['live', 'pdf'];

    /** @var array<string, array{requires: string, surfaces: array<string, bool>}> */
    private const REGISTRY = [
        self::PLATFORM => ['requires' => 'platform_comparison', 'surfaces' => ['live' => true, 'pdf' => false]],
        self::CONTENT => ['requires' => 'creatives', 'surfaces' => ['live' => true, 'pdf' => false]],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function defaultFor(string $breakdown, string $surface): bool
    {
        return (bool) (self::REGISTRY[$breakdown]['surfaces'][$surface] ?? false);
    }

    public static function requires(string $breakdown): ?string
    {
        return self::REGISTRY[$breakdown]['requires'] ?? null;
    }

    /**
     * Which breakdowns this link may open on a surface.
     *
     * An executive-summary link defaults every breakdown OFF; only an explicit override opens one.
     *
     * The operator's override is read from `settings.breakdowns.<surface>.<key>` and may only be a
     * boolean; anything else falls back to the registry default. The parent section is checked last
     * and always wins — an override can switch a breakdown off, never switch a hidden section on.
     *
     * @return array<string, bool>
     */
    public static function forShare(ReportShare $share, string $surface = 'live'): array
    {
        $overrides = (array) ((((array) ($share->settings ?? []))['breakdowns'] ?? [])[$surface] ?? []);
        $sections = $share->visibleSections();
        /*
         * An executive summary is the short product the operator chose to send: it offers no
         * drill-down by default on any surface. The operator may enable one on that link, explicitly.
         */
        $summary = ReportComposition::for($share->formOr(self::reportForm($share)))->isSummary();

        $out = [];
        foreach (self::REGISTRY as $key => $entry) {
            $wanted = is_bool($overrides[$key] ?? null) ? $overrides[$key] : ($summary ? false : self::defaultFor($key, $surface));
            $out[$key] = $wanted && ($sections[$entry['requires']] ?? false);
        }

        return $out;
    }

    /** Whether the operator hid a section of this report — its slide is present and `visible: false`. */
    public static function reportSlideHidden(Report $report, string $type): bool
    {
        foreach ((array) (((array) ($report->config ?? []))['slides'] ?? []) as $slide) {
            if (is_array($slide) && ($slide['type'] ?? null) === $type && ($slide['visible'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The report's own form, read WITHOUT touching `$share->report`.
     *
     * This runs before the live builders enter the share's tenant and project, and loading the
     * relation here cached it as null under the project scope — the drill-down then read the
     * report's objective as «custom» and put a sales platform's share on results instead of revenue.
     */
    private static function reportForm(ReportShare $share): ?string
    {
        if ($share->relationLoaded('report')) {
            return $share->report?->form;
        }

        $form = Report::withoutGlobalScopes()->whereKey($share->report_id)->value('form');

        return is_string($form) ? $form : null;
    }

    /**
     * Which breakdowns a generated REPORT's document carries on a surface (the PDF).
     *
     * A document has no share: the operator's switch lives on the report, `config.breakdowns.<surface>`,
     * and the parent section is the report's own slide — a platform section the operator hid takes its
     * drill-down with it, and a hidden ads section takes the content lists.
     *
     * @return array<string, bool>
     */
    public static function forReport(Report $report, string $surface = 'pdf'): array
    {
        $config = (array) ($report->config ?? []);
        $overrides = (array) (((array) ($config['breakdowns'] ?? []))[$surface] ?? []);

        $parentSlide = [self::PLATFORM => 'platform_comparison', self::CONTENT => 'ads'];

        $out = [];
        foreach (self::REGISTRY as $key => $entry) {
            $wanted = is_bool($overrides[$key] ?? null) ? $overrides[$key] : self::defaultFor($key, $surface);
            $out[$key] = $wanted && ! self::reportSlideHidden($report, $parentSlide[$key]);
        }

        return $out;
    }

    public static function allows(ReportShare $share, string $breakdown, string $surface = 'live'): bool
    {
        return self::forShare($share, $surface)[$breakdown] ?? false;
    }
}
