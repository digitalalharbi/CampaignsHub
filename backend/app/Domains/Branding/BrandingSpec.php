<?php

declare(strict_types=1);

namespace App\Domains\Branding;

/**
 * The single source of truth for the Branding Center: what may be uploaded, where it may apply, and the
 * REQUIRED render sizes per asset kind. Nothing here touches storage or the database — it is pure spec so a
 * static scan (and the upload boundary) can validate a file without side effects.
 *
 * A brand asset is addressed by (scope, kind, theme):
 *   - scope : who the asset belongs to (platform → tenant → client/project/report/portal/email).
 *   - kind  : the render slot it fills (primary logo, report logo, square icon, favicon, …).
 *   - theme : which surface it is drawn on (light, dark, or any). Only some kinds ship a light+dark pair.
 */
final class BrandingSpec
{
    /** Ownership layers, widest fallback first. `scope_id` pins the concrete client/project/report when scoped. */
    public const SCOPES = ['platform', 'tenant', 'client', 'project', 'report', 'portal', 'email'];

    /** Render slots. Each maps to one entry in SPEC below. */
    public const KINDS = [
        'primary_horizontal', 'report_logo', 'square_icon', 'favicon', 'email_header', 'client_logo',
    ];

    public const THEME_LIGHT = 'light';

    public const THEME_DARK = 'dark';

    public const THEME_ANY = 'any';

    /** Theme surfaces. `any` is the theme-agnostic single asset used when no light/dark pair is provided. */
    public const THEMES = [self::THEME_LIGHT, self::THEME_DARK, self::THEME_ANY];

    /** Only vector or lossless raster is accepted — brand marks must never be re-compressed on the way in. */
    public const ALLOWED_MIME = ['image/svg+xml', 'image/png'];

    /** Hard ceiling per file. A brand mark that needs more than this is not a logo. */
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    /**
     * The full per-kind specification: human label, accepted formats (SVG preferred where a logo scales),
     * the REQUIRED pixel size(s) a raster fallback must satisfy, and whether the kind ships a light+dark pair.
     *
     * @var array<string, array{label: string, formats: array<string,string>, sizes: list<array{w:int,h:int}>, themed: bool}>
     */
    public const SPEC = [
        'primary_horizontal' => [
            'label' => 'Primary horizontal logo',
            'formats' => ['svg' => 'preferred', 'png' => 'fallback'],
            'sizes' => [['w' => 1200, 'h' => 300]],
            'themed' => true,
        ],
        'report_logo' => [
            'label' => 'Report logo',
            'formats' => ['svg' => 'preferred', 'png' => 'fallback'],
            'sizes' => [['w' => 800, 'h' => 240]],
            'themed' => true,
        ],
        'square_icon' => [
            'label' => 'Square app icon',
            'formats' => ['svg' => 'preferred', 'png' => 'fallback'],
            'sizes' => [['w' => 512, 'h' => 512]],
            'themed' => true,
        ],
        'favicon' => [
            'label' => 'Favicon',
            'formats' => ['png' => 'required', 'svg' => 'optional'],
            'sizes' => [['w' => 32, 'h' => 32], ['w' => 48, 'h' => 48], ['w' => 180, 'h' => 180]],
            'themed' => false,
        ],
        'email_header' => [
            'label' => 'Email header',
            'formats' => ['png' => 'preferred', 'svg' => 'fallback'],
            'sizes' => [['w' => 600, 'h' => 150]],
            'themed' => true,
        ],
        'client_logo' => [
            'label' => 'Client logo (square + horizontal)',
            'formats' => ['svg' => 'preferred', 'png' => 'fallback'],
            'sizes' => [['w' => 512, 'h' => 512], ['w' => 800, 'h' => 240]],
            'themed' => true,
        ],
    ];

    /**
     * Validate a candidate upload against the spec — MIME and byte size only. Dimensions are advisory
     * (recorded, not enforced) because an SVG has no intrinsic pixel size and a raster fallback is allowed to
     * exceed the minimum. Returns a small, serialisable verdict.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function validateUpload(string $kind, string $mime, int $bytes): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'error' => "Unknown branding kind [{$kind}]."];
        }

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            return ['ok' => false, 'error' => 'Only SVG or PNG brand assets are accepted.'];
        }

        if ($bytes <= 0) {
            return ['ok' => false, 'error' => 'The uploaded file is empty.'];
        }

        if ($bytes > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Brand assets may not exceed 2 MB.'];
        }

        return ['ok' => true];
    }

    /** Whether a kind supports a distinct light + dark pair (vs. a single theme-agnostic asset). */
    public static function supportsThemes(string $kind): bool
    {
        return (bool) (self::SPEC[$kind]['themed'] ?? false);
    }

    public static function isScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    public static function isKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function isTheme(string $theme): bool
    {
        return in_array($theme, self::THEMES, true);
    }

    /**
     * The REQUIRED render size(s) for a kind (raster fallback minimums).
     *
     * @return list<array{w:int,h:int}>
     */
    public static function sizesFor(string $kind): array
    {
        return self::SPEC[$kind]['sizes'] ?? [];
    }
}
