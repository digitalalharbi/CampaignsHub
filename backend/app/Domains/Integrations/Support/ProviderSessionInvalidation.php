<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\TokenVault;

/**
 * META-SESSION-190-001 — a refusal that only a new consent can fix (Meta code 190; X UNAUTHORIZED_ACCESS).
 *
 * Meta answers code 190 for a token it no longer honours: expired, the person changed their password, removed the app, or hit a
 * security checkpoint (subcodes 458–467, 492). Waiting never fixes it, so the connection is marked `error`, which every surface already
 * reads as «يحتاج إعادة مصادقة». A rate limit (4, 17, 32, 613) or a transient error (1, 2) is fixed by waiting and is NOT this: sending
 * somebody to reconnect a working authorisation is the wrong action.
 *
 * The refusal is recognised by Meta's own code, which `PlatformHttp::reason()` carries as `· code 190`, never by the human sentence.
 * Nothing here touches a token, a credential, an account or a figure: only the connection's status and its last error.
 */
final class ProviderSessionInvalidation
{
    public static function invalidatedBy(string $provider, ?string $message): bool
    {
        if ($message === null) {
            return false;
        }

        return match ($provider) {
            'meta' => preg_match('/(?<![\w])code 190(?!\d)|\(#190\)/', $message) === 1,
            // X's «This request is not properly authenticated»: the stored user token no longer authenticates. An ad-account grant
            // refusal (NOT_AUTHORIZED…) or a rate limit is not this.
            'x' => preg_match('/(?<![\w])code UNAUTHORIZED_ACCESS(?![\w])/', $message) === 1,
            default => false,
        };
    }

    /**
     * Mark the connection for reconnecting when `$message` is an invalidated session. True when it was one.
     *
     * Only a `connected` connection is moved: a disconnected or revoked one stays exactly what somebody made it.
     */
    public function record(?ProviderConnection $connection, ?string $message): bool
    {
        if ($connection === null || ! self::invalidatedBy((string) $connection->provider, $message)) {
            return false;
        }

        if ($connection->status === 'connected') {
            app(TokenVault::class)->markError($connection, (string) $message);
        }

        return true;
    }
}
