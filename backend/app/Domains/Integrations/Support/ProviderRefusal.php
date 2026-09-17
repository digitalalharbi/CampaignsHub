<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

/**
 * Is a stored provider message a refusal about ACCESS — as opposed to a throttle or an outage?
 *
 * The distinction decides what an operator is told and whether asking again can help: a permission
 * refusal cannot change until the owner re-authorises; a rate limit or a 5xx will pass on its own.
 */
final class ProviderRefusal
{
    public static function isPermission(?string $message): bool
    {
        if ($message === null || trim($message) === '') {
            return false;
        }

        if (preg_match('/\b429\b|rate.?limit|too many|throttl/i', $message) === 1) {
            return false;
        }

        return preg_match('/\(#(10|190|2\d\d)\)|permission|unauthori[sz]ed|forbidden|access denied|invalid[_ ]token|OAuthException|USER_PERMISSION_DENIED/i', $message) === 1;
    }
}
