<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Leads\ProviderLead;

/**
 * A connector that can return the LEADS an account collected — LEAD-INGEST-001.
 *
 * A capability interface, following `ReportsCreativeInsights`: the syncer checks for it and skips
 * the call entirely for anyone else, so a provider that does not offer lead forms costs nothing and
 * is never asked a question it cannot answer.
 *
 * ## Why this is a separate capability rather than a method on every connector
 *
 * Lead delivery is the least uniform thing these APIs do. Meta and LinkedIn expose the submitted
 * form fields; TikTok gates them behind an additional authorisation; Google returns lead-form
 * submissions through a different surface again; and some providers will not return the contact
 * details through the reporting API at all, only a count. Putting `fetchLeads()` on the base
 * connector would force every provider to answer, and the honest answer for several of them is «this
 * provider does not expose that», which an interface expresses and a stub method does not.
 *
 * A connector that cannot return lead PII must NOT implement this interface. The count it reports in
 * insights is still real and still shown; what must never happen is a fabricated contact row to make
 * the two numbers agree.
 */
interface ReportsLeads
{
    /**
     * The leads this account collected in the window, exactly as the provider returned them.
     *
     * Returns provider truth, normalised into one shape and nothing more: no deduplication, no
     * assignment, no validity judgement. Those are operational decisions made later against the
     * canonical model, and a connector that made them here would bury the reason a lead was dropped
     * inside the integration layer where no operator can see it.
     *
     * @return list<ProviderLead>
     */
    public function fetchLeads(string $adAccountId, string $from, string $to): array;

    /**
     * Whether the provider will return the submitted FIELDS, or only that a lead happened.
     *
     * Some providers report a lead count in insights and withhold the contact details unless the app
     * holds an additional permission the customer may not have granted. The difference decides what
     * the product may promise: «we can show you 40 leads» versus «the platform says 40 leads and will
     * not tell us who they are». Both are honest; only one is useful, and conflating them produces an
     * empty inbox with no explanation.
     */
    public function exposesLeadFields(): bool;
}
