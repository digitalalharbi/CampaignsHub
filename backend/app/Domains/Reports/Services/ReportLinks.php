<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkHistory;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use Illuminate\Support\Carbon;

/**
 * REPORT-LINK-SECTION-001 — the links a campaign actually sent people through.
 *
 * A short link is the one artefact in this product that leaves it. It is pasted into an ad, read
 * aloud, sent in a message — and until now a report could describe a month of spend without ever
 * mentioning the address the money pointed at. The clicks were counted the whole time and shown on
 * one page nobody opens while reading a report.
 *
 * ## Why this is a client-safe section
 *
 * {@see ClientEntityBoundary} draws its line at the agency's working notes — campaign names, ad-set
 * names, entity ids, the arrangement of the buy. A short link is the opposite of that: it is public
 * by construction, it was given to the client's own audience, and its destination is the client's
 * own address. Nothing here names a campaign, and nothing here is an internal id.
 *
 * ## What it refuses to do
 *
 * Follows are only counted where they were RECORDED, and recording began when `short_link_hops`
 * did — see SHORT-LINK-HOPS-001. A report whose window closes before that day has no link figures
 * at all, and says so, rather than printing a column of zeroes beside links that were plainly being
 * followed. The lifetime counter is carried separately and never summed into the window's figure.
 */
final class ReportLinks
{
    public function __construct(
        private readonly ShortLinkHistory $history,
        private readonly ShortLinkHops $hops,
    ) {}

    /**
     * The project's links and what they were measured doing inside the window.
     *
     * @return array{
     *     links: list<array<string, mixed>>,
     *     recording_since: ?string,
     *     absent_reason: ?string,
     * }
     */
    public function forWindow(string $projectId, Carbon $from, Carbon $to): array
    {
        $links = ShortLink::query()
            ->where('project_id', $projectId)
            ->orderByDesc('clicks')
            ->limit(50)
            ->get();

        if ($links->isEmpty()) {
            return ['links' => [], 'recording_since' => null, 'absent_reason' => 'no_short_link_in_this_scope'];
        }

        $since = $this->history->recordingSince();

        /*
         * The window closed before anything was written down.
         *
         * This is the state that most deserves a sentence rather than a table. The links exist, they
         * have real totals, and this period cannot be spoken for — so the section reports itself
         * absent with that reason instead of showing every link at zero follows, which a client
         * would read as «nobody clicked» over a month that may have been the best one.
         */
        if ($since === null || $since->greaterThan($to)) {
            return ['links' => [], 'recording_since' => $since?->toIso8601String(), 'absent_reason' => 'links_not_recorded_in_this_window'];
        }

        $recorded = $this->history->recordedTotals(
            $links->map(fn (ShortLink $l): string => (string) $l->getKey())->all(),
            $from,
            $to,
        );

        $rows = $links
            ->map(fn (ShortLink $l): array => [
                'slug' => $l->slug,
                'short_url' => $this->hops->shareUrl($l),
                /* What the link points at — the client's own address, never an internal reference. */
                'destination' => $l->source_value ?? $l->destination,
                'kind' => $l->kind,
                /* Measured INSIDE the window: the figure this report is entitled to speak about. */
                'follows' => $recorded[(string) $l->getKey()] ?? 0,
                /*
                 * The counter, across the link's whole life. Carried for context and never added to
                 * the window's figure — a report that summed the two would be counting a year of
                 * clicks into a month.
                 */
                'clicks_all_time' => (int) $l->clicks,
                'is_active' => (bool) $l->is_active,
            ])
            ->sortByDesc('follows')
            ->values()
            ->all();

        /*
         * Every link at zero inside a recorded window is a real and reportable answer — the period
         * WAS measured and nobody followed them. That is not the same as the absent state above,
         * and the section stays present to say it.
         */
        return [
            'links' => $rows,
            'recording_since' => $since->toIso8601String(),
            'absent_reason' => null,
        ];
    }
}
