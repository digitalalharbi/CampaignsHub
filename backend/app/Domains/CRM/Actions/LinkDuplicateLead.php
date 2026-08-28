<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\CRM\Models\Lead;
use Illuminate\Support\Facades\DB;

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
 * list the sales team actually works. That asymmetry — a wrong link costs more than a missed one —
 * is what decides the ambiguous case below.
 */
final class LinkDuplicateLead
{
    /**
     * Link this lead to the earliest one sharing its identity, if any.
     *
     * Returns the canonical lead, or null when this one IS canonical — which is how a caller tells
     * «received» from «unique», two figures every lead report has to show separately.
     *
     * Null is therefore returned for three different situations that a caller does not need to tell
     * apart, but a reader of the data does: nothing to match on, nothing matched, and an identity too
     * ambiguous to resolve. `duplicate_reason` distinguishes the third.
     */
    public function handle(Lead $lead): ?Lead
    {
        $email = $lead->email_normalized;
        $phone = $lead->phone_normalized;

        if ($email === null && $phone === null) {
            // Nothing to match on. Not a duplicate — unknown, and unknown is not a match.
            return null;
        }

        /*
         * The whole election runs inside one transaction holding an advisory lock per identity.
         *
         * Without it, two workers ingesting the same person concurrently both read «no canonical yet»
         * and both stay canonical, or worse each elects the other and the person has no canonical at
         * all. The lock is keyed on the identity rather than the row, because at the moment of the race
         * the row the second worker needs to see may not be committed yet — there is nothing to lock.
         *
         * Locks are taken in sorted order so a lead holding both an email and a phone can never
         * deadlock against one holding the same two in the other order. Hash collisions between
         * unrelated identities are possible and harmless: they serialise two elections that did not
         * need serialising, and never merge two people.
         */
        return DB::transaction(function () use ($lead, $email, $phone): ?Lead {
            $this->lockIdentity($lead, array_filter([$email, $phone]));

            /*
             * Re-read under the lock. A concurrent worker may have linked this very lead between the
             * insert and here, and a backfill re-run over history reaches this line with the work
             * already done. Both must be idempotent rather than elect a second time.
             */
            $lead->refresh();

            if ($lead->canonical_lead_id !== null) {
                // Read without scopes, as every other read here does: the caller may hold no tenant
                // context at all, and the link has already been decided — this only reports it back.
                return Lead::withoutGlobalScopes()->whereKey($lead->canonical_lead_id)->first();
            }

            $byEmail = $email === null ? null : $this->earliestCanonical($lead, 'email_normalized', $email);
            $byPhone = $phone === null ? null : $this->earliestCanonical($lead, 'phone_normalized', $phone);

            /*
             * Ambiguous identity: the email says this is one person, the phone says it is a different
             * one. Something is wrong upstream — a typo, a shared family phone, a form filled in on
             * somebody else's behalf — and this action cannot tell which.
             *
             * Linking to either would silently collapse two real people into one, and the arbitrary
             * choice would be invisible afterwards. So it links to neither, stays canonical, and says
             * so. Over-counting people by one is a figure a human can correct; a person deleted from
             * the list the sales team works is not recoverable by looking at the data.
             */
            if ($byEmail !== null && $byPhone !== null && ! $byEmail->is($byPhone)) {
                $this->record($lead, null, 'ambiguous');

                return null;
            }

            $canonical = $byEmail ?? $byPhone;

            if ($canonical === null) {
                return null;
            }

            /*
             * A lead never links to one that arrived after it.
             *
             * Without this the outcome depends on the order elections happen to run in: processing the
             * original while a later duplicate already exists would point the original at the
             * duplicate, inverting «duplicates point at the original» and leaving the group's canonical
             * dependent on scheduling. Ingestion happens to process leads in arrival order, so the
             * defect stays invisible there and surfaces in a backfill, a retry, or a redelivery.
             *
             * Only a strictly later timestamp is refused. `created_at` is stored to the second, so two
             * submissions in the same second are indistinguishable in arrival order and whichever is
             * elected first wins — still exactly one canonical for the group, still no chain and no
             * mutual link, but which of the two it is depends on election order. Claiming more than
             * that would be claiming a precision the column does not have.
             */
            if ($this->arrivedAfter($canonical, $lead)) {
                return null;
            }

            $this->record($lead, $canonical, $byEmail !== null ? 'email' : 'phone');

            return $canonical;
        });
    }

    /**
     * The earliest canonical lead in this lead's tenant and project matching one identity field.
     *
     * Only ever elects something that is itself canonical, so no chain can form: every duplicate points
     * at the original rather than at the duplicate before it, and counting people needs no traversal.
     */
    private function earliestCanonical(Lead $lead, string $column, string $value): ?Lead
    {
        return Lead::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->when($lead->project_id !== null, fn ($q) => $q->where('project_id', $lead->project_id))
            ->when($lead->project_id === null, fn ($q) => $q->whereNull('project_id'))
            ->whereKeyNot($lead->getKey())
            ->whereNull('canonical_lead_id')
            ->where($column, $value)
            // Ties broken by id so the same set always elects the same canonical.
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Did $a arrive strictly after $b?
     *
     * A missing timestamp is treated as «not after», which keeps a row with no `created_at` electable
     * rather than silently unlinkable.
     */
    private function arrivedAfter(Lead $a, Lead $b): bool
    {
        $at = $a->created_at?->getTimestamp();
        $bt = $b->created_at?->getTimestamp();

        if ($at === null || $bt === null) {
            return false;
        }

        return $at > $bt;
    }

    /**
     * `forceFill` + `saveQuietly`, so the provenance guard on `saving` does not read this as an edit to
     * the acquisition event. It is not one: nothing about where this lead came from changes here, only
     * what we have since learned about who it is.
     */
    private function record(Lead $lead, ?Lead $canonical, string $reason): void
    {
        $lead->forceFill([
            'canonical_lead_id' => $canonical?->getKey(),
            'duplicate_reason' => $reason,
        ])->saveQuietly();
    }

    /**
     * Serialise every election touching these identities, for the rest of the transaction.
     *
     * Advisory locks are a Postgres facility. On any other driver this is a no-op and the election
     * falls back to being correct only under the sequential ingestion path — which is why the
     * production and test databases are both Postgres.
     */
    private function lockIdentity(Lead $lead, array $identities): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        sort($identities);

        foreach ($identities as $identity) {
            $key = 'lead-identity:'.$lead->tenant_id.':'.($lead->project_id ?? '-').':'.$identity;
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }
}
