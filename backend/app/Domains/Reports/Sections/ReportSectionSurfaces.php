<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\ReportColumns;
use App\Domains\Reports\Support\ReportMetricVisibility;

/**
 * The one call every surface makes to apply a report's sections — REPORT-SECTION-SURFACES-001.
 *
 * Live, the shared link, the print route behind the PDF and the file export all come through here,
 * with the report's saved settings, narrowed by the link's own flags when a link is involved. None of
 * them decides its own section set, which is what keeps them from disagreeing.
 */
final class ReportSectionSurfaces
{
    /**
     * The per-link display flags that predate the section model, in section vocabulary.
     *
     * A link an operator narrowed stays narrowed: each of these switched OFF on the link hides the
     * sections it names. `previous_comparison` is not here — it trims the deltas inside the KPIs and
     * is not a section of its own. `attribution` is a disclosure with its own endpoint.
     */
    public const SHARE_FLAGS = [
        'platform_comparison' => ['platform_comparison'],
        'objective_breakdown' => ['objective_breakdown', 'advanced_segmentation'],
        'creatives' => ['content_performance'],
        'budget' => ['budget_pacing'],
        'funnel_store' => ['funnel'],
    ];

    public function __construct(
        private readonly ReportSectionResolver $resolver,
        private readonly ReportSectionRegistry $registry,
    ) {}

    /**
     * The report's saved choices, narrowed by the link's flags when there is a link — and by the main
     * sections it offers: a component no enabled view draws is hidden like an unticked one
     * (REPORT-COMPOSITION-CONTROL-001), so a tab switched off takes its data with it.
     */
    public function settingsFor(Report $report, ?ReportShare $share = null): SectionSettings
    {
        $settings = $report->sectionSettings();

        $off = $share === null ? [] : array_keys($this->hiddenByLink($share));
        foreach (ReportViews::undrawn($this->viewsFor($report, $share), $this->registry->keys()) as $key) {
            $off[] = $key;
        }

        return $off === [] ? $settings : $settings->narrowedBy(array_fill_keys($off, false), $this->registry);
    }

    /**
     * REPORT-COMPOSITION-CONTROL-001 — the main sections this report (narrowed by the link) offers,
     * or null when it offers every one.
     *
     * Only the DETAILED product has main sections — a summary and a standard report are one
     * composition each, with no tabs — so for those the answer is always null and nothing narrows.
     *
     * @return list<string>|null
     */
    public function viewsFor(Report $report, ?ReportShare $share = null): ?array
    {
        if (! $this->offersViews($report, $share)) {
            return null;
        }

        return ReportViews::narrow($report->sectionSettings()->views(), $share?->views());
    }

    /** Whether this report (as this link sends it) is the multi-view detailed product. */
    public function offersViews(Report $report, ?ReportShare $share = null): bool
    {
        $form = $share !== null ? $share->formOr($report->form) : (string) ($report->form ?? 'detailed');
        $level = ((array) ($report->config ?? []))['detail_level'] ?? null;

        return ! ($form === 'executive_summary' || $level === 'summary' || $level === 'standard');
    }

    /**
     * The sections a link hides of its own accord: its older display flags and its section overrides,
     * read as one list.
     *
     * @return array<string, true>
     */
    public function hiddenByLink(ReportShare $share): array
    {
        $off = [];
        foreach ($share->sectionVisibility()->toArray() as $flag => $on) {
            if ($on === false) {
                foreach (self::SHARE_FLAGS[$flag] ?? [] as $section) {
                    $off[$section] = true;
                }
            }
        }
        foreach ($share->sectionOverrides() as $section) {
            if ($this->registry->has($section)) {
                $off[$section] = true;
            }
        }

        return $off;
    }

    /**
     * A shared link is client-facing whatever the report's own audience says: it is opened by the
     * client, and it already runs the client view over the data.
     */
    public function contextFor(Report $report, ?ReportShare $share, string $surface, ?string $audience = null, ?string $form = null): SectionContext
    {
        $audience ??= $share !== null ? 'client' : (string) ($report->audience ?? 'client');
        $form ??= $share !== null ? $share->formOr($report->form) : (string) ($report->form ?? 'detailed');

        $scope = (array) ($share !== null ? ($share->scope ?? []) : ($report->scope ?? []));

        return new SectionContext(
            audience: $audience,
            form: (string) $form,
            providers: array_values(array_filter((array) ($scope['providers'] ?? []), 'is_string')),
            objective: $report->campaign_objective,
            surface: $surface,
        );
    }

    public function resolve(Report $report, ?ReportShare $share, string $surface, ?array $payload, ?string $audience = null, ?string $form = null): ResolvedSections
    {
        return $this->resolver->resolve(
            $this->settingsFor($report, $share),
            $this->contextFor($report, $share, $surface, $audience, $form)->withPayload($payload),
        );
    }

    /**
     * Resolve against the payload and apply: hidden sections' keys leave, `report_sections` arrives.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, Report $report, ?ReportShare $share, string $surface, ?string $audience = null, ?string $form = null): array
    {
        $context = $this->contextFor($report, $share, $surface, $audience, $form);
        $payload = $this->resolve($report, $share, $surface, $payload, $audience, $form)->apply($payload);
        // The tabs a reader is offered, named by the server like the sections are.
        $payload['report_views'] = $this->viewsFor($report, $share) ?? ReportViews::VIEWS;
        // REPORT-TABLES-OWNER-COLUMNS-001 — the columns the link's Owner chose per table, on every surface
        // built from a link (page, print, file). Absent means every column.
        // IP-TRACE-001 — the link's opaque reference travels with every file built from it (CSV, XLSX manifest, print).
        if ($share !== null && $share->trace_mark !== null) {
            $payload['report_trace'] = $share->trace_mark;
        }
        // A saved report carries its own choice (`config['columns']`); a link's choice wins where it states one.
        $chosen = $share !== null ? ReportColumns::forShare($share)->toArray() : [];
        if ($chosen === []) {
            $chosen = ReportColumns::forReport($report)->toArray();
        }
        if ($chosen !== []) {
            $payload['report_columns'] = $chosen;
        }

        if (! $context->isClientFacing()) {
            return $payload;
        }

        // REPORT-KPI-VISIBILITY-001 — the metrics hidden on this document leave every client surface that is built here: the page, the live
        // payload, the print route and the files. Internal views (above) keep every authorised metric.
        return self::withoutBuyingMethodology(ReportMetricVisibility::redact($payload, ReportMetricVisibility::effective($report, $share)));
    }

    /**
     * REPORT-SECTION-STREAMS-001 — Direct against Blended never reaches a client, in any section.
     *
     * The split is the agency's buying methodology. On a client-facing document advanced
     * segmentation carries the operator's neutral business streams instead, so the split's blocks,
     * its slide and its outline entry leave the payload whatever the section switches say.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutBuyingMethodology(array $payload): array
    {
        unset($payload['objective_performance'], $payload['objective_performance_previous']);

        if (is_array($payload['slides'] ?? null)) {
            $payload['slides'] = array_values(array_filter(
                $payload['slides'],
                static fn ($slide): bool => ! (is_array($slide) && ($slide['type'] ?? null) === 'objective_performance'),
            ));
        }

        if (is_array($payload['outline'] ?? null)) {
            $payload['outline'] = array_values(array_filter(
                $payload['outline'],
                static fn ($entry): bool => ! (is_array($entry) && ($entry['key'] ?? null) === 'objectives'),
            ));
        }

        return $payload;
    }

    /** Whether the operator (report, then link) lets a section be shown at all — for endpoints that serve one section. */
    public function operatorAllows(string $section, Report $report, ?ReportShare $share): bool
    {
        return ! in_array(
            $this->resolve($report, $share, 'endpoint', null)->reasonFor($section),
            [ReportSectionResolver::DISABLED_BY_OPERATOR, ReportSectionResolver::UNSUPPORTED],
            true,
        );
    }

    /**
     * CLIENT-LIVE-LINK-SAFETY-001 — whether a link shows the section a legacy flag names, read from
     * BOTH places an operator can switch it off: the link's own flag and the section the live-link
     * builder unticked (`section_overrides`, resolved through the registry with the report's own
     * settings). The drill-down endpoints read only the first, so a section the owner hid in the
     * builder still opened in the platform drawer.
     */
    public function linkShows(string $flag, ReportShare $share): bool
    {
        if (! ($share->visibleSections()[$flag] ?? false)) {
            return false;
        }
        $section = self::SHARE_FLAGS[$flag][0] ?? null;
        if ($section === null) {
            return true;
        }
        $report = Report::withoutGlobalScopes()->find($share->report_id);

        return $report !== null && $this->operatorAllows($section, $report, $share);
    }
}
