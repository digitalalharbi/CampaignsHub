<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SUPERADMIN-ACCESS-001 — sign a user out of every session and token at once.
 *
 * Sessions are in Redis with no per-user index, so they cannot be listed and deleted. Every session stamps when it signed in
 * (`auth_at`, set on login); this sets the user's cut-off, and `RejectRevokedSessions` answers 401 to any session stamped before it —
 * including sessions that predate the stamp. API tokens are deleted and the remember-me token rotated, so nothing signs back in on
 * its own.
 */
final class UserSessions
{
    public const SESSION_KEY = 'auth_at';

    public function revokeAll(User $user, ?Session $keep = null): void
    {
        $now = Carbon::now();

        $user->forceFill(['sessions_valid_after' => $now])->setRememberToken(Str::random(60));
        $user->save();
        $user->tokens()->delete();

        // The session that asked («sign out my OTHER sessions») stays: it is re-stamped at the cut-off itself.
        $keep?->put(self::SESSION_KEY, $now->getTimestamp());
    }

    public static function stamp(Session $session): void
    {
        $session->put(self::SESSION_KEY, Carbon::now()->getTimestamp());
    }

    public static function isStale(User $user, Session $session): bool
    {
        $raw = $user->getAttribute('sessions_valid_after');

        if ($raw === null) {
            return false;
        }

        $cutOff = Carbon::parse($raw);

        $signedInAt = $session->get(self::SESSION_KEY);

        return ! is_int($signedInAt) || $signedInAt < $cutOff->getTimestamp();
    }
}
