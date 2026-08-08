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
        //    Resolution order: explicit client_display_name → sanitised internal name → safe generated.
        foreach (['campaigns', 'top_creatives', 'budget'] as $key) {
            if (! empty($out[$key]) && is_array($out[$key])) {
                $out[$key] = array_map(function ($row) {
                    if (! empty($row['client_display_name'])) {
                        $row['campaign_name'] = (string) $row['client_display_name'];
                    } elseif (isset($row['campaign_name'])) {
                        $row['campaign_name'] = self::clientName((string) $row['campaign_name']);
                    }
                    unset($row['client_display_name']); // internal field — never expose the mapping
                    unset($row['campaign_id']);         // internal id — never expose to a client
                    unset($row['external_account_id']);

                    return $row;
                }, $out[$key]);
            }
        }
        if (isset($out['best']['campaign'])) {
            $out['best']['campaign'] = self::clientName((string) $out['best']['campaign']);
        }
        /*
         * §14.7's observations name campaigns in prose, so they need the same treatment.
         *
         * «حملة «Meta — Lead Gen (burner)» تستهلك الميزانية أسرع من الخطة» would otherwise put an
         * internal marker in front of a client, in the section they are most likely to read.
         */
        if (! empty($out['observations'])) {
            $out['observations'] = array_map(function ($note) {
                foreach (['title', 'detail'] as $f) {
                    if (isset($note[$f])) {
                        $note[$f] = self::clientName((string) $note[$f]);
                    }
                }
                if (! empty($note['scope']['name'])) {
                    $note['scope']['name'] = self::clientName((string) $note['scope']['name']);
                }

                return $note;
            }, $out['observations']);
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

        // Next-steps action/reason may reference internal campaign names.
        if (! empty($out['next_steps'])) {
            $out['next_steps'] = array_map(function ($step) {
                foreach (['action', 'reason'] as $f) {
                    if (isset($step[$f])) {
                        $step[$f] = self::clientName((string) $step[$f]);
                    }
                }

                return $step;
            }, $out['next_steps']);
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

    /** Executive report is lean (5–7 pages): summary + comparison + budget + next steps, no per-platform detail. */
    private const EXECUTIVE_SLIDE_TYPES = [
        'cover', 'executive_summary',
        /*
         * Kept in the SHORT form deliberately (REPORT-OBJECTIVE-004).
         *
         * A summary is where a blended cost per order does the most damage: it is the version that
         * gets forwarded and quoted, with no per-platform pages behind it to argue with. Trimming
         * the one section that says which figure is which would leave the shortest, most-read
         * document as the least qualified one.
         */
        'objective_performance',
        /*
         * The notes stay in the SHORT form too (§14.7).
         *
         * They are the most decision-shaped thing in the deck — a budget running 67% ahead of plan
         * is exactly what an executive summary is for — and unlike the per-platform pages they are
         * already the compressed version.
         */
        'observations',
        'platform_comparison', 'budget', 'next_steps',
    ];

    /**
     * Executive view = the client filter PLUS a trimmed slide set (drop per-platform detail, funnel,
     * standalone recommendations). Keeps the report to a handful of decision-focused pages.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function executive(array $data): array
    {
        $out = $this->filter($data);
        $out['audience'] = 'executive';
        if (! empty($out['slides']) && is_array($out['slides'])) {
            $out['slides'] = array_values(array_filter(
                $out['slides'],
                fn ($s) => in_array($s['type'] ?? '', self::EXECUTIVE_SLIDE_TYPES, true),
            ));
        }

        return $out;
    }

    /** Names still containing internal tokens after cleaning fall back to a generic safe label. */
    private const STILL_INTERNAL = '/\b(?:burner|test|tmp|copy|internal|draft|wip)\b/i';

    /** Strip internal markers from a name for client display; fall back to generic when unsalvageable. */
    public static function clientName(string $name): string
    {
        $clean = $name;
        foreach (self::INTERNAL_MARKERS as $pattern) {
            $clean = (string) preg_replace($pattern, '', $clean);
        }
        $clean = trim($clean);

        // If a name still carries an internal token (regex can't always sanitise), use a safe generic
        // label derived from the platform where possible (e.g. "حملة — Meta").
        if ($clean === '' || preg_match(self::STILL_INTERNAL, $clean)) {
            if (preg_match('/\b(snapchat|tiktok|meta|google|linkedin|x|microsoft|pinterest)\b/i', $name, $m)) {
                return 'حملة — '.ucfirst(strtolower($m[1]));
            }

            return 'حملة إعلانية';
        }

        return $clean;
    }
}
