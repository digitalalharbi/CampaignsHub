<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Produces the CLIENT-facing view of a report snapshot. A client report is not the campaign-manager
 * dashboard: it drops operational and technical fields, shows only APPROVED recommendations, and
 * carries no campaign-management entity at all.
 *
 * ## The rule changed here — CLIENT-REPORT-ENTITY-BOUNDARY-001
 *
 * This class used to SANITISE campaign names: strip «(burner)», fall back to «حملة — Meta» where the
 * regex could not save it, and hand the result to the client. That was the wrong shape of answer. A
 * campaign name is not made client-safe by removing the embarrassing part of it — the container
 * itself is the agency's, the client never chose it and does not manage it, and the owner said so
 * plainly: «اسم واختيار الحملة احذفه من التقارير».
 *
 * {@see ReportGenerator} no longer WRITES the roster, so a report generated today arrives here
 * already clean. This still removes it, and that is the point: every snapshot generated before today
 * is still in the database and still served through this class, on the shared link, the PDF, the
 * spreadsheet and the client email. A boundary that only holds for new documents is not a boundary.
 *
 * The name sanitiser survives for the ad and media labels that MAY reach a client — an internal
 * marker in «المحتوى الأعلى أداءً» is still an internal marker.
 *
 * The full internal snapshot is never mutated — this returns a filtered copy used by the shared link,
 * client PDF and client email.
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
        $out = self::withoutCampaignManagement($data);
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
        foreach (['ads'] as $key) {
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

    /**
     * Every campaign-management entity out of a snapshot, whenever it was written.
     *
     * Structural rather than textual: a name cannot be recognised by looking at it, so the KEYS that
     * carry identity are what go. Prose is handled where it is produced — the generator states the
     * finding as a sum and a platform now — and the remaining risk is an OLD snapshot whose stored
     * sentences quote a name. `ClientReportContentValidator` is what refuses to serve one of those.
     *
     * `budget` is dropped whole when its rows are the legacy per-campaign shape. An old snapshot
     * cannot be re-folded to platforms after the fact, and a pacing table of anonymous rows would be
     * a worse answer than no pacing table: the reader could see that something is overspending and
     * never what.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function withoutCampaignManagement(array $data): array
    {
        // The rosters, and the two lists that ranked campaigns under the word «creatives».
        foreach (['campaigns', 'ad_sets', 'top_creatives', 'worst_creatives'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = [];
            }
        }

        if (isset($data['best']['campaign'])) {
            $data['best']['campaign'] = null;
        }

        if (! empty($data['available']['campaigns'])) {
            $data['available']['campaigns'] = [];
        }

        foreach (['objective_performance', 'objective_performance_previous'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $data[$key] = ClientEntityBoundary::objectivePerformance($data[$key]);
            }
        }

        /*
         * Prose written down before this requirement, which quotes a campaign by name.
         *
         * An old snapshot's observations say «حملة «National Day Sale — Demo» تستهلك الميزانية أبطأ
         * من الخطة» — a real sentence, produced correctly under the old rule, and now a leak on a
         * shared link. It cannot be rewritten here: the figures behind it are per-campaign and this
         * class has no platform to re-attribute them to. So the line is dropped.
         *
         * Losing a finding is a real cost, and it is paid ONLY by documents already generated. A
         * report generated today states the same finding by platform and keeps it. Dropped by SCOPE
         * first, which is exact, and by the quoting pattern for the older entries that predate the
         * `scope` key — the pattern is the generator's own sentence shape, in both languages.
         */
        foreach (['observations', 'findings', 'recommendations', 'next_steps'] as $key) {
            if (! empty($data[$key]) && is_array($data[$key])) {
                $data[$key] = array_values(array_filter(
                    $data[$key],
                    fn ($entry) => ! self::namesACampaign($entry),
                ));
            }
        }

        if (! empty($data['summary']) && is_array($data['summary'])) {
            $data['summary'] = array_values(array_filter(
                $data['summary'],
                fn ($line) => ! self::quotesACampaign((string) $line),
            ));
        }

        // Legacy pacing rows are per-campaign and cannot be folded retroactively.
        if (! empty($data['budget']) && is_array($data['budget'])) {
            $legacy = array_filter(
                $data['budget'],
                fn ($row) => is_array($row) && (isset($row['campaign_id']) || isset($row['campaign_name'])),
            );
            if ($legacy !== []) {
                $data['budget'] = [];
            }
        }

        return $data;
    }

    /** Whether a stored finding, observation, recommendation or step is ABOUT a campaign. */
    private static function namesACampaign(mixed $entry): bool
    {
        if (! is_array($entry)) {
            return false;
        }

        if (($entry['scope']['type'] ?? null) === 'campaign') {
            return true;
        }

        foreach (['title', 'detail', 'action', 'reason'] as $field) {
            if (self::quotesACampaign((string) ($entry[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The generator's own sentence shape, in both languages: the word «campaign» followed by a quoted
     * name. Narrow on purpose — «تُدار الحملات وفق منهجية» is a sentence about campaigns in general
     * and names none, and dropping it would take the methodology note with it.
     */
    private static function quotesACampaign(string $text): bool
    {
        return preg_match('/حملة\s*[«"\x{201C}]/u', $text) === 1
            || preg_match('/\bcampaign\s*[«"\x{201C}]/iu', $text) === 1;
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
