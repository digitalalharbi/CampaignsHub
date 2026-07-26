<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Produces the CLIENT-facing view of a report snapshot from the internal snapshot. A client report is
 * not the campaign-manager dashboard: it drops operational/technical fields, shows only APPROVED
 * recommendations, and presents client-facing campaign names (internal tags like "(burner)" removed).
 *
 * The full internal snapshot is never mutated — this returns a filtered copy used by the shared link,
 * client PDF and client email. Audience 'internal'/'executive' keep the full data.
 */
final class ClientReportView
{
    /** Internal-only top-level keys that must never surface in a client report body. */
    private const INTERNAL_KEYS = ['checksum', 'data_version', 'tenant_id', 'project_id'];

    /** Internal name markers stripped from client-facing campaign/creative names. */
    private const INTERNAL_MARKERS = ['/\s*\((?:burner|test|copy|internal|draft|wip)\)/i', '/\s*[-–]\s*(?:v\d+|final|copy|test|draft)\b/i'];

    /**
     * @param  array<string,mixed>  $data  internal snapshot
     * @return array<string,mixed> client-facing snapshot
     */
    public function filter(array $data): array
    {
        $out = $data;
        $out['audience'] = 'client';

        // 1. Drop internal/technical top-level fields from the client body (they stay in PDF metadata).
        foreach (self::INTERNAL_KEYS as $k) {
            unset($out[$k]);
        }

        // 2. Only APPROVED recommendations reach the client; findings (observations) stay.
        $out['recommendations'] = array_values(array_filter(
            $data['recommendations'] ?? [],
            fn ($r) => ($r['status'] ?? 'draft') === 'approved',
        ));

        // 3. Client-facing names on every list that carries a campaign/creative name.
        foreach (['campaigns', 'top_creatives'] as $key) {
            if (! empty($out[$key]) && is_array($out[$key])) {
                $out[$key] = array_map(function ($row) {
                    if (isset($row['campaign_name'])) {
                        $row['campaign_name'] = self::clientName((string) $row['campaign_name']);
                    }

                    return $row;
                }, $out[$key]);
            }
        }
        if (isset($out['best']['campaign'])) {
            $out['best']['campaign'] = self::clientName((string) $out['best']['campaign']);
        }
        // Sanitise campaign names referenced inside findings/recommendations text too.
        foreach (['findings', 'recommendations'] as $key) {
            if (! empty($out[$key])) {
                $out[$key] = array_map(function ($n) {
                    foreach (['title', 'detail'] as $f) {
                        if (isset($n[$f])) {
                            $n[$f] = self::clientName((string) $n[$f]);
                        }
                    }

                    return $n;
                }, $out[$key]);
            }
        }

        // Executive summary lines and platform-notes may reference internal campaign names.
        if (! empty($out['summary'])) {
            $out['summary'] = array_map(fn ($line) => self::clientName((string) $line), $out['summary']);
        }
        if (! empty($out['platform_notes']) && is_array($out['platform_notes'])) {
            $out['platform_notes'] = array_map(function ($note) {
                foreach (['strengths', 'weaknesses'] as $g) {
                    if (! empty($note[$g])) {
                        $note[$g] = array_map(fn ($t) => self::clientName((string) $t), $note[$g]);
                    }
                }

                return $note;
            }, $out['platform_notes']);
        }

        return $out;
    }

    /** Strip internal markers from a name for client display. */
    public static function clientName(string $name): string
    {
        $clean = $name;
        foreach (self::INTERNAL_MARKERS as $pattern) {
            $clean = (string) preg_replace($pattern, '', $clean);
        }

        return trim($clean) ?: $name;
    }
}
