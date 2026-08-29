<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
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
        $name = trim((string) ($report->name ?? '')) !== '' ? (string) $report->name : __('Performance report');

        return view('reports.share-preview', [
            'lang' => 'ar',
            'dir' => 'rtl',
            'title' => "{$name} — {$who}",
            /*
             * The period and nothing else. It is what makes the card useful in a chat — «which
             * report is this?» — and it is already implied by the link the sender chose to send.
             */
            'description' => $period === null ? $who : "{$who} · {$period}",
            'siteName' => config('app.name', 'CampaignsHub'),
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
