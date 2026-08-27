<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\CRM\Models\Lead;

/**
 * LEAD-DEDUP-001 — the same person, arriving twice, recorded twice and counted once.
 *
 * ## Why linking and not merging
 *
 * A duplicate is not a mistake to be cleaned up. It is a real acquisition event: somebody filled in a
 * second form, on a second platform, on a second day, and the money that bought that second submission
 * was really spent. Deleting the row destroys the evidence of what that spend produced. Merging
 * destroys the provenance of one of the two — which is precisely what LEAD-PROVENANCE-001 made
 * immutable, and for the same reason.
 *
 * So both rows survive with their own provenance and the later one points at the first. Counting then
 * becomes a question the reader asks rather than one the database answered irreversibly: «how many
 * submissions» and «how many people» are different figures and both remain available.
 *
 * ## What counts as the same person
 *
 * Normalised email or normalised phone, within one tenant AND one project. Both normalisations are
 * computed at ingestion, so this never scans raw PII to make a comparison.
 *
 * The project boundary is deliberate. The same person may be a lead for two different clients of the
 * same agency, and those are genuinely two leads — linking them would leak the fact of one client's
 * enquiry into another client's pipeline, which is a tenancy violation wearing a data-quality
 * justification.
 *
 * ## What is NOT matched on
 *
 * Name. Two people called محمد الحربي are not one person, and matching on it would silently collapse
 * them. A false duplicate is worse than a missed one here: it removes a real person's enquiry from the
 * list the sales team actually works.
 */
final class LinkDuplicateLead
{
    /**
     * Link this lead to the earliest one sharing its identity, if any.
     *
     * Returns the canonical lead, or null when this one IS canonical — which is how a caller tells
     * «received» from «unique», two figures every lead report has to show separately.
     */
    public function handle(Lead $lead): ?Lead
    {
        $email = $lead->email_normalized;
        $phone = $lead->phone_normalized;

        if ($email === null && $phone === null) {
            // Nothing to match on. Not a duplicate — unknown, and unknown is not a match.
            return null;
        }

        $canonical = Lead::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->when($lead->project_id !== null, fn ($q) => $q->where('project_id', $lead->project_id))
            ->when($lead->project_id === null, fn ($q) => $q->whereNull('project_id'))
            ->whereKeyNot($lead->getKey())
            /*
             * Only ever link to something that is itself canonical, and take the earliest. That stops
             * a chain forming: every duplicate points at the original rather than at the duplicate
             * before it, so `duplicates()` on the canonical returns the whole set and counting people
             * needs no traversal.
             */
            ->whereNull('canonical_lead_id')
            /*
             * Ordered by creation, ties broken by id, and the FIRST match wins.
             *
             * At ingestion this is simply «the one already here», because a later lead does not exist
             * yet. Re-run over history it is still deterministic: the same set always elects the same
             * canonical, so a backfill cannot produce a different shape than live ingestion did, and
             * two duplicates can never end up pointing at each other.
             *
             * Which row wins a same-second tie is not important. What is important is that every
             * duplicate of one person elects the SAME canonical and that the canonical is not itself a
             * duplicate — otherwise counting people means walking a chain, and every consumer that
             * forgets to walk it over-counts.
             */
            ->where(function ($q) use ($email, $phone) {
                if ($email !== null) {
                    $q->orWhere('email_normalized', $email);
                }
                if ($phone !== null) {
                    $q->orWhere('phone_normalized', $phone);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($canonical === null) {
            return null;
        }

        /*
         * `forceFill` + `saveQuietly`, so the provenance guard on `saving` does not read this as an
         * edit to the acquisition event. It is not one: nothing about where this lead came from
         * changes here, only what we have since learned about who it is.
         */
        $lead->forceFill([
            'canonical_lead_id' => $canonical->getKey(),
            'duplicate_reason' => $email !== null && $canonical->email_normalized === $email ? 'email' : 'phone',
        ])->saveQuietly();

        return $canonical;
    }
}
