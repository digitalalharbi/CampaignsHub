<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * The outcome of one resolution: which sections are visible, and the reason for each hidden one.
 *
 * ## Hidden means absent from the payload
 *
 * `apply()` REMOVES the keys a hidden section owns rather than emptying them. An empty list still
 * tells a reader a section exists and has nothing in it, and a blanked block still travels in the
 * JSON. A key two sections share (`platforms` serves the comparison and the detailed tables) stays
 * while either of them is visible — the renderer decides what to draw from `report_sections`, never
 * from whether a key happens to be present.
 *
 * ## What a client sees of the decision
 *
 * The client payload carries the ordered list of visible section keys and nothing about the hidden
 * ones. The reasons are for the operator's preview: «disabled by operator» is not a sentence a
 * client's report should contain.
 */
final class ResolvedSections
{
    /** The key every surface's payload carries the visible set under. */
    public const PAYLOAD_KEY = 'report_sections';

    /**
     * `ReportStructure`'s outline entries, in section vocabulary. An outline entry of a hidden section
     * leaves the outline: the contents page must not list — or explain the absence of — a section the
     * report does not carry. The executive summary is the report itself and belongs to no section.
     */
    private const OUTLINE = [
        'performance' => 'kpis',
        'platforms' => 'platform_comparison',
        'objectives' => 'advanced_segmentation',
        'ads' => 'content_performance',
        'findings' => 'recommendations',
        'recommendations' => 'recommendations',
    ];

    /**
     * @param  array<string, array{section: ReportSection, visible: bool, reason: ?string, because: ?string}>  $entries
     */
    public function __construct(
        private readonly array $entries,
        public readonly bool $availabilityJudged,
    ) {}

    /** @return list<string> in reading order */
    public function visible(): array
    {
        return array_keys(array_filter($this->entries, static fn (array $e): bool => $e['visible']));
    }

    /** @return array<string, string> section key => reason */
    public function hidden(): array
    {
        $out = [];
        foreach ($this->entries as $key => $entry) {
            if (! $entry['visible']) {
                $out[$key] = (string) $entry['reason'];
            }
        }

        return $out;
    }

    public function isVisible(string $key): bool
    {
        return ($this->entries[$key]['visible'] ?? false) === true;
    }

    public function reasonFor(string $key): ?string
    {
        return $this->entries[$key]['reason'] ?? null;
    }

    /**
     * Remove every payload key no visible section claims, and state the visible set.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        $kept = [];
        foreach ($this->entries as $entry) {
            if ($entry['visible']) {
                foreach ($entry['section']->payloadKeys as $key) {
                    $kept[$key] = true;
                }
            }
        }

        foreach ($this->entries as $entry) {
            if ($entry['visible']) {
                continue;
            }
            foreach ($entry['section']->payloadKeys as $key) {
                if (! isset($kept[$key])) {
                    unset($payload[$key]);
                }
            }
        }

        /*
         * The snapshot deck lists its slides; a slide of a hidden section leaves the list, so a
         * renderer is never handed a slide whose data was just removed. A slide type no section
         * claims (the cover, data quality) is left alone.
         */
        if (is_array($payload['slides'] ?? null)) {
            $hiddenSlides = [];
            foreach ($this->entries as $entry) {
                if (! $entry['visible']) {
                    foreach ($entry['section']->slideTypes as $type) {
                        $hiddenSlides[$type] = true;
                    }
                }
            }
            $payload['slides'] = array_values(array_filter(
                $payload['slides'],
                static fn ($slide): bool => ! (is_array($slide) && isset($hiddenSlides[(string) ($slide['type'] ?? '')])),
            ));
        }

        if (is_array($payload['outline'] ?? null)) {
            $payload['outline'] = array_values(array_filter(
                $payload['outline'],
                fn ($entry): bool => ! (is_array($entry)
                    && isset(self::OUTLINE[(string) ($entry['key'] ?? '')])
                    && ! $this->isVisible(self::OUTLINE[(string) $entry['key']])),
            ));
        }

        $payload[self::PAYLOAD_KEY] = $this->visible();

        return $payload;
    }

    /**
     * The operator's view: every section, visible or not, with the reason and its title.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->entries as $key => $entry) {
            $out[] = [
                'key' => $key,
                'title_ar' => $entry['section']->titleAr,
                'title_en' => $entry['section']->titleEn,
                'breakdown' => $entry['section']->breakdown,
                'visible' => $entry['visible'],
                'reason' => $entry['reason'],
                'because' => $entry['because'],
            ];
        }

        return $out;
    }
}
