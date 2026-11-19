<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Analysis;

use App\Domains\ContentIntake\Models\ContentIntakeItem;

/**
 * CONTENT-INTAKE-001 — the creative-analysis adapter. One interface, two implementations: the deterministic
 * analyzer (always on, facts only) and the AI-provider analyzer (feature-flagged, AWAITING_CREDENTIALS).
 * An analyzer never invents: a field it cannot fill stays null.
 */
interface CreativeAnalyzer
{
    public function key(): string;

    /** `active` | `awaiting_credentials` | `disabled` | `not_wired` */
    public function status(): string;

    /** @return array<string, mixed> */
    public function analyze(ContentIntakeItem $item): array;
}
