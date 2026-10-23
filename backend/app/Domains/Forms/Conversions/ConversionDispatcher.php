<?php

declare(strict_types=1);

namespace App\Domains\Forms\Conversions;

use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Jobs\DeliverConversion;
use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Support\Frontend;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FORMS-DISPATCH-001 — decides, for one stored lead, what each of the project's destinations is told.
 *
 * The lead is already stored when this runs; nothing here can lose it, and a failure here is logged and swallowed for
 * the same reason the event recorder's is — a destination being down must never turn a stored lead into an error.
 *
 *  - CONSENT first. A visitor who had not consented produces a `skipped_consent` row and NOTHING else: no payload is
 *    built and no identifier is hashed, so there is no conversion to leak. The row stays, so «why did Meta hear about
 *    four of these five» has an answer.
 *  - ONE delivery per (destination, event id). The event id is the submission's id — the same one the browser's tag is
 *    given — so a platform that hears from both the browser and the server counts one conversion, and a replay of this
 *    method is a no-op.
 *  - TEST destinations build the exact payload and record it as `test`; they send nothing. Only a destination the team
 *    deliberately made `live` is queued.
 */
final class ConversionDispatcher
{
    public function forLead(LeadForm $form, Lead $lead, LeadFormSubmission $submission): void
    {
        try {
            $destinations = ConversionDestination::query()
                ->where('project_id', $form->project_id)
                ->where('status', '!=', ConversionDestination::OFF)
                ->where(fn ($q) => $q->whereNull('lead_form_id')->orWhere('lead_form_id', $form->id))
                ->get();

            foreach ($destinations as $destination) {
                $this->one($destination, $form, $lead, $submission);
            }
        } catch (Throwable $e) {
            Log::warning('conversion dispatch failed', ['form' => (string) $form->id, 'reason' => $e->getMessage()]);
        }
    }

    private function one(ConversionDestination $d, LeadForm $form, Lead $lead, LeadFormSubmission $submission): void
    {
        $base = [
            'tenant_id' => $d->tenant_id,
            'project_id' => $d->project_id,
            'conversion_destination_id' => $d->id,
            'lead_form_id' => $form->id,
            'lead_id' => $lead->id,
            'event_name' => (string) ($d->config['event_name'] ?? ''),
            'event_id' => (string) $submission->id,
        ];

        try {
            if ($submission->consent_state !== 'granted') {
                DB::transaction(fn () => ConversionDelivery::create($base + ['status' => ConversionDelivery::SKIPPED_CONSENT]));

                return;
            }

            $payload = ConversionPayloads::build($d, $this->evidence($form, $lead, $submission));
            $isTest = $d->status !== ConversionDestination::LIVE;
            $delivery = DB::transaction(fn () => ConversionDelivery::create($base + ['status' => $isTest ? ConversionDelivery::TEST : ConversionDelivery::QUEUED, 'payload' => $payload]));

            if (! $isTest) {
                DeliverConversion::dispatch((string) $delivery->id)->afterCommit();
            }
        } catch (UniqueConstraintViolationException) {
            // The same conversion for the same destination: already recorded.
        }
    }

    /**
     * @return array{event_id: string, occurred_at: CarbonInterface, email: ?string, phone: ?string, name: ?string, form_name: string, session_id: ?string, utm: array<string,string>, click_ids: array<string,string>, ga_client_id: ?string, ga_session_id: ?string, source_url: ?string}
     */
    private function evidence(LeadForm $form, Lead $lead, LeadFormSubmission $submission): array
    {
        $a = (array) ($submission->attribution ?? []);
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
            if (isset($a[$k]) && is_string($a[$k])) {
                $utm[$k] = $a[$k];
            }
        }

        return [
            'event_id' => (string) $submission->id,
            'occurred_at' => $submission->submitted_at ?? Carbon::now(),
            'email' => $lead->email_normalized ?: ($lead->email ?: null),
            'phone' => $lead->phone_normalized ?: ($lead->phone ?: null),
            'name' => $lead->name ?: null,
            'form_name' => (string) $form->name,
            'session_id' => $submission->session_id,
            'utm' => $utm,
            'click_ids' => array_map('strval', (array) ($a['click_ids'] ?? [])),
            'ga_client_id' => isset($a['ga_client_id']) ? (string) $a['ga_client_id'] : null,
            'ga_session_id' => isset($a['ga_session_id']) ? (string) $a['ga_session_id'] : null,
            'source_url' => isset($a['landing_path']) ? Frontend::url((string) $a['landing_path']) : null,
        ];
    }
}
