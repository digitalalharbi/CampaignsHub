<?php

declare(strict_types=1);

namespace App\Domains\Legal;

/**
 * LEGAL-001 — which version of which policy is in force, and since when.
 *
 * ## Why this lives in code and not in a table
 *
 * A policy version is the thing an acceptance record points at. If it were a database row, the text a
 * user agreed to could be edited afterwards with nothing to show it had changed — and the acceptance
 * would still claim they agreed to whatever the row says today. In code it is diffable, reviewable
 * before it ships, and permanently recoverable from git by the commit that introduced the version.
 *
 * That is also why the CONTENT lives beside it in the frontend's `legalContent.ts` rather than in the
 * database: the two move together, in one reviewed change, or not at all.
 *
 * ## Bumping a version
 *
 * Raise `version` and set `effective` to the date the new text takes effect. Everyone who accepted an
 * earlier version keeps their record pointing at the earlier version — which is what makes «what did
 * this user actually agree to?» answerable a year later. Never edit a version in place after it has
 * shipped; that is the one operation this design exists to prevent.
 */
final class PolicyRegistry
{
    /**
     * Every published document, its current version and the date it took effect.
     *
     * `binding` marks the documents a user must accept to register or to pay. The rest are published
     * for reading — a subprocessor list nobody signs is still a document a regulator asks for.
     *
     * @var array<string, array{version: string, effective: string, binding: bool}>
     */
    private const DOCUMENTS = [
        'terms' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => true],
        'privacy' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => true],
        'data-processing' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'cookies' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'security' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'acceptable-use' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => true],
        'subscriptions-refunds' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => true],
        'retention' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'subprocessors' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'oauth-disclosure' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'account-deletion' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
        'data-requests' => ['version' => '1.0', 'effective' => '2026-08-07', 'binding' => false],
    ];

    /** @return list<string> every published document slug. */
    public static function slugs(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    public static function has(string $slug): bool
    {
        return array_key_exists($slug, self::DOCUMENTS);
    }

    /**
     * The documents a user must accept before registering or paying.
     *
     * Read from the same table as everything else, so adding a binding document is one edit and
     * cannot leave the acceptance flow behind.
     *
     * @return list<string>
     */
    public static function binding(): array
    {
        return array_keys(array_filter(self::DOCUMENTS, static fn (array $d): bool => $d['binding']));
    }

    /** @return array{slug: string, version: string, effective: string, binding: bool}|null */
    public static function get(string $slug): ?array
    {
        return self::has($slug) ? ['slug' => $slug] + self::DOCUMENTS[$slug] : null;
    }

    public static function versionOf(string $slug): ?string
    {
        return self::DOCUMENTS[$slug]['version'] ?? null;
    }

    /**
     * Everything a client needs to render the version strip and drive the acceptance checkboxes.
     *
     * @return list<array{slug: string, version: string, effective: string, binding: bool}>
     */
    public static function all(): array
    {
        return array_values(array_map(
            static fn (string $slug): array => self::get($slug),
            self::slugs(),
        ));
    }
}
