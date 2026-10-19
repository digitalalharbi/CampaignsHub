<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\ReportShare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REPORT-LINK-ANALYTICS-001 — how often a client link has been opened, for the OWNER's eyes only.
 *
 * ## What counts as a view
 *
 * An opening that a person did: the access log's `view` rows, minus link-preview fetches. WhatsApp, Telegram,
 * Slack, X, Facebook, LinkedIn and search crawlers request a link to draw its card the moment it is pasted;
 * counting them told an Owner their client had read a report that nobody had opened.
 *
 * ## Privacy-safe unique views
 *
 * «Unique» is the number of DISTINCT browsers, and it is computed from a one-way hash of address and
 * browser string, salted with the link's own id — so the same person on two links is two people to this
 * code, nobody's address is stored anywhere new, and none of it leaves this method: the response carries
 * counts and dates only.
 *
 * ## Never shown to the client
 *
 * The endpoint that serves this is the agency's (`reports.share`). The public report payload never carries
 * it, and a test holds that line.
 */
final class ShareAnalytics
{
    /** Substrings that identify an automated fetch of the link rather than a person opening it. */
    private const BOT_MARKERS = ['bot', 'crawl', 'spider', 'preview', 'whatsapp', 'facebookexternalhit', 'slack', 'telegram', 'linkedinbot', 'embedly', 'curl', 'wget'];

    /** @return array{views: int, unique_views: int, first_viewed_at: ?string, last_viewed_at: ?string, downloads: int} */
    public function for(ReportShare $share): array
    {
        $views = DB::table('report_share_access_logs')->where('share_id', $share->getKey())->where('action', 'view');

        foreach (self::BOT_MARKERS as $marker) {
            $views->whereRaw("lower(coalesce(user_agent, '')) not like ?", ['%'.$marker.'%']);
        }

        $row = (clone $views)->selectRaw(
            "count(*) as views, count(distinct md5(coalesce(ip, '') || '|' || coalesce(user_agent, '') || '|' || ?)) as unique_views, min(created_at) as first_at, max(created_at) as last_at",
            [(string) $share->getKey()],
        )->first();

        $downloads = DB::table('report_share_access_logs')->where('share_id', $share->getKey())->where('action', 'download')->count();

        return [
            'views' => (int) ($row->views ?? 0),
            'unique_views' => (int) ($row->unique_views ?? 0),
            'first_viewed_at' => $row?->first_at === null ? null : Carbon::parse((string) $row->first_at)->toIso8601String(),
            'last_viewed_at' => $row?->last_at === null ? null : Carbon::parse((string) $row->last_at)->toIso8601String(),
            'downloads' => $downloads,
        ];
    }
}
