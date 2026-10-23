<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\CRM\Actions\AdvanceLead;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Domains\Tracking\Services\TrackingRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * FORMS-FUNNEL-EVENTS-001 — a first-party form's funnel, written to the one event log.
 *
 * Two kinds of event, and they are kept apart on purpose:
 *
 *  - the BROWSER's account of a visit — page_view, form_view, form_start, step_completed, submit_attempt. A
 *    browser can say what it did; it cannot say whether anything was stored, so none of these ever counts as a
 *    lead;
 *  - the SERVER's account of the lead — created, contacted, qualified, appointment, won. Written by the code
 *    that made the thing true ({@see SubmitLeadForm}, {@see AdvanceLead}), once per
 *    lead (`dedupe_key`), so a lead that bounces between stages is still one «contacted».
 *
 * Everything passes through {@see TrackingRecorder}, which owns the rules: nothing is written unless the
 * workspace switched `advanced_tracking` on for the project, a visitor key exists only with consent, no IP and
 * no user agent are stored, and a failure to record can never break the thing being measured.
 *
 * The browser sends only an event type, the step, the consent state it had, a per-page-load `session_id` that
 * lives in memory (no cookie, so a second visit is a second session) and an `event_id` that makes a retried
 * beacon one row. The click id's VALUE never goes into the log, only which platform's it was.
 */
final class FormFunnel
{
    private const STAGE_EVENT = [
        'contacted' => TrackingEvent::LEAD_CONTACTED,
        'qualified' => TrackingEvent::LEAD_QUALIFIED,
        'appointment' => TrackingEvent::LEAD_APPOINTMENT,
        'won' => TrackingEvent::LEAD_WON,
    ];

    public function __construct(private readonly TrackingRecorder $recorder) {}

    /** @param array<string,mixed> $payload */
    public function fromBrowser(LeadForm $form, array $payload, Request $request): void
    {
        $type = (string) ($payload['event'] ?? '');
        if (! in_array($type, TrackingEvent::BROWSER_FUNNEL, true)) {
            return;
        }

        $attribution = is_array($payload['attribution'] ?? null) ? $payload['attribution'] : [];
        $eventId = is_string($payload['event_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $payload['event_id']) === 1 ? $payload['event_id'] : null;
        $session = is_string($payload['session_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $payload['session_id']) === 1 ? $payload['session_id'] : null;

        $this->recorder->record([
            'tenant_id' => (string) $form->tenant_id,
            'project_id' => (string) $form->project_id,
            'event_type' => $type,
            'subject_type' => 'lead_form',
            'subject_id' => (string) $form->id,
            'source' => 'first_party_form',
            // «granted» only when the visitor has said so; everything earlier in the form is «unknown», never assumed.
            'consent_state' => ($payload['consent'] ?? 'unknown') === 'granted' ? 'granted' : 'unknown',
            'session_id' => $session,
            'step' => isset($payload['step']) && is_numeric($payload['step']) ? max(1, min(10, (int) $payload['step'])) : null,
            'click_id_type' => $this->clickIdType($attribution),
            'utm_source' => $this->utm($attribution, 'utm_source'),
            'utm_medium' => $this->utm($attribution, 'utm_medium'),
            'utm_campaign' => $this->utm($attribution, 'utm_campaign'),
            'utm_content' => $this->utm($attribution, 'utm_content'),
            'dedupe_key' => $eventId === null ? null : 'form:'.$form->id.':'.$eventId,
        ], $request);
    }

    public function leadCreated(LeadForm $form, Lead $lead, LeadFormSubmission $submission): void
    {
        $attribution = (array) ($submission->attribution ?? []);
        $this->recorder->record([
            'tenant_id' => (string) $form->tenant_id,
            'project_id' => (string) $form->project_id,
            'event_type' => TrackingEvent::LEAD_CREATED,
            'subject_type' => 'lead_form',
            'subject_id' => (string) $form->id,
            'source' => 'first_party_form',
            'consent_state' => $submission->measurement_consent === 'granted' ? 'granted' : 'unknown',
            'session_id' => $submission->session_id,
            'lead_id' => (string) $lead->id,
            'click_id_type' => isset($attribution['click_ids']) ? (string) array_key_first($attribution['click_ids']) : null,
            'utm_source' => $attribution['utm_source'] ?? null,
            'utm_medium' => $attribution['utm_medium'] ?? null,
            'utm_campaign' => $attribution['utm_campaign'] ?? null,
            'utm_content' => $attribution['utm_content'] ?? null,
            'occurred_at' => $submission->submitted_at,
            'dedupe_key' => 'lead_created:'.$submission->id,
        ]);
    }

    /** `lead_referred`: written for the NEW lead, when a verified referral code brought them. One per lead. */
    public function referred(LeadForm $form, Lead $lead, Lead $referrer): void
    {
        $this->recorder->record([
            'tenant_id' => (string) $form->tenant_id,
            'project_id' => (string) $form->project_id,
            'event_type' => TrackingEvent::LEAD_REFERRED,
            'subject_type' => 'lead_form',
            'subject_id' => (string) $form->id,
            'source' => 'leads_centre',
            'consent_state' => 'not_required',
            'lead_id' => (string) $lead->id,
            'dedupe_key' => 'lead_referred:'.$lead->id,
        ]);
    }

    /** Written by the Leads Centre's own transition, once per lead and kind, for a lead that came through a first-party form. */
    public function transition(Lead $lead, LeadStage $to, ?Carbon $at = null): void
    {
        $type = self::STAGE_EVENT[$to->value] ?? null;
        if ($type === null || $lead->provider !== 'campaignshub_form' || $lead->form_id === null) {
            return;
        }

        $this->recorder->record([
            'tenant_id' => (string) $lead->tenant_id,
            'project_id' => $lead->project_id === null ? null : (string) $lead->project_id,
            'event_type' => $type,
            'subject_type' => 'lead_form',
            'subject_id' => (string) $lead->form_id,
            'source' => 'leads_centre',
            'consent_state' => 'not_required',
            'lead_id' => (string) $lead->id,
            'utm_source' => $lead->utm_source,
            'utm_medium' => $lead->utm_medium,
            'utm_campaign' => $lead->utm_campaign,
            'utm_content' => $lead->utm_content,
            'occurred_at' => $at ?? Carbon::now(),
            'dedupe_key' => $type.':'.$lead->id,
        ]);
    }

    /** @param array<string,mixed> $attribution */
    private function utm(array $attribution, string $key): ?string
    {
        return isset($attribution[$key]) && is_string($attribution[$key]) ? mb_substr($attribution[$key], 0, 190) : null;
    }

    /** @param array<string,mixed> $attribution */
    private function clickIdType(array $attribution): ?string
    {
        foreach (SubmitLeadForm::CLICK_IDS as $type) {
            if (isset($attribution[$type]) && is_string($attribution[$type]) && $attribution[$type] !== '') {
                return $type;
            }
        }

        return null;
    }
}
