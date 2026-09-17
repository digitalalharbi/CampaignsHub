<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ReportIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
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
    public function __construct(private readonly ShareService $shares) {}

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
            'image' => $identity['logo_url'],
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
