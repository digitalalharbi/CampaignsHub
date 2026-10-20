<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * REPORT-CLIENT-TECHNICAL-NAMES-001 — a content name a client reads, without the platform's own identifier.
 *
 * Meta and others name an ad after its post or its upload, and that name can end in a hash or a UUID:
 * «لا تضيع الفرصة، تواصل معنا الآن! 2026-08-24-9165f6a05f13c4ad27505e8535b0e6fe», or be nothing but one
 * («c31955d170048d9477e24ea…»). On a client's report those read as noise and as a leak of the platform's
 * internals; the Owner's rule is that technical identifiers are off by default for clients.
 *
 * Only a trailing run of 24 or more hexadecimal characters (or a canonical UUID) is removed, and only
 * after a separator, so a real word that merely looks hexadecimal is untouched. A name that is nothing
 * BUT such a token has no human name left, so it becomes a neutral label rather than the hash.
 */
final class ClientContentName
{
    /** The label for content whose only name was an identifier. */
    public const FALLBACK = 'محتوى · Content';

    public static function clean(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);
        $token = '(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{24,})';

        // Nothing but an identifier.
        if (preg_match('/^'.$token.'…?$/iu', $trimmed) === 1) {
            return self::FALLBACK;
        }

        $cleaned = preg_replace('/[\s\-_.:|]+'.$token.'$/iu', '', $trimmed);

        return $cleaned === null || trim($cleaned) === '' ? self::FALLBACK : trim($cleaned);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function onRow(array $row): array
    {
        if (isset($row['name']) && is_string($row['name'])) {
            $row['name'] = self::clean($row['name']);
        }

        return $row;
    }
}
