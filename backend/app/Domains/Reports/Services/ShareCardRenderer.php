<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Campaigns\Support\DrawableImage;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\ReportIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * SHARE-PREVIEW-CARD-001 — the 1200×630 picture a shared report link renders as when it is pasted.
 *
 * ## The defect this closes
 *
 * `SharePreviewController` already serves crawler metadata, and its `og:image` was the agency's
 * LOGO — whatever file the agency uploaded to the Branding Center. Two things were wrong with that,
 * and the second is worse than the first.
 *
 * A logo is not a preview image. Marks are square or tall, frequently transparent, and often a few
 * hundred pixels; the card slot is 1200×630 on a dark chat bubble. WhatsApp letterboxes it, X
 * rejects anything under 300×157 outright, and a transparent PNG on a dark background can arrive as
 * an empty rectangle. And the template declared `twitter:card = summary_large_image` whenever ANY
 * image existed — so the one layout that crops hardest was the one a square mark was sent to.
 *
 * So the product's own link, the thing a client sees before they see anything else, previewed as a
 * stretched mark or a blank box.
 *
 * ## What it draws, and what it must never draw
 *
 * Whose report it is, and which period. Nothing else — the same two facts the text metadata beside
 * it carries, for the same reason: **a preview is rendered by a third party, cached by them, and
 * shown to everyone who can see the message**, including a group the client forwarded it into. Spend
 * and revenue have no business in it, and a password-gated link must not preview what the password
 * protects. {@see ShareCardHasNoFiguresTest}.
 *
 * ## Why headless Chromium and not an image library
 *
 * The card is bilingual, and the Arabic half has to JOIN. GD and Imagick draw one TTF glyph per
 * codepoint with no shaping and no bidi, so an Arabic client name comes out as disconnected letters
 * in the wrong order — the same class of defect the PDF text layer already cost this product two
 * round trips over. Chromium shapes, orders and kerns it because it is the engine the client's own
 * phone uses.
 *
 * It is the browser this product already runs, already installs in the production image, and already
 * proves present in CI — so this adds a use, not a dependency.
 *
 * ## Fail-closed, in the direction that matters here
 *
 * Every failure returns null and the caller omits `og:image` entirely, falling back to the plain
 * summary card. That is the deliberate direction: a card with no picture is ordinary, and a card
 * whose picture is a dead URL is a broken link inside the message that carries the report.
 */
final class ShareCardRenderer
{
    /**
     * A face that JOINS, first — then the system's own.
     *
     * The production image installs `font-noto-arabic` for exactly this, and the PDF pipeline already
     * embeds IBM Plex Sans Arabic. Either one shapes; the Latin fallbacks are only reached for a
     * Latin card, where any of them is correct.
     */
    private const FONT_STACK = "'IBM Plex Sans Arabic', 'Noto Sans Arabic', 'Noto Naskh Arabic', 'Segoe UI', system-ui, -apple-system, sans-serif";

    /** The product's own accent, for a link whose agency has configured no colour. */
    private const DEFAULT_ACCENT = '#2563eb';

    /**
     * Bumped when the DRAWING changes — the layout, the script, the font stack.
     *
     * It is part of the cache key, so a card already drawn for a link is superseded by a deploy that
     * changes how cards look. Without it, the first person to paste a link freezes its picture at
     * whatever the design was that week.
     */
    private const DESIGN_VERSION = 'v1';

    public function __construct(private readonly SharedLinkBranding $branding) {}

    /**
     * Whether a card can be drawn at all.
     *
     * One switch, the renderer's. A separate flag for cards would allow the single state that cannot
     * be diagnosed from outside: crawler metadata pointing at an image route the server has decided
     * not to answer.
     */
    public function available(): bool
    {
        return (bool) config('reports.chromium.enabled', false);
    }

    /**
     * The card's PNG bytes for this share, or null when one cannot be drawn.
     *
     * Cached by what it DRAWS rather than by the token: the same report, period, mark and colour
     * produce the same picture, and a crawler fetches it once per service and again for every
     * recipient who opens the message. Launching a browser per request would make a forwarded link a
     * load test.
     */
    public function png(ReportShare $share, Report $report): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $card = $this->contents($share, $report);
        $disk = Storage::disk((string) config('reports.og.cache_disk', 'local'));
        $path = trim((string) config('reports.og.cache_dir', 'share-previews'), '/').'/'.$this->key($card).'.png';

        if ($disk->exists($path)) {
            $cached = $disk->get($path);

            if (is_string($cached) && DrawableImage::draws($cached)) {
                return $cached;
            }

            // A truncated or half-written file is not a card. Drop it and draw again rather than
            // serving bytes a crawler would show as a broken image.
            $disk->delete($path);
        }

        $bytes = $this->draw($card);

        if ($bytes === null) {
            return null;
        }

        $disk->put($path, $bytes);

        return $bytes;
    }

    /**
     * Everything the card draws, resolved. Public so a test can read the card's CONTENT without a
     * browser — which is how «no figures» is asserted on the values rather than on the picture.
     *
     * @return array<string,mixed>
     */
    public function contents(ReportShare $share, Report $report): array
    {
        $identity = $this->branding->forShare($share, 'preview-card', $report);
        $locale = $report->reportLocale();
        $who = (string) ($identity['name'] ?? 'CampaignsHub');

        return [
            'lang' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'who' => $who,
            'period' => $this->period($report),
            'siteName' => ReportIdentity::productName($locale),
            'eyebrow' => $locale === 'ar' ? 'تقرير الأداء' : 'Performance report',
            'kicker' => $locale === 'ar' ? 'تقرير أداء الحملات المدفوعة' : 'Paid campaign performance',
            'accent' => $this->accent($share, $report),
            'fontStack' => self::FONT_STACK,
            // Measured on the resolved name, not guessed per language: an agency called «مجموعة
            // النخيل للتسويق الرقمي» and one called «Acme» cannot share a type size.
            'titleSize' => Str::length($who) > 26 ? 58 : 74,
            'logo' => $this->logoDataUri($share, $report),
        ];
    }

    /**
     * The brand accent as a colour this may safely interpolate into a stylesheet, or the product's.
     *
     * STRICTLY validated, and that is not a formality: the value lands inside a `<style>` block, and
     * Blade's escaping is for HTML — it leaves `{`, `}` and `;` alone, so a stored value of
     * `red; } body { display: none` would rewrite the card rather than colour it. A hex triple or
     * nothing.
     */
    private function accent(ReportShare $share, Report $report): string
    {
        $frozen = (array) (((array) ($report->config ?? []))['branding'] ?? []);
        $candidates = [
            $frozen['accent'] ?? null,
            $frozen['primary'] ?? null,
            ((array) ($frozen['colors'] ?? []))['primary'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($candidate)) === 1) {
                return strtolower(trim($candidate));
            }
        }

        return self::DEFAULT_ACCENT;
    }

    /**
     * The agency's mark as a data URI, or null.
     *
     * Inlined rather than linked. The card is opened from a temp file with no origin, so a linked
     * mark would be a network fetch from a document that has no business making one — and a slow or
     * refused fetch photographs as an empty box, which is worse than a card with no mark at all.
     *
     * `DrawableImage::draws()` decides, not the stored content type. Production has already shown
     * this product real PNGs served as `multipart/form-data`, and a browser draws those fine.
     */
    private function logoDataUri(ReportShare $share, Report $report): ?string
    {
        $bytes = $this->branding->logoBytes($report, (string) $share->tenant_id);

        if ($bytes === null) {
            return null;
        }

        $format = DrawableImage::sniff($bytes);

        if ($format === null || ! DrawableImage::draws($bytes)) {
            return null;
        }

        return 'data:image/'.$format.';base64,'.base64_encode($bytes);
    }

    private function period(Report $report): ?string
    {
        if ($report->period_start === null || $report->period_end === null) {
            return null;
        }

        return Carbon::parse($report->period_start)->toDateString().' — '.Carbon::parse($report->period_end)->toDateString();
    }

    /** @param array<string,mixed> $card */
    private function key(array $card): string
    {
        // The logo is hashed rather than keyed: a data URI of a real mark is hundreds of kilobytes,
        // and a cache key is a filename.
        $keyed = $card;
        $keyed['logo'] = $card['logo'] === null ? null : hash('sha256', (string) $card['logo']);
        $keyed['design'] = self::DESIGN_VERSION;
        $keyed['w'] = (int) config('reports.og.width', 1200);
        $keyed['h'] = (int) config('reports.og.height', 630);

        return hash('sha256', (string) json_encode($keyed));
    }

    /**
     * @param  array<string,mixed>  $card
     */
    private function draw(array $card): ?string
    {
        $width = (int) config('reports.og.width', 1200);
        $height = (int) config('reports.og.height', 630);
        $timeout = (int) config('reports.og.timeout_ms', 20000);

        $html = tempnam(sys_get_temp_dir(), 'ogcard_').'.html';
        $out = tempnam(sys_get_temp_dir(), 'ogcard_').'.png';

        try {
            file_put_contents($html, View::make('reports.og-card', $card)->render());

            $process = new Process(
                [
                    (string) config('reports.chromium.node_bin', 'node'),
                    (string) config('reports.og.script'),
                    (string) json_encode([
                        'html' => $html,
                        'out' => $out,
                        'width' => $width,
                        'height' => $height,
                        'timeoutMs' => $timeout,
                        'chromiumPath' => config('reports.chromium.chromium_path') ?: null,
                        'requireBase' => config('reports.chromium.require_base'),
                    ]),
                ],
                base_path(),
                ['NODE_ENV' => 'production'],
                null,
                $timeout / 1000 + 10,
            );
            $process->run();

            if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
                return null;
            }

            $bytes = (string) file_get_contents($out);

            // The same judgement the product applies to a provider's media: bytes a browser would
            // actually draw. A zero-byte or truncated screenshot is not a card.
            return DrawableImage::draws($bytes) ? $bytes : null;
        } finally {
            @unlink($html);
            @unlink($out);
        }
    }
}
