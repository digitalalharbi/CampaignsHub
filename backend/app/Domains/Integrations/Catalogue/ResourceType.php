<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — what a connection to this provider actually selects (§1.5).
 *
 * «Ad account» is one provider category's noun, and using it for all of them is how a GA4 property or
 * a store ends up in an ad-account query. `ExternalAccount.account_type` keeps the stored spelling
 * (`ga4_property` stays); this is the vocabulary a caller asks in. Sheets and CRM are absent because no
 * such provider exists in this repository, and a case nothing can produce is an invention.
 */
enum ResourceType: string
{
    case AdAccount = 'ad_account';
    case Property = 'property';
    case Store = 'store';
}
