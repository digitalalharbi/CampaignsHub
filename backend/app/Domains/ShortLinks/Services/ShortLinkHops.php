<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Services;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Support\Frontend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SHORT-LINKS-001 — minting a slug, and serving the hop.
 *
 * Mirrors `InfluencerAttribution::resolveAndCount()` deliberately rather than sharing it: that one
 * resolves a tracking asset bound to a deliverable and carries redemptions and a discount, none of
 * which a short link has. What IS copied is the part worth copying — the tenant scope removed for a
 * stranger's request, the active check done here rather than assumed, and an atomic increment.
 */
final class ShortLinkHops
{
    /**
     * No `0`, `O`, `1`, `l` or `I`.
     *
     * The owner's stated case is a link somebody reads off a screen and types, or dictates. Two
     * characters that look identical in a sans-serif font are a support conversation, and the
     * alphabet costs nothing to narrow.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const LENGTH = 7;

    /** How many collisions to ride out before giving up rather than looping forever. */
    private const ATTEMPTS = 8;

    public function mint(): string
    {
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $slug = '';

            for ($c = 0; $c < self::LENGTH; $c++) {
                $slug .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            /*
             * Checked without the tenant scope, because the column is unique GLOBALLY: a slug taken
             * by another tenant is taken, and a scoped check would report it free and then fail on
             * the insert.
             */
            $taken = ShortLink::query()->withoutGlobalScope(TenantScope::class)->where('slug', $slug)->exists();

            if (! $taken) {
                return $slug;
            }
        }

        // Thirty-one to the seventh is large enough that this is a signal, not a stroke of bad luck.
        throw new \RuntimeException('Could not mint an unused short-link slug.');
    }

    /**
     * The link a slug names, counted as followed — or null, which the route renders as a miss.
     *
     * The increment is atomic rather than read-modify-write: this is the one endpoint here that can
     * be hit hard and concurrently, and a lost click is a number somebody reads as a result.
     */
    public function resolveAndCount(string $slug): ?ShortLink
    {
        $link = ShortLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($link === null) {
            return null;
        }

        ShortLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($link->getKey())
            ->update(['clicks' => DB::raw('clicks + 1'), 'last_clicked_at' => Carbon::now()]);

        return $link;
    }

    /**
     * The address a person copies — the SPA origin, which is what the owner specified.
     *
     * `https://campaignshub.io/l/{slug}`, not the API host. Built from `Frontend::origin()` rather
     * than `config('app.url')` for that reason: the two are different hosts in production, and a
     * short link is the one URL in this product that gets read aloud and pasted into a message. The
     * customer-facing name is the only one that belongs in it.
     *
     * **This depends on the edge.** The hop is a Laravel WEB route and the SPA host answers every
     * unmatched path with `index.html`, so `/l/` has to be sent to the backend there — see the
     * `location /l/` block in `deploy/nginx-spa.conf`, which exists for exactly this and is the one
     * part of this feature that is not deployed by pushing code.
     */
    public function shareUrl(ShortLink $link): string
    {
        return Frontend::origin().'/l/'.$link->slug;
    }

    /** @return string the slug pattern the route accepts, kept beside the alphabet that produces it */
    public static function slugPattern(): string
    {
        return '[a-z2-9]{'.self::LENGTH.'}';
    }
}
