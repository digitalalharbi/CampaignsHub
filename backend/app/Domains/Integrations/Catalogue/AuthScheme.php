<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * X-OAUTH1-001 — how a provider authenticates the calls made on a customer's behalf.
 *
 * This replaced a `usesPkce` boolean, and the replacement is the point. That flag described one bend
 * of OAuth 2.0 and was true for exactly one provider — X — whose Ads API does not accept OAuth 2.0 at
 * all. The X Ads API requires OAuth 1.0a: every request carries an `Authorization: OAuth …` header
 * signed with the app's consumer secret AND the user's token secret. A bearer token, whichever grant
 * produced it, is refused. A boolean about the details of a flow the provider does not use could only
 * describe the wrong thing precisely.
 *
 * - `OAuth2`  — authorization code → bearer access token (seven providers).
 * - `OAuth1a` — request token → user authorisation → access token + token secret, and every API
 *               request signed (RFC 5849, HMAC-SHA1). X Ads only.
 */
enum AuthScheme: string
{
    case OAuth2 = 'oauth2';
    case OAuth1a = 'oauth1a';
}
