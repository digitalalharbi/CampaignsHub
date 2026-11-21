<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RELEASE-INTEGRATION — the platform text layer the admin edits on `/admin/notifications` (builder R's
 * `notification_platform_texts`), read by the external pipeline (builder Q).
 *
 * Only a row the admin SAVED is returned: without one the pipeline keeps its own defaults (the lang file's disclaimer,
 * its forbidden-word list, no invitation line), so nothing a recipient receives changes until an admin decides it.
 * Keys: `disclaimer.default` {ar,en} with a `{workspace}` placeholder, `footprint.invitation` {ar,en},
 * `phrasing.dictionary` {forbidden: list<string>, …}.
 */
final class PlatformTexts
{
    /** @return array<string, mixed>|null the admin's saved value, or null when the key was never customised */
    public static function customised(string $key): ?array
    {
        // Not memoised: a queue worker lives for hours and an admin's edit must reach the next message.
        if (! Schema::hasTable('notification_platform_texts')) {
            return null;
        }
        $raw = DB::table('notification_platform_texts')->where('key', $key)->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($value) ? $value : null;
    }

    /** A locale's text of a {ar,en} key, trimmed; null when not customised or empty. */
    public static function localised(string $key, string $locale): ?string
    {
        $v = self::customised($key)[$locale] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** @return list<string> the admin's additions to the forbidden words (they extend the pipeline's list, never replace it) */
    public static function forbiddenWords(): array
    {
        return array_values(array_filter(array_map('strval', (array) (self::customised('phrasing.dictionary')['forbidden'] ?? [])), static fn (string $w): bool => trim($w) !== ''));
    }
}
