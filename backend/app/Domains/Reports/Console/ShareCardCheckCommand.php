<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Campaigns\Support\DrawableImage;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use Illuminate\Console\Command;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * SHARE-PREVIEW-CARD-001 — the picture a pasted link actually produces, on the running install.
 *
 * ## Why the unit suites cannot answer this
 *
 * They prove the card composes and that the route serves it — on a machine with a browser that the
 * test itself configured. What has never been asked is whether the INSTALL can do it: the renderer
 * flag, the Chromium binary, the Arabic face, the cache disk and the crawler's own path through the
 * metadata document are all properties of a box, not of a test. A card that cannot be drawn in
 * production is a link that previews as bare text in every chat it is forwarded to, and nothing
 * inside the product would ever show that — the product never fetches its own preview image.
 *
 * ## The link is TEMPORARY and is revoked before this returns
 *
 * A share token is a credential: anyone holding it opens the report without signing in. So one is
 * minted for this check, used entirely inside this process, and revoked in a `finally` — including
 * when an assertion throws. It is never printed, never logged, and never leaves the box.
 *
 * The report is the newest one that already has a completed export, so this measures the same
 * document the PDF acceptance beside it measured, rather than inventing a second subject.
 *
 * ## What it prints
 *
 * Assertions and sizes. Never the token, never the url, never the client's name — the card carries
 * an identity and a period, and a diagnostic log is read by people who are not that client.
 */
final class ShareCardCheckCommand extends Command
{
    protected $signature = 'reports:share-card-check';

    protected $description
        = 'Mint a temporary share link, fetch the preview card a crawler would, measure it, and revoke the link.';

    public function handle(ShareService $shares): int
    {
        $report = Report::withoutGlobalScopes()
            ->whereNotNull('project_id')
            ->orderByDesc('created_at')
            ->first();

        if ($report === null) {
            $this->error('No report on this install to share.');

            return self::FAILURE;
        }

        $share = null;

        try {
            [$share, $token] = $shares->create($report, [
                'scope' => ['project_id' => $report->project_id],
                'mode' => 'snapshot',
            ], null);

            return $this->measure($token);
        } catch (Throwable $e) {
            $this->error('The card check could not run: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            /*
             * Revoked whatever happened. A credential minted by a diagnostic and left behind is a
             * diagnostic that widened the attack surface it was written to reassure somebody about.
             */
            if ($share instanceof ReportShare) {
                $share->forceFill(['revoked_at' => now()])->save();
                $this->line('  ↺ the temporary link was revoked');
            }
        }
    }

    /** Drive the crawler's own two steps: read the document, then fetch the picture it names. */
    private function measure(string $token): int
    {
        $document = app()->handle(Request::create('/r/'.$token, 'GET', server: [
            // The edge sends only crawler agents to the backend; this is the one a card is made for.
            'HTTP_USER_AGENT' => 'facebookexternalhit/1.1',
        ]));

        $html = (string) $document->getContent();

        preg_match('/<meta property="og:image" content="([^"]*)"/', $html, $m);
        $image = html_entity_decode($m[1] ?? '');

        $checks = [
            'the crawler document is served' => $document->getStatusCode() === 200,
            'it names a picture' => $image !== '',
            /*
             * A FIGURE, not any run of digits.
             *
             * The first spelling was `\d{4,}` and it failed on the card's own PERIOD — «2026-07-01»
             * is four digits and is exactly what this document is supposed to say. A money figure is
             * thousands-grouped or carries a currency, and that is what must never reach a picture a
             * third party caches and shows to everyone in a forwarded chat.
             *
             * The specific-figure rule is held properly by `SharePreviewMetadataTest`, which hunts
             * the seeded spend and revenue by value. This is the coarse net for the running install,
             * and a coarse net that catches a date is one nobody keeps.
             */
            'it carries no money figure' => preg_match('/\d{1,3}(,\d{3})+|\b(SAR|USD|ر\.س)\b/u', strip_tags($html)) !== 1,
        ];

        $bytes = '';
        $size = null;

        if ($image !== '') {
            $picture = app()->handle(Request::create((string) parse_url($image, PHP_URL_PATH), 'GET'));
            $bytes = $this->bodyOf($picture);
            $size = $bytes === '' ? null : @getimagesizefromstring($bytes);

            $checks += [
                'the picture is served' => $picture->getStatusCode() === 200,
                'it is a png by its BYTES, not its header' => DrawableImage::sniff($bytes) === 'png',
                'a browser would draw it' => DrawableImage::draws($bytes),
                'it is the size the document promised' => is_array($size)
                    && $size[0] === (int) config('reports.og.width')
                    && $size[1] === (int) config('reports.og.height'),
                'it may be cached publicly' => str_contains((string) $picture->headers->get('Cache-Control'), 'public'),
            ];
        }

        foreach ($checks as $name => $ok) {
            $this->line(sprintf('  %s %s', $ok ? '✅' : '❌', $name));
        }

        $this->newLine();
        $this->line(sprintf('  card bytes        %d', strlen($bytes)));
        $this->line(sprintf('  dimensions        %s', is_array($size) ? $size[0].'x'.$size[1] : '—'));

        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));

        if ($failed !== []) {
            $this->newLine();
            $this->error('The preview a chat client would render failed: '.implode('; ', $failed));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('A pasted link produces a real branded card on this install.');

        return self::SUCCESS;
    }

    private function bodyOf(mixed $response): string
    {
        if (method_exists($response, 'getFile')) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
