<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Http\Controllers;

use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Http\Controllers\Controller;
use App\Support\Frontend;
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
 */
final class ShortLinkHopController extends Controller
{
    public function __construct(private readonly ShortLinkHops $hops) {}

    public function redirect(string $slug): RedirectResponse
    {
        $link = $this->hops->resolveAndCount($slug);

        return redirect()->away($link?->destination ?: Frontend::origin().'/', 302);
    }
}
