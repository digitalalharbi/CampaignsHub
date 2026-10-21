<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use Illuminate\Database\Query\Builder;
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
    private const GRANULARITIES = ['day' => ['days' => 30, 'step' => 'P1D'], 'week' => ['days' => 84, 'step' => 'P7D'], 'month' => ['days' => 365, 'step' => 'P1M']];

    /**
     * @param  string  $granularity  day | week | month — the bucket the trend is drawn in
     * @return array<string,mixed>
     */
    public function for(ReportShare $share, ?Carbon $from = null, ?Carbon $to = null, string $granularity = 'day'): array
    {
        $views = DB::table('report_share_access_logs')->where('share_id', $share->getKey())->where('action', 'view');

        foreach (self::BOT_MARKERS as $marker) {
            $views->whereRaw("lower(coalesce(user_agent, '')) not like ?", ['%'.$marker.'%']);
        }

        $row = (clone $views)->selectRaw(
            "count(*) as views, count(distinct md5(coalesce(ip, '') || '|' || coalesce(user_agent, '') || '|' || ?)) as unique_views, min(created_at) as first_at, max(created_at) as last_at",
            [(string) $share->getKey()],
        )->first();

        $all = DB::table('report_share_access_logs')->where('share_id', $share->getKey())->where('action', 'view')->count();
        $trend = $this->trend($share, clone $views, $from, $to, $granularity);

        $downloads = DB::table('report_share_access_logs')->where('share_id', $share->getKey())->where('action', 'download')->count();

        return $this->state($share) + [
            'views' => (int) ($row->views ?? 0),
            'unique_views' => (int) ($row->unique_views ?? 0),
            'first_viewed_at' => $row?->first_at === null ? null : Carbon::parse((string) $row->first_at)->toIso8601String(),
            'last_viewed_at' => $row?->last_at === null ? null : Carbon::parse((string) $row->last_at)->toIso8601String(),
            'downloads' => $downloads,
            /*
             * REPORT-LINK-ANALYTICS-002 — what the totals above are made of, said plainly.
             *
             * `unique_views` counts distinct devices (address and browser together) — not people. A person on two
             * devices is two, and two people behind one office address and one browser are one. That is why the
             * label is «devices» and `unique_basis` travels with it.
             */
            'repeat_visits' => max(0, (int) ($row->views ?? 0) - (int) ($row->unique_views ?? 0)),
            'bot_opens' => max(0, $all - (int) ($row->views ?? 0)),
            'unique_basis' => 'device_estimate',
        ] + $trend + ['comparison' => $this->comparison($share, clone $views, $trend['period'])];
    }

    /**
     * REPORT-LINK-ANALYTICS-003 — the link's own state beside its figures, so «no opens this week» is read with
     * «the link has been paused since Tuesday» rather than as a client who lost interest. One of active, paused,
     * revoked or expired, with the moment it entered that state; paused and revoked are the Owner's own acts,
     * expired is the clock.
     *
     * @return array{state: string, state_since: ?string}
     */
    private function state(ReportShare $share): array
    {
        if ($share->revoked_at !== null) {
            return ['state' => 'revoked', 'state_since' => $share->revoked_at->toIso8601String()];
        }
        if ($share->paused_at !== null) {
            return ['state' => 'paused', 'state_since' => $share->paused_at->toIso8601String()];
        }
        if ($share->expires_at !== null && Carbon::now()->greaterThan($share->expires_at)) {
            return ['state' => 'expired', 'state_since' => $share->expires_at->toIso8601String()];
        }

        return ['state' => 'active', 'state_since' => null];
    }

    /**
     * REPORT-LINK-ANALYTICS-003 — the drawn window against the window of the same length just before it:
     * human opens, distinct devices and downloads in each, so a rise or a fall is a comparison of like with like.
     * Both windows are the report's own days; a link younger than two windows simply shows zeros for the earlier one.
     *
     * @param  Builder  $views  already limited to this link's human views
     * @param  array{from: string, to: string}  $period  the drawn window, as the trend reported it
     * @return array{current: array{from: string, to: string, views: int, devices: int, downloads: int}, previous: array{from: string, to: string, views: int, devices: int, downloads: int}}
     */
    private function comparison(ReportShare $share, $views, array $period): array
    {
        $tz = $this->timezoneOf($share);
        $start = Carbon::parse($period['from'], $tz)->startOfDay();
        $end = Carbon::parse($period['to'], $tz)->startOfDay();
        $days = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        $count = function (Carbon $from, Carbon $to) use ($share, $views, $tz): array {
            $window = fn ($q) => $q
                ->whereRaw('created_at at time zone ? >= ?', [$tz, $from->toDateTimeString()])
                ->whereRaw('created_at at time zone ? < ?', [$tz, $to->copy()->addDay()->toDateTimeString()]);
            $row = $window(clone $views)->selectRaw("count(*) as views, count(distinct md5(coalesce(ip, '') || '|' || coalesce(user_agent, ''))) as devices")->first();
            $downloads = $window(DB::table('report_share_access_logs')->where('share_id', $share->getKey())->where('action', 'download'))->count();

            return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'views' => (int) ($row->views ?? 0), 'devices' => (int) ($row->devices ?? 0), 'downloads' => $downloads];
        };

        return ['current' => $count($start, $end), 'previous' => $count($prevStart, $prevEnd)];
    }

    /** The report's own clock, the one its period is measured on; UTC when it names none or an unknown one. */
    private function timezoneOf(ReportShare $share): string
    {
        $tz = (string) (Report::withoutGlobalScopes()->whereKey($share->report_id)->value('timezone') ?: 'UTC');

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /**
     * Opens per day, week or month, with the empty periods present as zero so a chart is continuous.
     *
     * Bucketed in the REPORT's own time zone (the same clock its period is measured on), so an open at 23:30 local
     * time belongs to the day the client lived it in.
     *
     * @param  Builder  $views  already limited to this link's human views
     * @return array{granularity: string, period: array{from: string, to: string}, series: list<array{period: string, views: int, devices: int}>}
     */
    private function trend(ReportShare $share, $views, ?Carbon $from, ?Carbon $to, string $granularity): array
    {
        $granularity = array_key_exists($granularity, self::GRANULARITIES) ? $granularity : 'day';
        $tz = $this->timezoneOf($share);

        $end = ($to ?? Carbon::now($tz))->copy()->setTimezone($tz)->startOfDay();
        $start = ($from ?? $end->copy()->subDays(self::GRANULARITIES[$granularity]['days'] - 1))->copy()->setTimezone($tz)->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        // A bounded series: a span that would draw hundreds of bars is cut to the most recent ones.
        $start = $start->lt($end->copy()->subDays(731)) ? $end->copy()->subDays(731) : $start;

        $bucket = "to_char(date_trunc('{$granularity}', created_at at time zone ?), 'YYYY-MM-DD')";
        $rows = $views
            ->whereRaw('created_at at time zone ? >= ?', [$tz, $start->toDateTimeString()])
            ->whereRaw('created_at at time zone ? < ?', [$tz, $end->copy()->addDay()->toDateTimeString()])
            ->selectRaw("{$bucket} as bucket, count(*) as views, count(distinct md5(coalesce(ip, '') || '|' || coalesce(user_agent, ''))) as devices", [$tz])
            ->groupBy('bucket')->orderBy('bucket')->get()->keyBy('bucket');

        $series = [];
        $cursor = match ($granularity) {
            'week' => $start->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $start->copy()->startOfMonth(),
            default => $start->copy(),
        };
        $guard = 0;
        while ($cursor->lte($end) && $guard++ < 800) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $series[] = ['period' => $key, 'views' => (int) ($row->views ?? 0), 'devices' => (int) ($row->devices ?? 0)];
            $cursor = match ($granularity) {
                'week' => $cursor->copy()->addWeek(),
                'month' => $cursor->copy()->addMonthNoOverflow(),
                default => $cursor->copy()->addDay(),
            };
        }

        return ['granularity' => $granularity, 'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()], 'series' => $series];
    }
}
