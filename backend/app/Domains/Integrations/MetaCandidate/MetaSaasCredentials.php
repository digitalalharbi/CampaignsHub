<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\OAuth\MetaCredentialProfile;

/**
 * META-SAAS-APP-001 — the third Meta app's own credentials: App ID, App Secret (encrypted), Configuration ID and scopes, in the row
 * `meta.saas` with `META_SAAS_*` as the environment fallback. Nothing is ever read from, or written to, the Live or Candidate rows.
 */
final class MetaSaasCredentials extends MetaCandidateCredentials
{
    public function profile(): MetaCredentialProfile
    {
        return MetaCredentialProfile::Saas;
    }
}
