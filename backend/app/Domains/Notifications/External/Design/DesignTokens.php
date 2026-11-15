<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Design;

use InvalidArgumentException;

/**
 * NOTIF-V2-EXTERNAL-001 — the ONE design-tokens source, read by the external email (spec §11).
 *
 * The file is `resources/design-tokens/tokens.json`, a byte-identical copy of the UI module
 * `frontend/src/lib/designTokens/tokens.json`, which was extracted from `frontend/src/styles/tokens.css`. The copy exists
 * because the backend image is built without the frontend tree; `DesignTokensSourceTest` fails when the two files differ
 * or when a value stops matching its CSS variable. Nothing in the external email names a colour, radius or size that is
 * not read through this class, and an unknown name throws rather than rendering an element with no colour.
 */
final class DesignTokens
{
    /** @var array<string, mixed>|null */
    private static ?array $tokens = null;

    public static function path(): string
    {
        return resource_path('design-tokens/tokens.json');
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$tokens === null) {
            $decoded = json_decode((string) file_get_contents(self::path()), true);
            self::$tokens = is_array($decoded) ? $decoded : [];
        }

        return self::$tokens;
    }

    public static function color(string $name, string $theme = 'light'): string
    {
        $value = self::all()['themes'][$theme === 'dark' ? 'dark' : 'light'][$name] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Unknown design token: {$name}");
        }

        return $value;
    }

    public static function platform(string $provider, string $theme = 'light'): string
    {
        $key = strtolower($provider);
        $name = self::all()['platforms'][$key] ?? self::all()['platforms'][explode('_', $key)[0]] ?? 'brand-600';

        return self::color((string) $name, $theme);
    }

    /** @return array{color: string, background: string} */
    public static function status(string $tone, string $theme = 'light'): array
    {
        $s = self::all()['status'][$tone] ?? self::all()['status']['on_track'];

        return ['color' => self::color((string) $s['color'], $theme), 'background' => self::color((string) $s['background'], $theme)];
    }

    /** @return list<string> */
    public static function series(string $theme = 'light'): array
    {
        return array_values(array_map(static fn ($n): string => self::color((string) $n, $theme), (array) self::all()['series']));
    }

    public static function radius(string $name): string
    {
        return self::scalar('radius', $name);
    }

    public static function space(string $name): string
    {
        return self::scalar('space', $name);
    }

    /** An email size in px (the UI's rem steps at 16px). */
    public static function px(string $name): string
    {
        $value = self::all()['email']['px'][$name] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("Unknown design token: email.px.{$name}");
        }

        return $value;
    }

    public static function emailFont(string $locale): string
    {
        return (string) self::all()['email'][$locale === 'en' ? 'font_en' : 'font_ar'];
    }

    public static function emailWidth(): int
    {
        return (int) self::all()['email']['width'];
    }

    private static function scalar(string $group, string $name): string
    {
        $value = self::all()[$group][$name] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Unknown design token: {$group}.{$name}");
        }

        return $value;
    }
}
