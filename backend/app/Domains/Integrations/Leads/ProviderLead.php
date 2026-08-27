<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Leads;

use Illuminate\Support\Carbon;

/**
 * One lead as a provider returned it — LEAD-INGEST-001.
 *
 * The boundary between «what the platform said» and «what the product decided». Everything here is
 * provider truth: the ids it used, the time IT recorded, the answers the person actually typed.
 * Nothing is inferred, normalised for convenience, or defaulted to make a row look complete.
 *
 * `answers` stays an untyped map on purpose. A real-estate form asks for a budget and a district; a
 * clinic asks which procedure. Typing those here would make the connector layer know about verticals,
 * and the next customer would need a code change to be onboarded.
 */
final class ProviderLead
{
    /**
     * @param  array<string,mixed>  $answers  the submitted form fields, as the provider labelled them
     * @param  array<string,mixed>  $raw  the untouched payload, kept for provenance disputes
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerLeadId,
        public readonly ?Carbon $providerCreatedAt,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $externalAccountId = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $campaignName = null,
        public readonly ?string $adsetId = null,
        public readonly ?string $adsetName = null,
        public readonly ?string $adId = null,
        public readonly ?string $adName = null,
        public readonly ?string $creativeId = null,
        public readonly ?string $creativeName = null,
        public readonly ?string $formId = null,
        public readonly ?string $formName = null,
        public readonly ?string $landingPage = null,
        public readonly ?string $utmSource = null,
        public readonly ?string $utmMedium = null,
        public readonly ?string $utmCampaign = null,
        public readonly ?string $utmContent = null,
        public readonly ?string $utmTerm = null,
        public readonly ?string $clickId = null,
        public readonly array $answers = [],
        public readonly array $raw = [],
    ) {}

    /**
     * Whether this lead carries any way to reach the person.
     *
     * A lead with neither an email nor a phone number is still a real acquisition event the client
     * paid for, and is still ingested — but it cannot be worked, and the operator has to be told that
     * rather than left to discover it by calling nobody. Data quality reports it; ingestion does not
     * silently drop it.
     */
    public function isContactable(): bool
    {
        return ($this->email !== null && $this->email !== '')
            || ($this->phone !== null && $this->phone !== '');
    }
}
