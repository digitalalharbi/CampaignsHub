<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Support\GenericNames;
use App\Domains\Reports\Support\ReportColumns;
use App\Domains\Reports\Support\ReportReadMemo;

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
     * REPORT-PARITY-FOUR-SURFACES-001 — the operator's PREVIEW of a report (no link), through this same resolver.
     *
     * The builder's section list and the page the operator reads each made their own decision: one over the unnarrowed settings
     * and the raw internal snapshot, the other over nothing at all — so a section the client's link, PDF and file did not carry
     * was listed (and drawn) on the preview. This asks what a link of the report as it stands would carry: the same settings
     * (narrowed by the report's main sections), judged against the same client-facing document the link is built from.
     */
    public function resolveReport(Report $report): ResolvedSections
    {
        $data = is_array($report->data) && $report->data !== [] ? $report->data : null;

        return $this->resolve($report, null, 'preview', $data === null ? null : $this->documentOf($report, $data));
    }

    /**
     * The operator's PREVIEW body: the document the client's link is built from, with this resolver's sections applied.
     *
     * Hidden means absent here too — the renderer (`InteractiveReport`) decides most of what to draw from which keys are present,
     * so listing the section set without removing the keys would still draw them. The operator's pickers (link builder) no longer
     * read the roster out of this body; the controller serves them separately as `scope_options`.
     *
     * @param  array<string, mixed>  $data  the stored snapshot
     * @return array<string, mixed>
     */
    public function previewPayload(Report $report, array $data): array
    {
        return $this->apply($this->documentOf($report, $data), $report, null, 'preview', (string) ($report->audience ?? 'client'));
    }

    /**
     * The client-facing document of a stored snapshot — the one step the link, the PDF, the file and the preview share before
     * sections are resolved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function documentOf(Report $report, array $data): array
    {
        return app(ClientReportView::class)->document($data, (string) ($report->audience ?? 'client'), $report->form);
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

        $payload = $context->isClientFacing() ? self::withoutBuyingMethodology($payload) : $payload;

        // REPORT-AUTO-COMPOSE-001 — only where somebody picked automatic mode; a stored document's own record is not rewritten.
        $picked = ReportAutoComposer::chosenMetrics($report, $share);
        if ($picked !== null && array_key_exists('metric_set', $payload)) {
            $payload['metric_set'] = $picked;
        }

        // REPORT-GENERIC-LABELS-001 — last, on the one call every surface makes: a hidden name leaves every key and sentence.
        return GenericNames::for($report, $share)->apply($payload);
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
        $report = ReportReadMemo::remember('report-find|'.$share->report_id, fn (): ?Report => Report::withoutGlobalScopes()->find($share->report_id));

        return $report !== null && $this->operatorAllows($section, $report, $share);
    }
}
