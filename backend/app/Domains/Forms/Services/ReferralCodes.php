<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\CRM\Models\Lead;

/**
 * FORMS-REFERRALS-001 — a referral code names the lead who is doing the referring, and proves it was issued by us.
 *
 * `{lead id without dashes}.{8 hex of an HMAC over the id, the tenant and the form}`. The signature is keyed with the
 * application secret and bound to the TENANT and the FORM, so a code cannot be carried to another workspace or another form,
 * and a visitor cannot invent one for a lead they have heard of. A code that does not verify is simply ignored — the
 * submission is still accepted, because a mistyped link must not cost a client a lead.
 *
 * The code carries no personal data: a lead id is an opaque uuid.
 */
final class ReferralCodes
{
    public static function issue(Lead $referrer, string $formId): string
    {
        $id = str_replace('-', '', (string) $referrer->id);

        return $id.'.'.self::sign($id, (string) $referrer->tenant_id, $formId);
    }

    /** The referring lead's id when the code is genuine for THIS tenant and form; otherwise null. */
    public static function verify(string $code, string $tenantId, string $formId): ?string
    {
        if (preg_match('/^([a-f0-9]{32})\.([a-f0-9]{8})$/', $code, $m) !== 1) {
            return null;
        }
        if (! hash_equals(self::sign($m[1], $tenantId, $formId), $m[2])) {
            return null;
        }

        return substr($m[1], 0, 8).'-'.substr($m[1], 8, 4).'-'.substr($m[1], 12, 4).'-'.substr($m[1], 16, 4).'-'.substr($m[1], 20);
    }

    private static function sign(string $id, string $tenantId, string $formId): string
    {
        return substr(hash_hmac('sha256', $id.'|'.$tenantId.'|'.$formId, (string) config('app.key')), 0, 8);
    }
}
