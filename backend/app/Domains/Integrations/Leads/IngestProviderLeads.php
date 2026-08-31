<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Leads;

use App\Domains\CRM\Actions\LinkDuplicateLead;
use App\Domains\CRM\Models\Lead;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Provider leads into the canonical model — LEAD-INGEST-001.
 *
 * One entrance for every route a lead can arrive by: a webhook, an incremental poll, a historical
 * backfill. They differ in what wakes them up and not at all in what a lead means, so they share
 * this and nothing re-decides provenance, idempotency or normalisation on its own.
 *
 * ## Insert, then let the database refuse
 *
 * A webhook and a backfill will both see the same lead, and providers retry deliveries. Asking
 * «does this exist?» first is a race with itself: two deliveries check, both find nothing, both
 * insert. So this inserts and catches the unique violation on
 * `(tenant_id, provider, provider_lead_id)` — the guarantee lives in the schema, where a race cannot
 * get underneath it.
 *
 * A re-delivery is a SUCCESS, not an error. The provider is behaving correctly; our previous 2xx was
 * lost, or two paths saw the same lead. It is counted separately so a sync that ingested nothing new
 * can say why.
 *
 * ## What this deliberately does not do
 *
 * No deduplication of people, no assignment, no validity verdict, no lead dropped for missing
 * contact details. Those are operational decisions about a lead that already exists, and making them
 * here would bury the reason inside the integration layer where no operator can see it. A lead with
 * neither phone nor email is still an acquisition event the client paid for; Data Quality reports it.
 */
final class IngestProviderLeads
{
    /**
     * @param  list<ProviderLead>  $leads
     * @return array{ingested: int, redelivered: int, uncontactable: int}
     */
    public function handle(string $tenantId, array $leads): array
    {
        $ingested = 0;
        $duplicates = 0;
        $redelivered = 0;
        $uncontactable = 0;

        foreach ($leads as $lead) {
            if (! $lead->isContactable()) {
                $uncontactable++;
            }

            try {
                /*
                 * Wrapped so a violation is scoped to a SAVEPOINT.
                 *
                 * Postgres aborts the whole transaction a failed statement was in, so catching the
                 * violation and then continuing the loop would leave every later insert refused with
                 * «current transaction is aborted». Standalone that never bites; inside a caller's
                 * transaction — a backfill wrapping a batch, a test's RefreshDatabase — it is the
                 * recovery path that breaks. The same lesson `WebhookIngest` already records.
                 */
                $created = DB::transaction(fn () => Lead::create($this->attributes($tenantId, $lead)));
                $ingested++;

                /*
                 * LEAD-DEDUP-001 — link, never drop.
                 *
                 * Deliberately AFTER the insert and outside its savepoint. The lead is stored either
                 * way: a duplicate is a real acquisition event that real money bought, and losing it
                 * would understate what the spend produced. Linking only records what we have since
                 * learned about who it is, so a failure here must not cost us the row.
                 *
                 * Counted separately from `ingested` because «received» and «unique» are different
                 * figures, and a lead report that publishes one under the other's name is the same
                 * class of defect as a total that omits a contributor.
                 */
                if (app(LinkDuplicateLead::class)->handle($created) !== null) {
                    $duplicates++;
                }
            } catch (UniqueConstraintViolationException) {
                $redelivered++;
            }
        }

        return [
            'ingested' => $ingested,
            'redelivered' => $redelivered,
            'uncontactable' => $uncontactable,
            // Of the ingested, how many were somebody we already had. Never subtracted from `ingested`.
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attributes(string $tenantId, ProviderLead $lead): array
    {
        return [
            'tenant_id' => $tenantId,
            // The project comes off the ACCOUNT's binding, never off the payload. A body that could
            // name its own project is a body that could name somebody else's — the same rule the
            // webhook ledger already follows for tenants.
            'project_id' => $this->projectFor($tenantId, $lead),

            'name' => $lead->name ?? '',
            'email' => $lead->email,
            'phone' => $lead->phone,
            'source' => 'paid',
            'status' => 'new',

            'provider' => $lead->provider,
            'external_account_id' => $lead->externalAccountId,
            'provider_lead_id' => $lead->providerLeadId,
            'provider_created_at' => $lead->providerCreatedAt,
            'received_at' => Carbon::now(),

            'external_campaign_id' => $lead->campaignId,
            'campaign_name' => $lead->campaignName,
            'external_adset_id' => $lead->adsetId,
            'adset_name' => $lead->adsetName,
            'external_ad_id' => $lead->adId,
            'ad_name' => $lead->adName,
            'external_creative_id' => $lead->creativeId,
            'creative_name' => $lead->creativeName,
            'form_id' => $lead->formId,
            'form_name' => $lead->formName,

            'landing_page' => $lead->landingPage,
            'utm_source' => $lead->utmSource,
            'utm_medium' => $lead->utmMedium,
            'utm_campaign' => $lead->utmCampaign,
            'utm_content' => $lead->utmContent,
            'utm_term' => $lead->utmTerm,
            'click_id' => $lead->clickId,
            'form_answers' => $lead->answers,

            /*
             * Normalised keys for matching, computed once at ingestion.
             *
             * `PhoneNumber::normalise` already handles the case an Arabic keyboard produces — `٠٥٠`
             * is `050` — so two submissions of the same number from different keyboards match. Doing
             * this at ingestion rather than at query time means dedup never scans raw PII.
             */
            'phone_normalized' => PhoneNumber::normalise($lead->phone),
            'email_normalized' => $this->normaliseEmail($lead->email),
        ];
    }

    /** The project this account is bound to, or null — a lead may arrive before the binding exists. */
    private function projectFor(string $tenantId, ProviderLead $lead): ?string
    {
        if ($lead->externalAccountId === null) {
            return null;
        }

        $project = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('external_id', $lead->externalAccountId)
            ->where('provider', $lead->provider)
            ->value('project_id');

        return $project === null ? null : (string) $project;
    }

    /**
     * Lower-cased and trimmed, and nothing cleverer.
     *
     * Deliberately NOT stripping Gmail dots or `+tag` suffixes: those are provider-specific delivery
     * rules, and treating `a.b@gmail.com` and `ab@gmail.com` as one person is a guess. A guess that
     * merges two real buyers is worse than two rows an operator can see and merge themselves.
     */
    private function normaliseEmail(?string $email): ?string
    {
        $email = $email === null ? null : mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }
}
