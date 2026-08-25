<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * REPORT-PROVIDER-NAME-001 — what a platform is CALLED, in a sentence a client reads.
 *
 * `ReportGenerator` interpolated the provider key straight into its findings and recommendations, so
 * a client's report said «زيادة ميزانية meta تدريجيًا» and «meta دون المتوسط» — a database key in
 * the middle of an Arabic sentence, in the one document that leaves the product.
 *
 * The names already existed on `PlatformOverviewController::PLATFORMS`, and the frontend keeps its
 * own copy for the same purpose. That is two, and adding a third here to fix this would be the
 * pattern this codebase keeps paying for. So the controller's map moves here and the controller
 * reads it — one owner, two callers, no new copy.
 *
 * `ProviderCatalogue::labelAr` is deliberately not reused: it is the API's long name («واجهة ميتا
 * الإعلانية»), correct on an integrations card and wrong inside a sentence about a budget.
 */
final class ProviderDisplayName
{
    /**
     * Ordered by how much of this market's spend each carries, which is the order every surface
     * lists them in.
     *
     * @var array<string, array{ar: string, en: string}>
     */
    public const NAMES = [
        'snapchat' => ['ar' => 'سناب شات', 'en' => 'Snapchat Ads'],
        'tiktok' => ['ar' => 'تيك توك', 'en' => 'TikTok Ads'],
        'meta' => ['ar' => 'ميتا (فيسبوك وإنستقرام)', 'en' => 'Meta'],
        'google' => ['ar' => 'إعلانات جوجل', 'en' => 'Google Ads'],
        'x' => ['ar' => 'منصة X', 'en' => 'X Ads'],
        'linkedin' => ['ar' => 'لينكدإن', 'en' => 'LinkedIn Ads'],
        'salla' => ['ar' => 'سلة', 'en' => 'Salla'],
        'zid' => ['ar' => 'زد', 'en' => 'Zid'],
    ];

    /**
     * The short name, for prose.
     *
     * «ميتا (فيسبوك وإنستقرام)» is right on a card listing what a connection covers and clumsy inside
     * a sentence, so the parenthetical is dropped here and kept there.
     */
    public static function short(string $provider, string $locale = 'ar'): string
    {
        $name = self::of($provider, $locale);

        $paren = mb_strpos($name, ' (');

        return $paren === false ? $name : mb_substr($name, 0, $paren);
    }

    /**
     * The full name.
     *
     * An unknown provider returns its own key rather than a placeholder: a connector the product
     * does not recognise is a fact worth seeing, and «غير معروف» would hide which one it was.
     */
    public static function of(string $provider, string $locale = 'ar'): string
    {
        $entry = self::NAMES[strtolower(trim($provider))] ?? null;

        if ($entry === null) {
            return $provider;
        }

        return $locale === 'en' ? $entry['en'] : $entry['ar'];
    }
}
