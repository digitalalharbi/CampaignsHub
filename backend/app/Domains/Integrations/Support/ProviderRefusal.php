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

        // `· code N` is how Meta's code is stored; `(?<![\w])` keeps a subcode from being read as one.
        if (preg_match('/\b429\b|rate.?limit|too many|throttl|request limit reached|(?<![\w])code (4|17|32|341|613|800\d\d)(?!\d)/i', $message) === 1) {
            return false;
        }

        // Stored as `· code 190` with no `(#190)` and no word about permission: a dead token read as nothing at all.
        if (preg_match('/(?<![\w])code (10|102|190|2\d\d)(?!\d)|(?<![\w])code 100 · subcode 33(?!\d)/', $message) === 1) {
            return true;
        }

        return preg_match('/\(#(10|190|2\d\d)\)|permission|unauthori[sz]ed|forbidden|access denied|invalid[_ ]token|OAuthException|USER_PERMISSION_DENIED/i', $message) === 1;
    }
}
