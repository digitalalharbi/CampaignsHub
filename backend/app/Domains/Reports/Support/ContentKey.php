<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\ReportShare;

/**
 * An opaque, share-bound handle for one piece of content on a client link.
 *
 * A client drills from a platform into its content, and that drilldown needs to name ONE creative.
 * The internal id cannot travel: CLIENT-REPORT-ENTITY-BOUNDARY-001 strips every primary key from the
 * payload because «the name was withheld and the primary key was not» is how an internal identity
 * leaks. So the payload carries a keyed hash of (share, creative) instead:
 *
 * - it names nothing outside this link — the same creative has a different key on another share,
 *   so a key copied from one client's link opens nothing on another's;
 * - it cannot be reversed or enumerated without the application key;
 * - resolving it re-runs the link's own ceiling (see `LiveReportService::content()`), so a key is a
 *   pointer and never a grant.
 */
final class ContentKey
{
    private const LENGTH = 24;

    public static function for(ReportShare $share, string $creativeId): string
    {
        return substr(hash_hmac('sha256', $share->getKey().'|'.$creativeId, (string) config('app.key')), 0, self::LENGTH);
    }

    /**
     * Put a key on every content row that still carries its id — ranked ads, groups of them at any
     * depth, and the roster. Run BEFORE `ClientEntityBoundary` removes the ids.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function attach(array $rows, ReportShare $share): array
    {
        return array_map(static function ($row) use ($share) {
            if (! is_array($row)) {
                return $row;
            }
            if (isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
                $row['content_key'] = self::for($share, $row['id']);
            }
            foreach (['ads', 'groups'] as $nested) {
                if (isset($row[$nested]) && is_array($row[$nested])) {
                    $row[$nested] = self::attach($row[$nested], $share);
                }
            }

            return $row;
        }, $rows);
    }

    public static function matches(string $candidate, ReportShare $share, string $creativeId): bool
    {
        return strlen($candidate) === self::LENGTH && hash_equals(self::for($share, $creativeId), $candidate);
    }
}
