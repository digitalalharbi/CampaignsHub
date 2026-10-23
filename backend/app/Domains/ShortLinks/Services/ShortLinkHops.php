<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Services;

use App\Domains\Metrics\Touchpoints\TouchpointLinker;
use App\Domains\Metrics\Touchpoints\TouchpointRecorder;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Models\ShortLinkHop;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Domains\Tracking\Services\TrackingRecorder;
use App\Support\Frontend;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
/**
 * SHORT-LINKS-001 — minting a slug, and serving the hop.
 *
 * Mirrors `InfluencerAttribution::resolveAndCount()` deliberately rather than sharing it: that one
 * resolves a tracking asset bound to a deliverable and carries redemptions and a discount, none of
 * which a short link has. What IS copied is the part worth copying — the tenant scope removed for a
 * stranger's request, the active check done here rather than assumed, and an atomic increment.
 */
use Illuminate\Validation\ValidationException;
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

    /**
     * SMART-LINK-001 — a CHOSEN slug: 3–32 characters of lowercase letters, digits and single hyphens,
     * never starting or ending with a hyphen. Wider than the minted alphabet on purpose: a chosen
     * slug is read off a brief, not dictated, so `0` and `l` are the person's own decision.
     */
    public const CUSTOM_SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])?$/';

    /** Slugs that name a route of ours or would read as one. */
    private const RESERVED = ['api', 'l', 'r', 'admin', 'app', 'agency', 'portal', 'login', 'logout', 'register', 'static', 'assets'];

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
     * SMART-LINK-001 — a slug the person chose, normalised and checked, or a refusal naming why.
     *
     * Checked without the tenant scope, like minting: the column is unique globally, and a slug held
     * by another tenant is held. The refusal says «taken», never whose — that would disclose another
     * tenant's link.
     *
     * @throws ValidationException
     */
    public function chosen(string $slug): string
    {
        $slug = mb_strtolower(trim($slug));

        if (preg_match(self::CUSTOM_SLUG, $slug) !== 1 || str_contains($slug, '--')) {
            throw ValidationException::withMessages([
                'slug' => [__('A custom slug is 3–32 lowercase letters, digits or single hyphens.')],
            ]);
        }

        if (in_array($slug, self::RESERVED, true)) {
            throw ValidationException::withMessages(['slug' => [__('That slug is reserved.')]]);
        }

        $taken = ShortLink::query()->withoutGlobalScope(TenantScope::class)->withTrashed()->where('slug', $slug)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['slug' => [__('That slug is already taken.')]]);
        }

        return $slug;
    }

    /**
     * The link a slug names, counted as followed — or null, which the route renders as a miss.
     *
     * The increment is atomic rather than read-modify-write: this is the one endpoint here that can
     * be hit hard and concurrently, and a lost click is a number somebody reads as a result.
     */
    public function resolveAndCount(string $slug, ?Request $request = null): ?ShortLink
    {
        $link = ShortLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        // SMART-LINK-001 — an expired link is a miss, exactly like a disabled one: no count, no hop.
        if ($link === null || $link->isExpired()) {
            return null;
        }

        $now = Carbon::now();

        ShortLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($link->getKey())
            ->update(['clicks' => DB::raw('clicks + 1'), 'last_clicked_at' => $now]);

        $this->record($link, $now);

        /*
         * TRACKING-EVENTS-001 — the same open, as a measured event. Written AFTER the response is decided and
         * never able to change it: the counters above are what they always were, and this adds the evidence a
         * count cannot hold (when, whether a person, which destination). Off unless the workspace turned
         * advanced tracking on, and a failure here is swallowed by the recorder.
         */
        $event = app(TrackingRecorder::class)->record([
            'tenant_id' => (string) $link->tenant_id,
            'project_id' => $link->project_id === null ? null : (string) $link->project_id,
            'event_type' => $link->mode === ShortLink::MODE_LANDING ? TrackingEvent::SHORT_LINK_OPEN : TrackingEvent::SHORT_LINK_REDIRECT,
            'subject_type' => 'short_link',
            'subject_id' => (string) $link->getKey(),
            'source' => 'short_link',
            'destination_type' => $link->kind === ShortLink::KIND_WHATSAPP ? 'whatsapp' : 'external_url',
            'destination_host' => (string) parse_url((string) $link->destination, PHP_URL_HOST),
            'occurred_at' => $now,
            'consent_state' => 'not_required',
        ], $request);

        if ($event !== null && ! $event->is_bot) {
            $this->touchpoint($link, $event, $now);
        }

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
    /**
     * TOUCHPOINT-EVIDENCE-001 — a person's click on a plain link becomes marketing evidence an order can later prove.
     *
     * Only for a PLAIN link (not WhatsApp: a chat message cannot carry anything to a store), in redirect mode, with
     * a project, and only for a click that did not announce itself as a machine. The click gets a token minted
     * here and written into the destination as `ch_tid` (and `utm_content` when the link has none), so that if the
     * store later records an order carrying that token, the order can be linked to this click by evidence nobody
     * else could have produced. The destination host and path are untouched: only a query parameter is added, in
     * the open, which is ordinary campaign tagging and not a different place.
     *
     * The response a visitor receives never depends on who they are — a machine gets the same redirect, only
     * without a token, because it is not recorded as a person's click.
     */
    private function touchpoint(ShortLink $link, TrackingEvent $event, Carbon $at): void
    {
        if ($link->kind !== ShortLink::KIND_LINK || $link->mode !== ShortLink::MODE_REDIRECT || $link->project_id === null) {
            return;
        }

        try {
            $token = Str::lower(Str::random(20));
            app(TouchpointRecorder::class)->record((string) $link->tenant_id, (string) $link->project_id, [
                'source_type' => 'smart_link_click',
                'source_ref' => (string) $event->getKey(),
                'channel' => 'smart_link',
                'smart_link_id' => (string) $link->getKey(),
                'tracking_token' => $token,
                'occurred_at' => $at,
                'consent_state' => 'not_required',
            ]);
            $link->setAttribute('tracked_destination', self::withToken((string) $link->destination, $token));
        } catch (Throwable $e) {
            Log::warning('smart link touchpoint not recorded', ['short_link_id' => (string) $link->getKey(), 'reason' => $e->getMessage()]);
        }
    }

    /** The destination with our click token added to its query, everything else as it was. */
    public static function withToken(string $destination, string $token): string
    {
        $parts = parse_url($destination);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            return $destination;
        }

        parse_str($parts['query'] ?? '', $query);
        $query[TouchpointLinker::TOKEN_PARAM] = $token;
        $query['utm_content'] ??= $token;

        $out = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');

        return $out.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function shareUrl(ShortLink $link): string
    {
        return Frontend::origin().'/l/'.$link->slug;
    }

    /**
     * BIO-PAGE-001 — what the bio view draws: the title, the subtitle, and the buttons whose links are
     * still live (active, not expired, not deleted, same tenant). A button whose link went away is
     * not drawn — a dead button on a page people reach from a profile is worse than a shorter page.
     *
     * @return array{title: string, subtitle: ?string, buttons: list<array{label: string, href: string, whatsapp: bool}>}
     */
    public function bioPage(ShortLink $page): array
    {
        $bio = is_array($page->bio) ? $page->bio : [];
        $wanted = array_values(array_filter(array_map(static fn ($b) => is_array($b) ? (string) ($b['short_link_id'] ?? '') : '', $bio['buttons'] ?? [])));
        $links = $wanted === [] ? collect() : ShortLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $page->tenant_id)
            ->whereIn('id', $wanted)
            ->where('is_active', true)
            ->where('kind', '!=', ShortLink::KIND_BIO)
            ->get()
            ->filter(fn (ShortLink $l): bool => ! $l->isExpired())
            ->keyBy(fn (ShortLink $l): string => (string) $l->getKey());

        $buttons = [];
        foreach ($bio['buttons'] ?? [] as $b) {
            $link = is_array($b) ? $links->get((string) ($b['short_link_id'] ?? '')) : null;
            if ($link === null) {
                continue;
            }
            $buttons[] = [
                'label' => trim((string) ($b['label'] ?? '')) ?: ($link->kind === ShortLink::KIND_WHATSAPP ? 'واتساب' : (string) parse_url((string) $link->destination, PHP_URL_HOST)),
                'href' => $this->shareUrl($link),
                'whatsapp' => $link->kind === ShortLink::KIND_WHATSAPP,
            ];
        }

        return [
            'title' => trim((string) ($bio['title'] ?? '')) ?: 'روابطنا',
            'subtitle' => trim((string) ($bio['subtitle'] ?? '')) ?: null,
            'buttons' => $buttons,
        ];
    }

    /** @return string the slug pattern the route accepts, kept beside the alphabet that produces it */
    public static function slugPattern(): string
    {
        // Minted slugs (7 of the narrow alphabet) and chosen ones (SMART-LINK-001) both match.
        return '[a-z0-9][a-z0-9-]{2,31}';
    }
}
