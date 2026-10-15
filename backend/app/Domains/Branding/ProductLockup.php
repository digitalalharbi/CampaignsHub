<?php

declare(strict_types=1);

namespace App\Domains\Branding;

/**
 * SHARE-CARD-BRAND-001 — the canonical CampaignsHub lockup, as a data URI a card can draw.
 *
 * The files in `resources/brand` are the brand pack's own dark lockups, unmodified; nothing here
 * redraws the mark or sets the name in a font. Arabic reports get the Arabic lockup, every other
 * locale the English one.
 */
final class ProductLockup
{
    public static function dataUri(string $locale): string
    {
        $file = resource_path('brand/lockup-'.($locale === 'ar' ? 'ar' : 'en').'-dark.png');

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($file));
    }
}
