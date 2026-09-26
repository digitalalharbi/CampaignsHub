<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareCardRenderer;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ReportIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * REPORT-TITLE-METADATA-001 — crawler-visible metadata for a shared report link.
 *
 * A client link is pasted into WhatsApp far more often than it is typed into a browser, and the
 * preview card is the first thing anybody sees of it. Every one of them showed the product's
 * marketing line, because the SPA's static `index.html` is what a crawler receives and none of
 * WhatsApp, X, LinkedIn, Slack or Telegram runs the React that would have set the real title.
 *
 * Three rules this must not break:
 *
 *   1. **No figures.** A preview is rendered by a third party, cached by them, and shown to everyone
 *      who can see the message — including a group the client forwarded it into. Spend and revenue
 *      have no business in it, and a password-gated link must not preview what the password protects.
 *   2. **An invalid link previews as nothing.** A 404 rather than a card describing a report that
 *      may have been revoked. Saying «this was Nakheel's July report» to somebody holding a dead
 *      token is a disclosure, however small.
 *   3. **The identity comes from the same resolver as everything else** — `SharedLinkBranding` —
 *      so a preview card cannot disagree with the header the same link opens.
 */
final class SharePreviewController extends Controller
{
    public function __construct(
        private readonly ShareService $shares,
        private readonly ShareCardRenderer $cards,
    ) {}

    public function show(string $token, SharedLinkBranding $branding): View
    {
        $share = $this->shares->resolveActive($token);
        abort_if($share === null, 404);

        $report = Report::withoutGlobalScopes()->find($share->report_id);
        abort_if($report === null, 404);

        $identity = $branding->forShare($share, $token, $report);
        $who = $identity['name'];

        $period = $this->period($report);
        $locale = $report->reportLocale();

        return view('reports.share-preview', [
            // The report's own language, and its title ends with the product's name in it (REPORT BRANDING).
            'lang' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => ReportIdentity::pageTitle($report),
            /*
             * The period and nothing else. It is what makes the card useful in a chat — «which
             * report is this?» — and it is already implied by the link the sender chose to send.
             */
            'description' => $period === null ? $who : "{$who} · {$period}",
            /*
             * BRAND-CANONICAL-001 — `brand.name`, and not the framework's name key.
             *
             * Two configuration keys held the product's identity and this card read the wrong one.
             * The framework's own name key defaults to «Laravel» and is what `MAIL_FROM_NAME` and
             * `VITE_APP_NAME` interpolate — a framework value that happens to be set correctly in
             * every env template we ship. `brand.name` is the product's, and its whole docblock is
             * «change here (or via env) — never hard-code the name in code». The other key is not
             * spelled out here: `BrandIdentitySourceTest` sweeps for the call, and a note quoting it
             * trips the guard it exists to explain.
             *
             * Nothing a reader sees changes today, because both say «CampaignsHub». What changes is
             * that there is one source: an install that renamed the brand renamed this card with it,
             * and one that never set `APP_NAME` no longer sends «Laravel» to WhatsApp.
             */
            'siteName' => ReportIdentity::productName($locale),
            'url' => url("/r/{$token}"),
            /*
             * SHARE-PREVIEW-CARD-001 — the DRAWN card, or no picture at all.
             *
             * This was `$identity['logo_url']`, and a mark is not a preview image. It is square or
             * tall, often transparent, often a few hundred pixels — and the template below declared
             * `summary_large_image` for it, which is the layout that crops hardest. A client's first
             * sight of the product was a stretched logo or an empty rectangle.
             *
             * The route draws on demand and caches, so this stays a URL rather than a render: the
             * crawler reads this document first and fetches the picture after, and holding the HTML
             * open for a browser launch is how a crawler times out and shows nothing at all.
             *
             * `available()` rather than a drawn card, for the same reason. When the renderer is off
             * there is no picture and the card below says `summary` — an ordinary card, which is
             * better than a large one pointing at a dead URL.
             */
            'image' => $this->cards->available() ? url("/r/{$token}/preview.png") : null,
        ]);
    }

    /**
     * The preview card itself — a PNG, drawn once and then served from cache.
     *
     * Public and unauthenticated, exactly like the metadata above and under the same three rules: an
     * invalid token is a 404 rather than a picture describing a report that may have been revoked,
     * the card carries no figures, and its identity comes from the same resolver as the header the
     * link opens.
     *
     * A card that cannot be drawn is a 404 rather than a placeholder. The crawler then renders the
     * summary card it would have rendered anyway, and nobody is shown a picture of a report that
     * failed to compose.
     */
    public function image(string $token): Response
    {
        $share = $this->shares->resolveActive($token);
        abort_if($share === null, 404);

        $report = Report::withoutGlobalScopes()->find($share->report_id);
        abort_if($report === null, 404);

        $png = $this->cards->png($share, $report);
        abort_if($png === null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            /*
             * PUBLIC, deliberately — the one response on this link that is.
             *
             * Everything else a share serves is `private`, because it carries the report. This
             * carries an identity and a period and nothing else, and it is fetched by a crawler's
             * cache and then by every recipient's client. Marking it private means each of them
             * launches a browser on our server for a picture that is identical every time.
             */
            'Cache-Control' => 'public, max-age='.(int) config('reports.og.http_max_age', 86400),
            'Content-Length' => (string) strlen($png),
        ]);
    }

    private function period(Report $report): ?string
    {
        if ($report->period_start === null || $report->period_end === null) {
            return null;
        }

        return Carbon::parse($report->period_start)->toDateString().' — '.Carbon::parse($report->period_end)->toDateString();
    }
}
