<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Http\Controllers;

use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Http\Controllers\Controller;
use App\Support\Frontend;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * SHORT-LINKS-001 — the hop itself, served by the platform.
 *
 * A WEB route with no session, no tenant and no JSON: it is followed by a stranger from a WhatsApp
 * message or a printed page. Serving it ourselves is also what makes the click count honest — the
 * number beside a link is something this platform measured rather than something somebody typed in.
 *
 * A slug nobody minted sends the reader to the site rather than to an error page. They did not
 * mistype a URL of ours on purpose, and a 404 in a browser opened from a chat message is a dead end
 * with nothing on it; the home page at least says whose link it was.
 *
 * ## SHORT-LINKS-LANDING-001 — and some links open a page first
 *
 * A link in a paid ad has to lead somewhere a person can read. Forwarding straight into WhatsApp
 * gives the reader nothing to decide from and an ad reviewer nothing to review: the destination is
 * an app handoff with no offer on it, which is the shape of a cloaked link whether or not it is one.
 *
 * So a link may name a LANDING PAGE, and then the hop renders it. Three properties make that
 * honest rather than a slower redirect, and all three are deliberate:
 *
 *   - the page does not forward. There is no meta refresh, no timer and no script that leaves; the
 *     reader presses the button or they do not;
 *   - everybody gets the same page. Nothing here reads the user agent or the referrer, so an ad
 *     reviewer and a customer see the identical document;
 *   - the destination is the link's own. The button goes to the same WhatsApp address the slug has
 *     always resolved to — the page is a step in front of it, not a different answer.
 */
final class ShortLinkHopController extends Controller
{
    public function __construct(private readonly ShortLinkHops $hops) {}

    public function redirect(string $slug): RedirectResponse|View
    {
        $link = $this->hops->resolveAndCount($slug);

        if ($link !== null && $link->opensLandingPage()) {
            return view('links.video-request', [
                'slug' => $link->slug,
                /* The CTA posts through this product, so the decision can be counted apart. */
                'ctaUrl' => url('/l/'.$link->slug.'/go'),
            ]);
        }

        return redirect()->away($link?->destination ?: Frontend::origin().'/', 302);
    }

    /**
     * The reader pressed the button.
     *
     * Counted as a DECISION rather than as a second visit, then forwarded to the link's own
     * destination. A slug with no landing page still works here — it simply forwards, which is what
     * it would have done anyway — so a stale bookmark of this path is never a dead end.
     */
    public function go(string $slug): RedirectResponse
    {
        $link = $this->hops->resolve($slug);

        if ($link !== null) {
            $this->hops->countCtaClick($link);
        }

        return redirect()->away($link?->destination ?: Frontend::origin().'/', 302);
    }
}
