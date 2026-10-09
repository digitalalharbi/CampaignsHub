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

        /*
         * DRAW IT HERE, so the tag is a picture we HAVE rather than one we hope to make.
         *
         * `available()` answers «is the renderer switched on», which is not the same promise as «this
         * url will return an image». An install with the flag on and no working browser advertised
         * `/r/{token}/preview.png` and answered 404 — and `SharedReportCrawlerMetadataTest` caught
         * exactly that, because a crawler fetches this from its own servers and caches whatever comes
         * back. A broken card then follows the link into every chat it is forwarded to, and nothing
         * inside this product would ever show it: the product never fetches its own preview image.
         *
         * So the card is composed while the crawler is reading the document, and the tag names it
         * only if it exists. The cost is one render on the first crawl of a link — every fetch after
         * that is served from the cache — and a crawler waits seconds for HTML anyway.
         *
         * When it cannot be drawn, the agency's MARK is offered instead. A mark is not a preview
         * image, which is why this card exists; but a real small picture is better than a promise
         * that 404s, and `largeImage` below keeps it out of the layout that would crop it.
         */
        $drawn = $this->cards->png($share, $report);

        return view('reports.share-preview', [
            // The report's own language, and its title ends with the product's name in it (REPORT BRANDING).
            'lang' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            /*
             * SHARE-PREVIEW-CLIENT-IDENTITY-001 — the CLIENT leads the card, not the product.
             *
             * This was `ReportIdentity::pageTitle()`, which appends the product's name — correct for
             * a BROWSER TAB, where it is what tells two open report links apart, and wrong here. The
             * owner's instruction is explicit: «Do NOT use CampaignsHub as the primary report-card
             * identity when the Client has a logo», and a WhatsApp card renders this line first and
             * largest. A client's first sight of their own report read «… — كامبينز هب».
             *
             * So where the identity resolved to somebody — a client, or the agency on a link that
             * has no client — that name leads and the report's own name follows it. The product's
             * name is still on the card, in `og:site_name`, which is where a platform belongs.
             *
             * Where nothing resolved, the identity IS the product and the old title stands: a card
             * reading «CampaignsHub — CampaignsHub» would be the «Nakheel, by Nakheel» bug the
             * resolver already avoids one field down.
             */
            'title' => $this->cardTitle($report, $identity),
            /*
             * The period and nothing else. It is what makes the card useful in a chat — «which
             * report is this?» — and it is already implied by the link the sender chose to send.
             */
            'description' => $this->cardDescription($report, $who, $period, $identity),
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
            /*
             * SHARE-PREVIEW-VERSION-001 — the picture's address changes when the picture does.
             *
             * WhatsApp and the rest cache a preview by URL and hold it, so replacing or removing a
             * client's mark left the OLD card attached to every chat the link had been pasted into.
             * The canonical `/r/{token}` is untouched — that is the link people hold — and the
             * version rides on the image URL, derived from the same key the card is cached under.
             */
            'image' => $drawn !== null
                ? url("/r/{$token}/preview.png").'?v='.$this->cards->version($share, $report)
                : $identity['logo_url'],
            // The LARGE layout only for a picture composed for it. A mark under
            // `summary_large_image` is the crop this card was built to stop.
            'largeImage' => $drawn !== null,
            // From the same config the renderer draws at, so the declared size cannot drift from the file served.
            'imageWidth' => (int) config('reports.og.width', 1200),
            'imageHeight' => (int) config('reports.og.height', 630),
        ]);
    }

    /**
     * The card's headline: whose report this is, then which report.
     *
     * @param  array<string, mixed>  $identity
     */
    private function cardTitle(Report $report, array $identity): string
    {
        $who = trim((string) ($identity['name'] ?? ''));
        $product = ReportIdentity::productName($report->reportLocale());

        /*
         * Nothing resolved, so the identity IS the product — keep the browser-tab form rather than
         * printing the product's name twice.
         */
        if ($who === '' || $who === $product) {
            return ReportIdentity::pageTitle($report);
        }

        $name = trim((string) ($report->name ?? ''));

        if ($name === '') {
            $name = $report->reportLocale() === 'en' ? 'Performance report' : 'تقرير الأداء';
        }

        return "{$who} — {$name}";
    }

    /**
     * The second line: the period, and the preparing company as SECONDARY attribution.
     *
     * `by` is already «the agency, under a client's name» — absent when the two identities are one,
     * because «Nakheel, by Nakheel» reads as a bug. The client's own name is no longer repeated
     * here: it now leads the title, and a card that said it twice would spend its second line
     * saying nothing.
     *
     * Still no figures. Spend and revenue would be visible to everyone the message reaches,
     * including a group the client forwarded it into — the rule this whole card was built under.
     *
     * @param  array<string, mixed>  $identity
     */
    private function cardDescription(Report $report, string $who, ?string $period, array $identity): string
    {
        $by = trim((string) ($identity['by'] ?? ''));
        $leads = trim((string) ($identity['name'] ?? '')) !== '' && $this->titleCarriesIdentity($identity);

        $parts = array_values(array_filter([
            $leads ? null : $who,
            $period,
            /*
             * REPORT-IDENTITY-001's own words — «من إعداد», not «بواسطة».
             *
             * «بواسطة» is a byline; this is a report somebody PREPARED for somebody else. The
             * public report's header already says it this way, and a card that said it differently
             * would be a second phrasing for one relationship. Composed here rather than through a
             * translation key because this domain has no `reports` language file and inventing one
             * for a single string is how a vocabulary ends up in two places.
             */
            $by === '' ? null : ($report->reportLocale() === 'en' ? "Prepared by {$by}" : "من إعداد {$by}"),
        ], static fn (?string $p): bool => $p !== null && trim($p) !== ''));

        return $parts === [] ? $who : implode(' · ', $parts);
    }

    /** Whether {@see cardTitle} put the identity's name at the front. */
    private function titleCarriesIdentity(array $identity): bool
    {
        $who = trim((string) ($identity['name'] ?? ''));

        return $who !== '' && $who !== ReportIdentity::productName('ar') && $who !== ReportIdentity::productName('en');
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
