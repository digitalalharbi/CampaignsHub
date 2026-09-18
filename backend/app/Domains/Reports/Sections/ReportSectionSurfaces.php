<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;

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

    /** The report's saved choices, narrowed by the link's flags when there is a link. */
    public function settingsFor(Report $report, ?ReportShare $share = null): SectionSettings
    {
        $settings = $report->sectionSettings();

        if ($share === null) {
            return $settings;
        }

        return $settings->narrowedBy(array_fill_keys(array_keys($this->hiddenByLink($share)), false), $this->registry);
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
        return $this->resolve($report, $share, $surface, $payload, $audience, $form)->apply($payload);
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
}
