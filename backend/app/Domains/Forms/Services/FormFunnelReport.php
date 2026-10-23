<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\CRM\Enums\LeadStage;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Domains\Tracking\Services\TrackingRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FORMS-DASHBOARDS-001 — what a form (or a link) produced, from the two sources that can each be trusted for their own half.
 *
 *  - The VISIT — views, starts, steps, attempts — is read from the event log. It exists only from the day the workspace
 *    switched advanced tracking on, and it says so (`tracking.since`); a project that never did gets `visit: null`, not a
 *    row of zeroes. Machines are left out. «Sessions» are page loads: the id lives in memory, so one person on two days is
 *    two sessions, and the report calls them sessions, never visitors.
 *  - The LEADS — submissions, people, contacted, qualified, appointment, won — are read from the stored submissions and the
 *    Leads Centre itself, so they are right whether or not tracking was ever on. A form can have leads and no visits.
 *
 * A rate is `null` when its denominator is absent or zero. «0%» is a claim that something was measured and nothing came of
 * it; a missing input is not that claim (the same rule the rest of the product follows).
 *
 * Cost per lead is NOT computed here: no spend is linked to a form, and dividing a campaign's spend by a form's leads would
 * be inventing the link. `cost` says so. Revenue is likewise not claimed — only the ESTIMATED value of leads marked won,
 * labelled as an estimate, per currency.
 */
final class FormFunnelReport
{
    public function __construct(private readonly ReportingTimezone $timezones, private readonly TrackingRecorder $recorder) {}

    /** @return array<string,mixed> */
    public function forForm(LeadForm $form, Carbon $from, Carbon $to): array
    {
        [$start, $end, $tz] = $this->window((string) $form->project_id, $from, $to);
        $tenant = (string) $form->tenant_id;

        $leadIds = DB::table('lead_form_submissions')->where('tenant_id', $tenant)->where('lead_form_id', $form->id)
            ->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)->whereNotNull('lead_id')->pluck('lead_id');

        return [
            'scope' => ['type' => 'form', 'id' => (string) $form->id, 'name' => $form->name],
            'period' => ['from' => $start->copy()->setTimezone($tz)->toDateString(), 'to' => $end->copy()->subDay()->setTimezone($tz)->toDateString(), 'timezone' => $tz],
            'visit' => $this->visit($tenant, (string) $form->project_id, 'lead_form', (string) $form->id, $start, $end),
        ] + $this->leads($tenant, $leadIds->all(), $this->submissionCount($tenant, (string) $form->id, $start, $end));
    }

    /**
     * A link's dashboard. A short link is not tied to a form, so the leads credited to it are the ones that ARRIVED CARRYING ITS TAG:
     * the same `utm_campaign` (and `utm_source` / `utm_medium` where the link states them), inside the project. That is a stated
     * rule about a stated tag, not an inference about who clicked what.
     *
     * @return array<string,mixed>
     */
    public function forLink(ShortLink $link, string $projectId, Carbon $from, Carbon $to): array
    {
        [$start, $end, $tz] = $this->window($projectId, $from, $to);
        $tenant = (string) $link->tenant_id;
        $utm = (array) ($link->utm ?? []);

        $clicks = DB::table('tracking_events')->where('tenant_id', $tenant)->where('project_id', $projectId)
            ->where('subject_type', 'short_link')->where('subject_id', $link->id)
            ->whereIn('event_type', [TrackingEvent::SHORT_LINK_REDIRECT, TrackingEvent::SHORT_LINK_OPEN])
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->selectRaw('count(*) filter (where not is_bot) as clicks, count(*) filter (where is_bot) as machine, count(distinct coalesce(visitor_key, visitor_hash)) filter (where not is_bot) as device_days')->first();
        $since = DB::table('tracking_events')->where('tenant_id', $tenant)->where('project_id', $projectId)->min('occurred_at');

        $leadIds = [];
        $tagged = isset($utm['utm_campaign']) && $utm['utm_campaign'] !== '';
        if ($tagged) {
            $q = DB::table('leads')->where('tenant_id', $tenant)->where('project_id', $projectId)->whereNull('deleted_at')
                ->where('received_at', '>=', $start)->where('received_at', '<', $end)->where('utm_campaign', $utm['utm_campaign']);
            foreach (['utm_source', 'utm_medium'] as $k) {
                if (isset($utm[$k]) && $utm[$k] !== '') {
                    $q->where($k, $utm[$k]);
                }
            }
            $leadIds = $q->pluck('id')->all();
        }

        $report = $this->leads($tenant, $leadIds, null);

        return [
            'scope' => ['type' => 'link', 'id' => (string) $link->id, 'name' => $link->slug],
            'period' => ['from' => $start->copy()->setTimezone($tz)->toDateString(), 'to' => $end->copy()->subDay()->setTimezone($tz)->toDateString(), 'timezone' => $tz],
            'tracking' => ['since' => $since === null ? null : Carbon::parse((string) $since)->setTimezone($tz)->toDateString()],
            'clicks' => $since === null ? null : ['clicks' => (int) ($clicks->clicks ?? 0), 'machine' => (int) ($clicks->machine ?? 0), 'device_days' => (int) ($clicks->device_days ?? 0)],
            // How the leads were credited, in words, so the number is never read as more than it is.
            'attribution_rule' => $tagged
                ? 'Leads in this project that arrived carrying this link\'s utm_campaign'.(isset($utm['utm_source']) ? ', utm_source' : '').(isset($utm['utm_medium']) ? ', utm_medium' : '').'.'
                : 'This link carries no utm_campaign, so no lead can be credited to it.',
            'credited' => $tagged,
            'click_to_lead_rate' => $tagged && $since !== null && (int) ($clicks->clicks ?? 0) > 0 ? round($report['leads']['people'] / (int) $clicks->clicks, 4) : null,
        ] + $report;
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} [start inclusive, end exclusive, timezone] */
    private function window(string $projectId, Carbon $from, Carbon $to): array
    {
        $tz = $this->timezones->forProject($projectId);
        $start = $from->copy()->setTimezone($tz)->startOfDay();
        $end = $to->copy()->setTimezone($tz)->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->copy()->utc(), $end->copy()->addDay()->utc(), $tz];
    }

    private function submissionCount(string $tenant, string $formId, Carbon $start, Carbon $end): int
    {
        return (int) DB::table('lead_form_submissions')->where('tenant_id', $tenant)->where('lead_form_id', $formId)
            ->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)->count();
    }

    /** @return array<string,mixed> `state` says whether the workspace ever recorded a visit for this project */
    private function visit(string $tenant, string $projectId, string $subjectType, string $subjectId, Carbon $start, Carbon $end): array
    {
        $since = DB::table('tracking_events')->where('tenant_id', $tenant)->where('project_id', $projectId)->min('occurred_at');
        if ($since === null) {
            return ['state' => $this->recorder->enabled($tenant, $projectId) ? 'no_events_yet' : 'tracking_off', 'since' => null];
        }

        $base = fn () => DB::table('tracking_events')->where('tenant_id', $tenant)->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('is_bot', false)->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end);
        $sessions = fn (string $type) => (int) (clone $base())->where('event_type', $type)->whereNotNull('session_id')->distinct()->count('session_id');

        $steps = (clone $base())->where('event_type', TrackingEvent::STEP_COMPLETED)->whereNotNull('session_id')->whereNotNull('step')
            ->selectRaw('step, count(distinct session_id) as n')->groupBy('step')->orderBy('step')->pluck('n', 'step')->map(fn ($n): int => (int) $n)->all();

        $views = $sessions(TrackingEvent::FORM_VIEW);
        $starts = $sessions(TrackingEvent::FORM_START);
        $attempts = $sessions(TrackingEvent::SUBMIT_ATTEMPT);

        // Drop-off: of the sessions that reached a step, how many finished it. Step 1 is reached by starting.
        $dropoff = [];
        $reached = $starts;
        foreach ($steps as $step => $done) {
            $dropoff[] = ['step' => (int) $step, 'reached' => $reached, 'completed' => $done, 'left' => max(0, $reached - $done)];
            $reached = $done;
        }

        return [
            'state' => 'measured',
            'since' => Carbon::parse((string) $since)->toDateString(),
            // Page loads, not people: the id is in memory, so a second visit is a second session.
            'unit' => 'session',
            'views' => $views,
            'starts' => $starts,
            'attempts' => $attempts,
            'steps' => $dropoff,
            'rates' => [
                'view_to_start' => $views > 0 ? round($starts / $views, 4) : null,
                'start_to_attempt' => $starts > 0 ? round($attempts / $starts, 4) : null,
            ],
        ];
    }

    /**
     * @param  list<string>  $leadIds
     * @return array<string,mixed>
     */
    private function leads(string $tenant, array $leadIds, ?int $submissions): array
    {
        $rows = $leadIds === [] ? collect() : DB::table('leads')->where('tenant_id', $tenant)->whereIn('id', $leadIds)->whereNull('deleted_at')
            ->get(['id', 'status', 'canonical_lead_id', 'first_contact_at', 'qualified_at', 'estimated_value', 'currency']);

        $people = $rows->whereNull('canonical_lead_id');
        $won = $rows->where('status', LeadStage::Won->value);
        $count = $rows->count();

        $rate = static fn (int $part, int $whole): ?float => $whole > 0 ? round($part / $whole, 4) : null;

        $contacted = $rows->whereNotNull('first_contact_at')->count();
        $qualified = $rows->whereNotNull('qualified_at')->count();
        $appointment = $rows->whereIn('status', [LeadStage::Appointment->value, LeadStage::Won->value])->count();

        return [
            'leads' => [
                'submissions' => $submissions,
                // «Received» and «people» are different figures: the same person twice is two submissions and one person.
                'received' => $count,
                'people' => $people->count(),
                'contacted' => $contacted,
                'qualified' => $qualified,
                'appointment' => $appointment,
                'won' => $won->count(),
            ],
            'rates' => [
                'lead_to_contacted' => $rate($contacted, $count),
                'lead_to_qualified' => $rate($qualified, $count),
                'lead_to_appointment' => $rate($appointment, $count),
                'lead_to_won' => $rate($won->count(), $count),
            ],
            // An ESTIMATE the team typed on the lead, not a booked revenue; per currency, never summed across them.
            'won_estimated_value' => $won->groupBy('currency')->map(fn ($g): float => round((float) $g->sum('estimated_value'), 2))->all(),
            'cost' => ['cost_per_lead' => null, 'reason' => 'No ad spend is linked to a form or a link, so a cost per lead would be an invented division.'],
            'stage_note' => 'Contacted and qualified are counted from the stamps the pipeline writes; appointment and won from where the lead stands now.',
        ];
    }
}
