<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Http\Controllers;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Http\Controllers\Controller;
use App\Support\Frontend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
 */
final class ShortLinkHopController extends Controller
{
    public function __construct(private readonly ShortLinkHops $hops) {}

    /**
     * One behaviour for everybody — SHORT-LINK-COMPLIANCE-001.
     *
     * No user-agent, header, IP or referrer is read: a person, an ad platform's review bot and a
     * link preview all receive the same single, server-side response. `redirect` mode is one 302 to
     * the stored https destination; `landing` mode is one page that states the destination and offers
     * it as a button. There is no chain, no script redirect and no second destination.
     */
    public function redirect(Request $request, string $slug): RedirectResponse|Response
    {
        $link = $this->hops->resolveAndCount($slug, $request);

        if ($link !== null && $link->mode === ShortLink::MODE_LANDING) {
            return response()
                ->view('short-links.landing', [
                    'destination' => (string) $link->destination,
                    'host' => (string) parse_url((string) $link->destination, PHP_URL_HOST),
                    'whatsapp' => $link->kind === ShortLink::KIND_WHATSAPP,
                ])
                ->header('X-Robots-Tag', 'noindex');
        }

        return redirect()->away($link?->destination ?: Frontend::origin().'/', 302);
    }
}
