<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Services;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Models\ShortLinkHop;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Support\Frontend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
/**
 * SHORT-LINKS-001 — minting a slug, and serving the hop.
 *
 * Mirrors `InfluencerAttribution::resolveAndCount()` deliberately rather than sharing it: that one
 * resolves a tracking asset bound to a deliverable and carries redemptions and a discount, none of
 * which a short link has. What IS copied is the part worth copying — the tenant scope removed for a
 * stranger's request, the active check done here rather than assumed, and an atomic increment.
 */
use Throwable;

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

        $now = Carbon::now();

        ShortLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($link->getKey())
            ->update(['clicks' => DB::raw('clicks + 1'), 'last_clicked_at' => $now]);

        $this->record($link, $now);

        return $link;
    }

    /**
     * SHORT-LINK-HOPS-001 — the moment, so the counter above can be read as a curve.
     *
     * AFTER the increment and unable to undo it. The counter is the authoritative total and this is
     * best-effort history: if this insert fails, a click has still been counted and the person is
     * still redirected. The opposite order — or a transaction spanning both — would make a database
     * hiccup in a history table cost a real click, or worse, cost somebody their redirect.
     *
     * `withoutGlobalScope` for the same reason the lookup above needs it: a stranger following a
     * link carries no tenant, so the tenant is taken from the LINK, which is the only authority for
     * it here.
     */
    private function record(ShortLink $link, Carbon $at): void
    {
        try {
            ShortLinkHop::query()->withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $link->tenant_id,
                'short_link_id' => $link->getKey(),
                'occurred_at' => $at,
            ]);
        } catch (Throwable $e) {
            /*
             * Logged and swallowed. A redirect that 500s because a history row could not be written
             * is a broken link in somebody's ad; a missing row is a gap in a curve that the surfaces
             * already have to describe honestly, because every link older than this table has one.
             */
            Log::warning('short link hop not recorded', [
                'short_link_id' => (string) $link->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
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
