<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Domains\Tracking\Services\TrackingRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TRACKING-HEALTH-001 — whether a project's lead tracking can be trusted, and exactly what to do where it cannot.
 *
 * Every check is a statement about something that was MEASURED, with the number behind it and the action it asks for. A check
 * that cannot be made says so (`unavailable`, with the reason) instead of passing: «nothing wrong» and «could not look» are
 * different answers, and a health page that conflates them is how a broken pixel stays broken for a quarter.
 *
 * What this does NOT do, deliberately: it never states an accuracy figure. Browser tracking, consent, ad blockers and platform
 * attribution windows all lose events legitimately, so «98% tracked» would be a number the data cannot support. It reports
 * COVERAGE (how many leads carry a campaign tag, a click id, a measurement consent) and DIFFERENCES (what a platform reports
 * against what the Leads Centre holds), each with its denominator, and leaves the reading to the person.
 *
 * Severities: `critical` something is broken now; `warning` something is losing data; `info` worth knowing; `ok` looked and fine;
 * `unavailable` could not be checked.
 */
final class TrackingHealth
{
    /** A form that has been viewed this often with no submission at all is treated as broken, not unpopular. */
    private const BROKEN_FORM_VIEWS = 20;

    /** Fewer leads than this and a coverage percentage is noise. */
    private const MIN_FOR_COVERAGE = 5;

    public function __construct(private readonly ReportingTimezone $timezones, private readonly TrackingRecorder $recorder) {}

    /** @return array<string,mixed> */
    public function forProject(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $tz = $this->timezones->forProject($projectId);
        $start = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $end = $to->copy()->setTimezone($tz)->startOfDay()->addDay()->utc();
        $trackingOn = $this->recorder->enabled($tenantId, $projectId);

        $forms = LeadForm::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('status', LeadForm::PUBLISHED)->get();
        $destinations = ConversionDestination::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->get();

        $checks = [];
        $this->formChecks($checks, $tenantId, $projectId, $forms, $start, $end, $trackingOn);
        $coverage = $this->coverage($checks, $tenantId, $projectId, $start, $end);
        $deliveries = $this->deliveryChecks($checks, $tenantId, $projectId, $forms, $destinations, $start, $end);
        $reconciliation = $this->reconcile($checks, $tenantId, $projectId, $start, $end, $tz);

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2, 'unavailable' => 3, 'ok' => 4];
        usort($checks, static fn (array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);

        return [
            'period' => ['from' => $start->copy()->setTimezone($tz)->toDateString(), 'to' => $end->copy()->subDay()->setTimezone($tz)->toDateString(), 'timezone' => $tz],
            'tracking_enabled' => $trackingOn,
            'summary' => [
                'critical' => count(array_filter($checks, static fn (array $c): bool => $c['severity'] === 'critical')),
                'warning' => count(array_filter($checks, static fn (array $c): bool => $c['severity'] === 'warning')),
            ],
            'checks' => $checks,
            'coverage' => $coverage,
            'deliveries' => $deliveries,
            'reconciliation' => $reconciliation,
            'confidence_note' => 'This page reports coverage and differences, each with its denominator. It never states an accuracy: consent, ad blockers, browsers and platform attribution windows lose events legitimately, so no honest percentage of «tracked» exists.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @param  Collection<int, LeadForm>  $forms
     */
    private function formChecks(array &$checks, string $tenant, string $project, $forms, Carbon $start, Carbon $end, bool $trackingOn): void
    {
        if ($forms->isEmpty()) {
            $checks[] = $this->check('no_published_forms', 'info', 'No published forms', 'Nothing in this project collects leads through a first-party form.', null, 'Publish a form to start collecting.');

            return;
        }

        foreach ($forms as $form) {
            $submissions = (int) DB::table('lead_form_submissions')->where('tenant_id', $tenant)->where('lead_form_id', $form->id)->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)->count();
            $views = $trackingOn
                ? (int) DB::table('tracking_events')->where('tenant_id', $tenant)->where('subject_type', 'lead_form')->where('subject_id', $form->id)->where('event_type', TrackingEvent::FORM_VIEW)->where('is_bot', false)
                    ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->whereNotNull('session_id')->distinct()->count('session_id')
                : null;

            if ($views === null) {
                $checks[] = $this->check('form_visits:'.$form->id, 'unavailable', "«{$form->name}»: visits cannot be checked", 'Advanced tracking is off for this project, so a form that is viewed and never sent cannot be told from one nobody opens.', ['submissions' => $submissions], 'Switch advanced tracking on for the project to see visits.');
            } elseif ($views >= self::BROKEN_FORM_VIEWS && $submissions === 0) {
                $checks[] = $this->check('form_broken:'.$form->id, 'critical', "«{$form->name}» is viewed and never sent", "{$views} sessions viewed it and none produced a submission. That is a broken form far more often than an unpopular one.", ['views' => $views, 'submissions' => 0], 'Open the form on a phone and send it. Check the consent box, a required field and the connection to the Leads Centre.');
            } elseif ($views === 0 && $submissions === 0) {
                $checks[] = $this->check('form_unseen:'.$form->id, 'info', "«{$form->name}» had no visits", 'Nothing was recorded for it in this period.', ['views' => 0], 'Check the ad or link that should lead to it.');
            } else {
                $checks[] = $this->check('form_ok:'.$form->id, 'ok', "«{$form->name}»: visits and submissions both present", null, ['views' => $views, 'submissions' => $submissions], null);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function coverage(array &$checks, string $tenant, string $project, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('lead_form_submissions as s')
            ->join('leads as l', 'l.id', '=', 's.lead_id')
            ->where('s.tenant_id', $tenant)->where('s.project_id', $project)->where('s.submitted_at', '>=', $start)->where('s.submitted_at', '<', $end)
            ->selectRaw("count(*) as n, count(*) filter (where l.utm_source is not null and l.utm_source <> '') as with_utm, count(*) filter (where l.click_id is not null) as with_click_id, count(*) filter (where s.measurement_consent = 'granted') as consented, count(*) filter (where s.measurement_consent = 'not_asked') as not_asked")
            ->first();
        $n = (int) ($rows->n ?? 0);
        $rate = static fn (int $part): ?float => $n >= self::MIN_FOR_COVERAGE ? round($part / $n, 4) : null;

        $coverage = [
            'form_leads' => $n,
            'with_campaign_tag' => $rate((int) ($rows->with_utm ?? 0)),
            'with_click_id' => $rate((int) ($rows->with_click_id ?? 0)),
            'measurement_consent_granted' => $rate((int) ($rows->consented ?? 0)),
            'minimum_for_a_rate' => self::MIN_FOR_COVERAGE,
        ];

        if ($n >= self::MIN_FOR_COVERAGE) {
            $untagged = $n - (int) ($rows->with_utm ?? 0);
            $checks[] = $untagged / $n > 0.5
                ? $this->check('missing_campaign_tag', 'warning', 'Most form leads carry no campaign tag', "{$untagged} of {$n} leads arrived without a utm_source, so they cannot be credited to a campaign or a link.", ['without_tag' => $untagged, 'of' => $n], 'Add utm parameters to the ad and link addresses that lead to the form.')
                : $this->check('campaign_tag_ok', 'ok', 'Most form leads carry a campaign tag', null, ['without_tag' => $untagged, 'of' => $n], null);
        } else {
            $checks[] = $this->check('coverage_thin', 'unavailable', 'Too few form leads to judge coverage', "Only {$n} in this period; a percentage over fewer than ".self::MIN_FOR_COVERAGE.' would be noise.', ['of' => $n], null);
        }

        return $coverage;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @param  Collection<int, LeadForm>  $forms
     * @param  Collection<int, ConversionDestination>  $destinations
     * @return list<array<string,mixed>>
     */
    private function deliveryChecks(array &$checks, string $tenant, string $project, $forms, $destinations, Carbon $start, Carbon $end): array
    {
        $out = [];
        foreach ($destinations as $d) {
            if ($d->kind === 'gtm_web') {
                continue;
            }
            $by = ConversionDelivery::withoutGlobalScopes()->where('tenant_id', $tenant)->where('conversion_destination_id', $d->id)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
            $stuck = (int) ConversionDelivery::withoutGlobalScopes()->where('tenant_id', $tenant)->where('conversion_destination_id', $d->id)->where('status', ConversionDelivery::QUEUED)->where('created_at', '<', Carbon::now()->subHour())->count();

            // Leads that WERE eligible: consent granted on a form this destination covers.
            $eligible = (int) DB::table('lead_form_submissions')->where('tenant_id', $tenant)->where('project_id', $project)->where('measurement_consent', 'granted')
                ->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)
                ->when($d->lead_form_id !== null, fn ($q) => $q->where('lead_form_id', $d->lead_form_id))->count();
            $reported = ($by['sent'] ?? 0) + ($by['test'] ?? 0) + ($by['queued'] ?? 0) + ($by['failed'] ?? 0);

            $row = [
                'destination_id' => (string) $d->id, 'name' => $d->name, 'kind' => $d->kind, 'status' => $d->status,
                'sent' => $by['sent'] ?? 0, 'failed' => $by['failed'] ?? 0, 'queued' => $by['queued'] ?? 0, 'queued_stuck' => $stuck,
                'skipped_consent' => $by['skipped_consent'] ?? 0, 'test' => $by['test'] ?? 0,
                'eligible_leads' => $eligible, 'attempted' => $reported, 'gap' => $eligible - $reported,
            ];
            $out[] = $row;

            $live = $d->status === ConversionDestination::LIVE;
            if (($by['failed'] ?? 0) > 0) {
                $checks[] = $this->check('delivery_failed:'.$d->id, $live ? 'critical' : 'info', "«{$d->name}»: {$row['failed']} failed deliveries", $live ? 'Conversions that should have reached the platform did not.' : 'This destination is not live, so nothing real was lost.', ['failed' => $row['failed']], $live ? 'Open the destination, read the last error, fix the credential or address, then retry the failed rows.' : null);
            }
            if ($stuck > 0) {
                $checks[] = $this->check('delivery_stuck:'.$d->id, 'warning', "«{$d->name}»: {$stuck} deliveries waiting over an hour", 'Queued deliveries that are not moving usually mean no queue worker is running, or the platform keeps answering with a retryable error.', ['stuck' => $stuck], 'Check the queue worker and the destination\'s last error.');
            }
            if ($live && $row['gap'] > 0) {
                $checks[] = $this->check('delivery_gap:'.$d->id, 'warning', "«{$d->name}»: {$row['gap']} consented leads were never attempted", "{$eligible} leads agreed to be reported and {$reported} deliveries exist.", ['eligible' => $eligible, 'attempted' => $reported], 'The destination may have been made live after those leads arrived; they are not sent retroactively.');
            }
            if ($live) {
                $needsAsk = $forms->filter(fn (LeadForm $f): bool => ($d->lead_form_id === null || $d->lead_form_id === $f->id) && ! $this->asksMeasurement($f));
                foreach ($needsAsk as $f) {
                    $checks[] = $this->check('never_asked:'.$d->id.':'.$f->id, 'warning', "«{$d->name}» is live but «{$f->name}» never asks for measurement consent", 'Nothing from that form is reported, by design.', null, 'Turn on the optional measurement consent in the form builder, or scope the destination to a form that asks.');
                }
            }
        }

        if ($destinations->where('kind', '!=', 'gtm_web')->isEmpty()) {
            $checks[] = $this->check('no_destinations', 'info', 'No conversion destinations', 'Leads are stored; no platform is told about them.', null, null);
        }

        return $out;
    }

    private function asksMeasurement(LeadForm $form): bool
    {
        $v = LeadFormVersion::withoutGlobalScopes()->find($form->published_version_id);

        return (bool) ($v?->definition['consent']['measurement']['enabled'] ?? false);
    }

    /**
     * What each ad platform says it produced, against what the Leads Centre holds from it.
     *
     * Not an error check: a platform counts on its own attribution window, a lead can be deleted or merged, and a lead form
     * sync can lag. A difference is a question, and each row carries both numbers so the person can ask it.
     *
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function reconcile(array &$checks, string $tenant, string $project, Carbon $start, Carbon $end, string $tz): array
    {
        // A metric day is a DATE in the project's own time zone; comparing it with the UTC date of the window's edge drops the last day.
        $firstDay = $start->copy()->setTimezone($tz)->toDateString();
        $lastDay = $end->copy()->subDay()->setTimezone($tz)->toDateString();
        $reported = DB::table('daily_metrics')->where('tenant_id', $tenant)->where('project_id', $project)->where('metric_key', 'leads')
            ->where('metric_date', '>=', $firstDay)->where('metric_date', '<=', $lastDay)
            ->selectRaw('provider, sum(value) as n')->groupBy('provider')->pluck('n', 'provider');
        $stored = DB::table('leads')->where('tenant_id', $tenant)->where('project_id', $project)->whereNull('deleted_at')->whereNotNull('provider')->where('provider', '<>', 'campaignshub_form')
            ->where('received_at', '>=', $start)->where('received_at', '<', $end)->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider');

        $out = [];
        foreach ($reported->keys()->merge($stored->keys())->unique()->sort() as $provider) {
            $r = (float) ($reported[$provider] ?? 0);
            $s = (int) ($stored[$provider] ?? 0);
            $out[] = ['provider' => $provider, 'platform_reported' => (int) round($r), 'stored' => $s, 'difference' => (int) round($r) - $s, 'stored_to_reported' => $r > 0 ? round($s / $r, 4) : null];
            if ($r >= 10 && $s / $r < 0.8) {
                $checks[] = $this->check('reconcile:'.$provider, 'warning', ucfirst((string) $provider).' reports more leads than the Leads Centre holds', sprintf('%d reported, %d stored (%d%%).', (int) round($r), $s, (int) round($s / $r * 100)), ['reported' => (int) round($r), 'stored' => $s], 'Check that the lead form sync for this platform is running and the form is bound to this project. A platform\'s own attribution window explains part of a gap.');
            }
        }
        if ($out === []) {
            $checks[] = $this->check('reconcile_none', 'unavailable', 'Nothing to reconcile', 'No ad platform reported leads for this project in the period.', null, null);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>|null  $numbers
     * @return array<string,mixed>
     */
    private function check(string $key, string $severity, string $title, ?string $detail, ?array $numbers, ?string $action): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'numbers' => $numbers, 'action' => $action];
    }
}
