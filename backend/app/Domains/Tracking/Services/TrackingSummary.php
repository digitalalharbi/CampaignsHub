<?php

declare(strict_types=1);

namespace App\Domains\Tracking\Services;

use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tracking\Models\TrackingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TRACKING-SUMMARY-001 — what a project's recorded link activity adds up to, and exactly how far that goes.
 *
 * Every figure is a count of RECORDED EVENTS, taken from the day advanced tracking was switched on. It says so
 * (`tracking_since`), because a project that switched it on yesterday has a very different history from one that
 * has had it for a year, and a chart that starts on the activation day must not be read as «nothing happened
 * before». An event is an open of a link, never a sale and never a person:
 *
 *  - machines (link previews, crawlers) are counted apart and are not in `opens`;
 *  - devices are estimated per DAY only (the hash cannot follow a device across days), so no period total of
 *    «unique visitors» exists here — it would be a number the data cannot support;
 *  - a link click is not a purchase. Outcomes belong to the attribution views, which need a verified order.
 */
final class TrackingSummary
{
    private const EVENT_TYPES = [TrackingEvent::SHORT_LINK_REDIRECT, TrackingEvent::SHORT_LINK_OPEN, TrackingEvent::INFLUENCER_LINK_CLICK, TrackingEvent::CAMPAIGN_CLICK, TrackingEvent::WHATSAPP_CLICK, TrackingEvent::BIO_BUTTON_CLICK];

    public function __construct(private readonly ReportingTimezone $timezones) {}

    /**
     * @return array<string,mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $tz = $this->timezones->forProject($projectId);
        $start = $from->copy()->setTimezone($tz)->startOfDay();
        $end = $to->copy()->setTimezone($tz)->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $base = fn () => DB::table('tracking_events')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->whereIn('event_type', self::EVENT_TYPES)
            ->whereRaw('occurred_at at time zone ? >= ?', [$tz, $start->toDateTimeString()])
            ->whereRaw('occurred_at at time zone ? < ?', [$tz, $end->copy()->addDay()->toDateTimeString()]);

        $since = DB::table('tracking_events')->where('tenant_id', $tenantId)->where('project_id', $projectId)->min('occurred_at');

        $totals = (clone $base())->selectRaw('count(*) filter (where not is_bot) as opens, count(*) filter (where is_bot) as machine_opens')->first();
        $opens = (int) ($totals->opens ?? 0);
        $machines = (int) ($totals->machine_opens ?? 0);

        $days = (clone $base())
            ->where('is_bot', false)
            ->selectRaw("to_char(occurred_at at time zone ?, 'YYYY-MM-DD') as day, count(*) as opens, count(distinct coalesce(visitor_key, visitor_hash)) as devices", [$tz])
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');
        $series = [];
        for ($d = $start->copy(), $i = 0; $d->lte($end) && $i < 800; $d->addDay(), $i++) {
            $row = $days->get($d->toDateString());
            $series[] = ['period' => $d->toDateString(), 'opens' => (int) ($row->opens ?? 0), 'devices' => (int) ($row->devices ?? 0)];
        }

        $byType = (clone $base())->where('is_bot', false)->selectRaw('event_type, count(*) as opens')->groupBy('event_type')->pluck('opens', 'event_type');
        $byDestination = (clone $base())->where('is_bot', false)->selectRaw("coalesce(destination_type, 'unknown') as destination, count(*) as opens")->groupBy('destination')->orderByDesc('opens')->pluck('opens', 'destination');

        $links = (clone $base())->where('is_bot', false)->where('subject_type', 'short_link')
            ->selectRaw('subject_id, count(*) as opens, max(destination_host) as host')->groupBy('subject_id')->orderByDesc('opens')->limit(10)->get();
        $slugs = ShortLink::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $links->pluck('subject_id'))->pluck('slug', 'id');

        return [
            'state' => $since === null ? 'no_events' : 'measured',
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'timezone' => $tz],
            // The day the first event in this project was recorded: nothing before it was measured, which is not the same as nothing happening.
            'tracking_since' => $since === null ? null : Carbon::parse((string) $since)->setTimezone($tz)->toDateString(),
            'opens' => $opens,
            'machine_opens' => $machines,
            'series' => $series,
            'by_type' => array_map(static fn ($n): int => (int) $n, $byType->all()),
            'by_destination' => array_map(static fn ($n): int => (int) $n, $byDestination->all()),
            'top_links' => $links->map(static fn ($r): array => [
                'id' => (string) $r->subject_id,
                'slug' => $slugs[$r->subject_id] ?? null,
                'destination_host' => $r->host,
                'opens' => (int) $r->opens,
            ])->all(),
            // The limits of these numbers, carried with them so no surface has to remember to say them.
            'basis' => [
                'counts' => 'recorded_events',
                'unique_basis' => 'devices_per_day_estimate',
                'includes_sales' => false,
            ],
        ];
    }
}
