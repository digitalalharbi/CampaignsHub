<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Unsubscribe;

/**
 * Signed one-click unsubscribe links (spec §9): every external message, and every CC/BCC copy, carries its own.
 *
 * The token is the claims (workspace, recipient row or copy address, subscription, project, event) base64url-encoded
 * and signed with HMAC-SHA256 over the application key. It is bound to workspace + recipient: a token can only ever act
 * on the row it names inside the workspace it names, and a changed byte fails the signature. Nothing secret or personal
 * is readable from it beyond the ids it acts on; it does not expire (an unsubscribe link must keep working).
 */
final class UnsubscribeLinks
{
    /**
     * Claims: t tenant, r recipient id, c copy address (CC/BCC without a recipient row), s subscription, p project, e event key.
     *
     * @param  array{t: string, r?: string|null, c?: string|null, s?: string|null, p?: string|null, e?: string|null}  $claims
     */
    public static function token(array $claims): string
    {
        $claims = array_filter($claims, static fn ($v): bool => $v !== null && $v !== '');
        $body = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        return $body.'.'.self::sign($body);
    }

    /** @return array<string, string>|null */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($claims) || ! isset($claims['t']) || (! isset($claims['r']) && ! isset($claims['c']))) {
            return null;
        }

        return array_map('strval', $claims);
    }

    /** @param array{t: string, r?: string|null, c?: string|null, s?: string|null, p?: string|null, e?: string|null} $claims */
    public static function url(array $claims, string $locale = 'ar'): string
    {
        return rtrim((string) config('app.url'), '/').'/n/u/'.self::token($claims).($locale === 'en' ? '?lang=en' : '');
    }

    private static function sign(string $body): string
    {
        return substr(hash_hmac('sha256', 'notif-unsub|'.$body, (string) config('app.key')), 0, 32);
    }
}
