<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\CRM\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FORMS-PDPL-001 — the two ways form evidence leaves the product: it expires, or a person asks.
 *
 * ## What is erased, and what is deliberately kept
 *
 * Erased: the visit's attribution (UTM, click-id values, landing path, referrer host), the session id, the funnel events written
 * for the form or the lead, and the payloads stored for conversion deliveries (hashed identifiers are still identifiers). For a
 * data-subject request the LEAD's own personal fields go too — name, email, phone, the answers, the notes.
 *
 * Kept: the consent record — state, the wording's version, the measurement-consent state, when and against which form version.
 * That is the proof the processing was lawful; erasing it together with the data it justified would leave nothing to answer a
 * regulator with. The submission row itself stays as that proof, pointing at a lead that no longer says who it was.
 *
 * Campaign tags on the lead (`utm_*`) are kept: they describe an advertisement, not a person, and the aggregates the client was
 * billed against are built from them. Counting is unaffected: an erased lead still counts once.
 *
 * Every erasure is an audit entry. Neither path ever deletes a row another report is built on.
 */
final class FormDataRetention
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Anonymise the evidence of submissions older than their form's retention. Idempotent: a purged row is never touched again.
     *
     * @return array{submissions: int, events: int, deliveries: int}
     */
    public function purgeExpired(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $default = (int) config('forms.default_retention_days', 730);
        $totals = ['submissions' => 0, 'events' => 0, 'deliveries' => 0];

        $forms = DB::table('lead_forms')->get(['id', 'tenant_id', 'retention_days']);
        foreach ($forms as $form) {
            $cutoff = $now->copy()->subDays(max(30, (int) ($form->retention_days ?? $default)));

            $ids = DB::table('lead_form_submissions')->where('lead_form_id', $form->id)->whereNull('evidence_purged_at')->where('submitted_at', '<', $cutoff)->pluck('id');
            if ($ids->isNotEmpty()) {
                $totals['submissions'] += DB::table('lead_form_submissions')->whereIn('id', $ids)->update(['attribution' => null, 'session_id' => null, 'evidence_purged_at' => $now, 'purge_reason' => 'retention']);
            }

            $totals['events'] += DB::table('tracking_events')->where('tenant_id', $form->tenant_id)->where('subject_type', 'lead_form')->where('subject_id', $form->id)->where('occurred_at', '<', $cutoff)->delete();
            $totals['deliveries'] += DB::table('conversion_deliveries')->where('tenant_id', $form->tenant_id)->where('lead_form_id', $form->id)->whereNotNull('payload')->where('created_at', '<', $cutoff)->update(['payload' => null]);
        }

        return $totals;
    }

    /**
     * A data subject's erasure of one lead that came through a form. The lead stays as a counted, anonymous row.
     *
     * @return array{submissions: int, events: int, deliveries: int}
     */
    public function eraseLead(Lead $lead, ?User $by = null, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($lead, $by, $now): array {
            $submissions = DB::table('lead_form_submissions')->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)
                ->update(['attribution' => null, 'session_id' => null, 'evidence_purged_at' => $now, 'purge_reason' => 'erasure']);
            $events = DB::table('tracking_events')->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->delete();
            $deliveries = DB::table('conversion_deliveries')->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->update(['payload' => null]);

            // The columns that say who the person was. Raw updates: the model's normalisers must not re-derive anything.
            DB::table('leads')->where('id', $lead->id)->update([
                'name' => 'Erased', 'email' => null, 'phone' => null, 'email_normalized' => null, 'phone_normalized' => null,
                'form_answers' => null, 'notes' => null, 'click_id' => null, 'landing_page' => null, 'canonical_lead_id' => null,
                'updated_at' => $now,
            ]);

            $this->audit->log(
                action: 'lead.erased',
                entityType: Lead::class,
                entityId: (string) $lead->id,
                after: ['by' => $by?->id, 'submissions' => $submissions, 'events' => $events, 'deliveries' => $deliveries],
            );

            return ['submissions' => $submissions, 'events' => $events, 'deliveries' => $deliveries];
        });
    }
}
