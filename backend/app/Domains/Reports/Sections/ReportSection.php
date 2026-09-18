<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * One section of a client report, as the registry defines it — REPORT-SECTION-MODEL-001.
 *
 * A definition says three things and nothing else:
 *
 *  - what the operator calls it (the builder's label, in both languages);
 *  - which payload keys it owns, under either of the names the two payload shapes use (a snapshot
 *    says `kpis`, a live link says `totals`) — so hiding the section can remove its data rather than
 *    blank it on the page;
 *  - whether it is on for a client-facing report when nobody has chosen.
 *
 * Whether it is SUPPORTED or AVAILABLE is not a property of the definition; those are predicates the
 * registry holds, because other lanes add them without editing this list.
 */
final class ReportSection
{
    /**
     * @param  list<string>  $payloadKeys  every key this section draws from, in either payload shape
     */
    public function __construct(
        public readonly string $key,
        public readonly string $titleAr,
        public readonly string $titleEn,
        public readonly array $payloadKeys,
        public readonly bool $clientDefault = true,
        public readonly bool $internalDefault = true,
        /** A breakdown is optional depth under the main report, never part of its headline. */
        public readonly bool $breakdown = false,
    ) {}

    public function defaultFor(string $audience): bool
    {
        return $audience === 'internal' ? $this->internalDefault : $this->clientDefault;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title_ar' => $this->titleAr,
            'title_en' => $this->titleEn,
            'breakdown' => $this->breakdown,
            'default_client' => $this->clientDefault,
            'default_internal' => $this->internalDefault,
        ];
    }
}
