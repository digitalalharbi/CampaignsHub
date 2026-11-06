<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

/**
 * Meta's `X-Business-Use-Case-Usage` header, read.
 *
 * Meta reports how close an app is to its Marketing API ceiling in this header — per business id, as
 * percentages of call count, CPU time and total time — rather than by answering 429 first. It is a
 * META header (not an X Ads one) and it carries no credential, so its figures may be recorded.
 */
final class MetaUsageHeader
{
    /**
     * Minutes until Meta stops throttling, from `estimated_time_to_regain_access`; 0 when none is stated.
     *
     * Meta's rate-limit page names the same payload `X-Business-Use-Case`, so the caller passes whichever arrived.
     */
    public static function regainMinutes(?string $header): int
    {
        $decoded = is_string($header) && $header !== '' ? json_decode($header, true) : null;
        $minutes = 0;

        foreach (is_array($decoded) ? $decoded : [] as $entries) {
            foreach ((array) $entries as $entry) {
                if (is_array($entry) && is_numeric($entry['estimated_time_to_regain_access'] ?? null)) {
                    $minutes = max($minutes, (int) $entry['estimated_time_to_regain_access']);
                }
            }
        }

        return $minutes;
    }

    /** @return array{present: bool, max_call_count: int|null, max_total_cputime: int|null, max_total_time: int|null} */
    public static function summarise(?string $header): array
    {
        $decoded = is_string($header) && $header !== '' ? json_decode($header, true) : null;

        if (! is_array($decoded)) {
            return ['present' => false, 'max_call_count' => null, 'max_total_cputime' => null, 'max_total_time' => null];
        }

        $max = ['call_count' => null, 'total_cputime' => null, 'total_time' => null];

        foreach ($decoded as $entries) {
            foreach ((array) $entries as $entry) {
                foreach (array_keys($max) as $key) {
                    if (is_array($entry) && is_numeric($entry[$key] ?? null)) {
                        $max[$key] = max((int) $max[$key], (int) $entry[$key]);
                    }
                }
            }
        }

        return [
            'present' => true,
            'max_call_count' => $max['call_count'],
            'max_total_cputime' => $max['total_cputime'],
            'max_total_time' => $max['total_time'],
        ];
    }
}
