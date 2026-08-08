<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * Where an email's links actually go — MAIL-008.
 *
 * ## Why this exists at all
 *
 * The digest footer pointed at `/account/notifications` and the operational messages at
 * `/app/settings/notifications`. Both resolve today — one directly, one through a redirect — which
 * is exactly why nobody would have noticed when one of them stopped. Two literals describing one
 * destination is a dead link waiting for a router change, and the link in question is the
 * unsubscribe: the one a person reaches for when they are already annoyed, and the one whose
 * failure turns a digest into a spam report.
 *
 * The canonical path is `/app/account/notifications` — the route the router actually declares,
 * rather than either of the addresses that happen to redirect there.
 */
final class MailLinks
{
    /** The application's own origin, without a trailing slash. */
    public static function app(): string
    {
        return rtrim((string) config('brand.frontend_url', 'https://campaignshub.io'), '/');
    }

    public static function to(string $path): string
    {
        return self::app().'/'.ltrim($path, '/');
    }

    /**
     * The footer every message carries.
     *
     * The three policies each portal footer carries, plus the way out. An unsubscribe a person
     * cannot find is how a useful digest becomes a spam report — which costs the sending domain,
     * not just the message.
     *
     * @return array<string,string>
     */
    public static function footer(): array
    {
        return [
            'preferences' => self::to('/app/account/notifications'),
            'privacy' => self::to('/privacy'),
            'terms' => self::to('/terms'),
            'security' => self::to('/security'),
        ];
    }
}
