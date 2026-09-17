<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * The operator's saved section choices for one report or one template — REPORT-SECTION-MODEL-001.
 *
 * Stored SPARSE: only what somebody actually chose. A section nobody touched takes the audience's
 * default at read time, so a section added to the registry next month gets a sensible state on every
 * existing report instead of the state of whoever last saved it.
 *
 * Unknown keys are dropped when read. A stored choice for a section that no longer exists is not an
 * error, and it must never reach a renderer as a key it would try to draw.
 */
final class SectionSettings
{
    /** @param array<string, bool> $chosen */
    private function __construct(private readonly array $chosen) {}

    /** @param array<string, mixed>|null $raw  the stored JSON, `{"sections": {"kpis": true, …}}` */
    public static function fromArray(?array $raw, ReportSectionRegistry $registry): self
    {
        $sections = is_array($raw['sections'] ?? null) ? $raw['sections'] : [];
        $chosen = [];

        foreach ($registry->keys() as $key) {
            if (array_key_exists($key, $sections) && is_bool($sections[$key])) {
                $chosen[$key] = $sections[$key];
            }
        }

        return new self($chosen);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** The operator's choice for a section, or the audience default when nobody chose. */
    public function enabled(ReportSection $section, string $audience): bool
    {
        return $this->chosen[$section->key] ?? $section->defaultFor($audience);
    }

    /** Whether somebody chose this section explicitly (the builder marks defaults differently). */
    public function chose(string $key): bool
    {
        return array_key_exists($key, $this->chosen);
    }

    /**
     * Narrow these choices by another layer's: a section is on only if both say so.
     *
     * Used for the per-link flags that predate this model — a link an operator narrowed stays
     * narrowed. A conjunction, never an override: nothing downstream can switch ON what the report
     * switched off.
     *
     * @param  array<string, bool>  $off  section key => false for every section the other layer turned off
     */
    public function narrowedBy(array $off, ReportSectionRegistry $registry): self
    {
        $chosen = $this->chosen;

        foreach ($off as $key => $on) {
            if ($on === false && $registry->has($key)) {
                $chosen[$key] = false;
            }
        }

        return new self($chosen);
    }

    /** @return array{sections: array<string, bool>} */
    public function toArray(): array
    {
        return ['sections' => $this->chosen];
    }

    /**
     * Every section's effective on/off for an audience.
     *
     * @return array<string, bool>
     */
    public function effective(ReportSectionRegistry $registry, string $audience): array
    {
        $out = [];
        foreach ($registry->all() as $section) {
            $out[$section->key] = $this->enabled($section, $audience);
        }

        return $out;
    }
}
