<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

/**
 * META-INSIGHTS-GRANT-001 — «connected» and «allowed to read insights» are two different facts.
 *
 * A Meta connection can complete OAuth perfectly — consent given, token exchanged, `me/adaccounts`
 * answering with seventeen accounts — and still be refused the moment anything asks for spend:
 *
 *     (#200) Ad account owner has NOT grant ads_management or ads_read permission
 *
 * That is an AD ACCOUNT's grant, not the app's. It is decided by whoever owns the account in Business
 * Manager, after the consent screen, and no amount of re-exchanging the same token changes it. The
 * integrations page showed a green connection over a sync that could never succeed, which is the one
 * shape of wrong that costs a customer a day: every layer reported success and no figure ever arrived.
 *
 * ## What this knows, and what it refuses to guess
 *
 * `ads_read` is sufficient for Insights. `ads_management` also implies it, being the write scope over
 * the same surface — so a connection holding either can read. `business_management` is a DIFFERENT
 * capability, for the Business hierarchy, and is not required to read insights: naming it as required
 * would send a customer to grant more than the product needs, which is its own kind of wrong.
 *
 * Only Meta is judged here. Every other provider returns «no opinion», because inventing a scope name
 * for a platform whose grant model has not been verified would mark working connections broken — the
 * exact failure this class exists to stop, pointed the other way.
 */
final class InsightsAuthorisation
{
    /** Either of these lets a Meta token read `/{ad-account}/insights`. */
    private const META_READS_INSIGHTS = ['ads_read', 'ads_management'];

    /**
     * Meta's refusal, recognised by its code and its subject rather than by its whole sentence.
     *
     * Graph puts the code in parentheses at the head of the message and names the two scopes it
     * wanted. Matching the code alone would catch unrelated #200s — it is Meta's general permissions
     * error — and matching the whole sentence would miss the day they reword it.
     */
    public static function refusedBy(?string $providerMessage): bool
    {
        if ($providerMessage === null || $providerMessage === '') {
            return false;
        }

        return str_contains($providerMessage, '#200')
            && (str_contains($providerMessage, 'ads_read') || str_contains($providerMessage, 'ads_management'));
    }

    /**
     * Whether the GRANT recorded at authorisation can read insights — «no» only when we know it cannot.
     *
     * Three answers collapse into two on purpose. A grant that names neither read scope cannot read,
     * and that is knowable without calling anybody. A grant we have no record of is UNKNOWN, and
     * unknown is not «broken»: rows predate the column, and marking them reconnect-required on a
     * guess would send customers to re-authorise connections that work. The provider's own refusal is
     * what settles those, and it settles them authoritatively — see {@see refusedBy()}.
     */
    public static function grantedBy(string $provider, mixed $scopes): bool
    {
        if ($provider !== 'meta') {
            return true;
        }

        if (! is_array($scopes) || $scopes === []) {
            return true;
        }

        foreach ($scopes as $scope) {
            if (in_array((string) $scope, self::META_READS_INSIGHTS, true)) {
                return true;
            }
        }

        return false;
    }
}
