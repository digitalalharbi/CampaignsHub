<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Charts;

/**
 * The email's icon set: the product's own icon library (lucide, ISC licence — the same glyphs the screens draw with
 * lucide-react), reduced to the few kinds a service report needs and drawn as 24px stroke glyphs. Rendered to PNG by
 * resvg (never an icon font, never an emoji — mail clients drop both). Generated from frontend/node_modules/lucide-react.
 */
final class Icons
{
    /** @var array<string, string> */
    public const PATHS = [
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ];

    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }
}
