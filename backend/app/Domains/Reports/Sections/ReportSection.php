<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * A report section built ONCE on the server and carried unchanged by every surface.
 *
 * REPORT-OBJECTIVE-ANALYTICS-001 — a thin seam, deliberately. The `report-section-controls` lane is
 * building the section registry (operator enable/disable, provider/objective support, one ordered
 * section model for Live, shared link, snapshot and PDF). Until it lands, a section exposes the two
 * things any registry needs from it — its payload key and how to build it — and the live and
 * generated paths call it directly. When the registry arrives it registers these instead of the call
 * sites doing so; the payload shape does not change.
 *
 * `build()` returns null when the section has nothing honest to show, so a renderer draws no card
 * rather than an empty one.
 */
interface ReportSection
{
    /** The payload key the section travels under, identical on every surface. */
    public function key(): string;

    /** @return array<string,mixed>|null */
    public function build(ReportSectionInput $input): ?array;
}
