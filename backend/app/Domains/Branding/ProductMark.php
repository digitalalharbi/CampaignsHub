<?php

declare(strict_types=1);

namespace App\Domains\Branding;

/**
 * SHARE-PREVIEW-MARK-001 — the CampaignsHub symbol, as a data URI a card can draw.
 *
 * The same geometry as `frontend/src/components/brand/CampaignsHubMark.tsx` (three paths converging
 * on one point, gold dot at the junction), in the fixed colours the dark card uses. It is the card's
 * ONLY fallback when a client has no logo: not the agency's, which identifies the company that
 * presents the report inside it and is not what a pasted link should wear.
 */
final class ProductMark
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
        .'<g fill="none" stroke="#f7f5f0" stroke-width="6" stroke-linecap="round">'
        .'<path d="M8 14 H24 C34 14 34 32 42 32"/><path d="M8 32 H42"/><path d="M8 50 H24 C34 50 34 32 42 32"/>'
        .'</g><circle cx="52" cy="32" r="7" fill="#e8a33d"/></svg>';

    public static function dataUri(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::SVG);
    }
}
