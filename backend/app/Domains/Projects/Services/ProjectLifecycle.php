<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

/**
 * PROJECT-LIFECYCLE-DERIVED-001 (Owner 2026-11-06) — what a project's card says about where it is.
 *
 * `projects.status` and `projects.setup_completion` are written once, at creation (`draft`, 0), and no part
 * of the product ever moved them. So a project with bound, syncing sources and hundreds of campaigns read
 * «مسودة», «الإعداد 0%», and the list's header counted «نشطة 0».
 *
 * Both are derived here, on read, from the same per-project facts the card already shows
 * ({@see ProjectListSummary}); the stored columns are left exactly as they are.
 *
 * ## Status
 *
 * Only the two states nobody chose are derived. `draft` and `onboarding` are where a project starts, not a
 * decision: a project whose linked source has delivered data is `active`, one with a linked source that has
 * not delivered yet is `onboarding`. `active`, `paused`, `completed` and `archived` are somebody's decision
 * and are returned untouched — data arriving never un-pauses a project.
 *
 * ## Setup
 *
 * Three facts, each a thing that either happened or did not: a source is linked, its data has arrived,
 * campaigns were found. A number an operator typed in is kept when it is higher.
 */
final class ProjectLifecycle
{
    private const STARTING = ['draft', 'onboarding'];

    /** @param  array<string,mixed>|null  $summary */
    public static function status(string $stored, ?array $summary): string
    {
        if ($summary === null || ! in_array($stored, self::STARTING, true)) {
            return $stored;
        }

        if ((int) ($summary['accounts'] ?? 0) === 0) {
            return $stored;
        }

        return ($summary['data_last_synced_at'] ?? null) !== null ? 'active' : 'onboarding';
    }

    /** @param  array<string,mixed>|null  $summary */
    public static function setupCompletion(int $stored, ?array $summary): int
    {
        if ($summary === null) {
            return $stored;
        }

        $linked = (int) ($summary['accounts'] ?? 0) > 0;
        $done = (int) $linked
            + (int) ($linked && ($summary['data_last_synced_at'] ?? null) !== null)
            + (int) ($linked && (int) ($summary['campaigns'] ?? 0) > 0);

        return max($stored, (int) round($done * 100 / 3));
    }
}
