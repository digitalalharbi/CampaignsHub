<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REPORT-VIEWS-001 — how often a project's shared report links were opened, across all of them.
 *
 * Read from the same access log as the per-link analytics and cut by the same definition of «machine»
 * ({@see ShareAnalytics::humanOnly}), so a project total can never disagree with the links it adds up. Agency-only: the
 * public payload carries none of it, and no address or user agent leaves this class.
 *
 * What the numbers are: opens of a link by something that did not announce itself as a machine. They are not people —
 * one person on two devices is two opens from two browsers, and a link forwarded to a colleague is opened by someone the
 * agency never named — so there is no «visitors» figure here, only opens, and the opens a window holds.
 */
final class ProjectReportViews
{
    public function __construct(private readonly ReportingTimezone $timezones) {}

    /** @return array<string,mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $tz = $this->timezones->forProject($projectId);
        $start = $from->copy()->setTimezone($tz)->startOfDay();
        $end = $to->copy()->setTimezone($tz)->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $base = fn () => DB::table('report_share_access_logs as l')
            ->join('report_shares as s', 's.id', '=', 'l.share_id')
            ->join('reports as r', 'r.id', '=', 's.report_id')
            ->where('s.tenant_id', $tenantId)->where('r.project_id', $projectId)->where('l.action', 'view');

        $window = fn ($q) => $q
            ->whereRaw('l.created_at at time zone ? >= ?', [$tz, $start->toDateTimeString()])
            ->whereRaw('l.created_at at time zone ? < ?', [$tz, $end->copy()->addDay()->toDateTimeString()]);

        $everViewed = (clone $base())->exists();
        $all = (int) $window(clone $base())->count();
        $human = (int) $window(ShareAnalytics::humanOnly($base()))->count();

        $days = $window(ShareAnalytics::humanOnly($base()))
            ->selectRaw("to_char(l.created_at at time zone ?, 'YYYY-MM-DD') as day, count(*) as opens", [$tz])
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');
        $series = [];
        for ($d = $start->copy(), $i = 0; $d->lte($end) && $i < 800; $d->addDay(), $i++) {
            $series[] = ['period' => $d->toDateString(), 'opens' => (int) ($days->get($d->toDateString())->opens ?? 0)];
        }

        $top = $window(ShareAnalytics::humanOnly($base()))
            ->selectRaw('r.id as report_id, r.name as report_name, count(*) as opens, max(l.created_at) as last_opened_at')
            ->groupBy('r.id', 'r.name')->orderByDesc('opens')->limit(5)->get();

        $last = ShareAnalytics::humanOnly($base())->max('l.created_at');

        return [
            'state' => $everViewed ? 'measured' : 'no_views',
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'timezone' => $tz],
            'opens' => $human,
            'machine_opens' => max(0, $all - $human),
            'last_opened_at' => $last === null ? null : Carbon::parse((string) $last)->toIso8601String(),
            'series' => $series,
            'top_reports' => $top->map(static fn ($r): array => [
                'id' => (string) $r->report_id,
                'name' => (string) $r->report_name,
                'opens' => (int) $r->opens,
                'last_opened_at' => Carbon::parse((string) $r->last_opened_at)->toIso8601String(),
            ])->all(),
            'basis' => ['counts' => 'opens_not_people', 'machines_excluded' => true],
        ];
    }
}
