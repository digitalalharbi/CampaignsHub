<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\EmailSafeHtml;
use App\Domains\Notifications\Support\MailGallery;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EMAIL-CLIENT-COMPAT-001 — the constraint lint every message must pass, so it renders the same in the Gmail app on a non-Google
 * account (no `<style>`, partial inline CSS, forced dark inversion), Outlook (Word), Apple Mail and the browsers.
 *
 * Every gallery message (internal digests, alerts, spend summary, account messages) × ar/en, the seven external templates and the
 * subscription confirmation. What this proves is the constraint set; real-client rendering needs real mailboxes (see
 * docs/email-compat/EM-notes.md, the manual checklist) and the engine simulations in frontend/e2e-mail.
 */
final class EmailClientCompatTest extends TestCase
{
    /** CSS no email client honours reliably (in any style ATTRIBUTE; `<style>` is progressive only). */
    private const FORBIDDEN = [
        'display:flex/grid' => '/display\s*:\s*(inline-)?(flex|grid)/i',
        'gap' => '/(^|[;\s])(row-|column-)?gap\s*:/i',
        'position' => '/(^|[;\s])position\s*:/i',
        'float' => '/(^|[;\s])float\s*:/i',
        'var()' => '/var\(/i',
        'calc()' => '/calc\(/i',
        'rem/vw/vh units' => '/\d(rem|vw|vh)\b/i',
        'min-width/min-height' => '/min-(width|height)\s*:/i',
        'background shorthand' => '/(^|[;\s])background\s*:/i',
        'negative margin' => '/margin[a-z-]*\s*:\s*[^;]*-\d/i',
        'unitless line-height' => '/line-height\s*:\s*\d+(\.\d+)?\s*(;|$)/i',
        'colour inherit' => '/(^|[;\s])color\s*:\s*(inherit|currentcolor)/i',
    ];

    /** The 30-project × 6-platform stress fixtures: over Gmail's clip before this work (~177 KB) and still over it (see the notes). */
    private const STRESS = ['digest-projects-daily', 'digest-projects-weekly', 'digest-projects-monthly'];

    /** @return array<string, Mailable> every message this product sends, by preview name */
    public static function messages(): array
    {
        $out = [];
        foreach (['ar', 'en'] as $locale) {
            foreach (MailGallery::compatMessages($locale) as $key => $mail) {
                $out["{$key}.{$locale}"] = $mail;
            }
        }

        return $out;
    }

    public function test_every_message_passes_the_email_client_constraints(): void
    {
        $problems = [];
        $count = 0;
        foreach (self::messages() as $name => $mail) {
            $count++;
            foreach ($this->violations($mail->render(), $name) as $v) {
                $problems[] = "{$name}: {$v}";
            }
        }
        $this->assertGreaterThan(110, $count);
        $this->assertSame([], array_slice($problems, 0, 60), count($problems)." violations:\n".implode("\n", array_slice($problems, 0, 60)));
    }

    public function test_the_pass_is_deterministic_and_idempotent(): void
    {
        $html = (string) MailGallery::render('digest-portfolio', 'ar');
        $this->assertSame($html, (string) MailGallery::render('digest-portfolio', 'ar'));
        $this->assertSame($html, EmailSafeHtml::process($html), 'running the pass again changes nothing');
    }

    public function test_the_pass_inlines_style_rules_turns_margins_into_spacers_and_makes_every_cell_explicit(): void
    {
        $out = EmailSafeHtml::process('<!DOCTYPE html><html dir="rtl"><head><style>.x { color: #123456; } @media (max-width: 9px) { .x { color: #654321 !important; } }</style></head>'
            .'<body style="background-color:#f7f5f0;"><table width="100%"><tr><td style="font-size:20px; line-height:1.5;"><div class="x" style="margin:0 0 12px;">A</div><table width="100%" style="margin-top:-4px;"><tr><td>B</td></tr></table></td></tr></table></body></html>');
        $this->assertStringContainsString('class="x" style="margin:0 0 0 0; color:#123456;"', $out, 'a stylesheet rule outside @media is inlined; the @media one is not');
        $this->assertStringContainsString('@media (max-width: 9px)', $out, '<style> stays for progressive enhancement');
        $this->assertStringContainsString('data-spacer="8"', $out, '12px then -4px collapse to 8px, as a browser collapses them');
        $this->assertStringContainsString('line-height:30px', $out, '1.5 × 20px');
        $this->assertMatchesRegularExpression('/<td bgcolor="#f7f5f0"[^>]*>B<\/td>|<td[^>]*bgcolor="#f7f5f0"[^>]*style="[^"]*background-color:#f7f5f0;[^"]*color:#0b1a16;[^"]*"[^>]*>B</', $out);
        $this->assertStringContainsString('role="presentation"', $out);
        $this->assertStringContainsString('dir="rtl"', $out);
    }

    public function test_every_message_leaves_with_a_plain_text_part_and_the_same_safe_html(): void
    {
        config(['mail.default' => 'array']);
        $transport = app('mailer')->getSymfonyTransport();
        foreach (['digest-portfolio', 'alerts-bundle', 'spend-summary-weekly', 'account-password-reset'] as $key) {
            foreach (['ar', 'en'] as $locale) {
                Mail::to('compat@example.test')->send(MailGallery::messages($locale)[$key]);
                $sent = $transport->messages()->last()->getOriginalMessage();
                $this->assertNotSame('', trim((string) $sent->getTextBody()), "{$key}.{$locale} has a text part");
                $this->assertSame([], $this->violations((string) $sent->getHtmlBody(), "{$key}.{$locale}"), 'the sent HTML is the safe HTML');
            }
        }
    }

    /** @return list<string> */
    private function violations(string $html, string $name): array
    {
        $out = [];
        $key = explode('.', $name)[0];
        $limit = in_array($key, self::STRESS, true) ? 160 * 1024 : 100 * 1024;
        if (strlen($html) >= $limit) {
            $out[] = sprintf('%.1f KB, over the %d KB budget (Gmail clips at ~102 KB)', strlen($html) / 1024, $limit / 1024);
        }
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        if ($doc->querySelector('meta[name="color-scheme"]') === null || $doc->querySelector('meta[name="supported-color-schemes"]') === null) {
            $out[] = 'no color-scheme meta';
        }
        foreach ($doc->body?->querySelectorAll('*') ?? [] as $el) {
            $tag = strtolower($el->localName);
            $style = (string) $el->getAttribute('style');
            foreach (self::FORBIDDEN as $label => $re) {
                if (preg_match($re, $style) === 1) {
                    $out[] = "<{$tag}> uses {$label}: ".mb_substr($style, 0, 90);
                }
            }
            if (preg_match('/max-(width|height)\s*:/i', $style) === 1 && ! $this->allowedMax($el)) {
                $out[] = "<{$tag}> relies on max-width/max-height: ".mb_substr($style, 0, 90);
            }
            if ($el->hasAttribute('class') && $style === '') {
                $out[] = "<{$tag} class=\"{$el->getAttribute('class')}\"> has no inline style: it would depend on <style>";
            }
            if ($tag === 'table') {
                foreach (['role' => 'presentation', 'border' => '0', 'cellpadding' => '0'] as $a => $v) {
                    if ($el->getAttribute($a) !== $v) {
                        $out[] = "<table> without {$a}=\"{$v}\"";
                    }
                }
                if (! $el->hasAttribute('dir')) {
                    $out[] = '<table> without dir';
                }
            }
            if ($tag === 'td' || $tag === 'th') {
                if (! $el->hasAttribute('bgcolor') || ! str_contains($style, 'background-color:')) {
                    $out[] = "<{$tag}> without bgcolor + inline background-color";
                }
                if ($this->holdsText($el) && ! preg_match('/(^|[;\s])color\s*:/', $style)) {
                    $out[] = "<{$tag}> holds text without an explicit color";
                }
            }
            if (in_array($tag, ['div', 'p', 'span', 'a', 'strong', 'b', 'em', 'small'], true) && $this->holdsText($el) && ! preg_match('/(^|[;\s])color\s*:/', $style)) {
                $out[] = "<{$tag}> holds text without an explicit color";
            }
            if ($tag === 'img') {
                foreach (['width', 'height', 'alt'] as $a) {
                    if (! $el->hasAttribute($a)) {
                        $out[] = "<img> without {$a}";
                    }
                }
                if (! str_contains($style, 'display:block')) {
                    $out[] = '<img> not display:block';
                }
            }
            if ($el->hasAttribute('data-button') && ! str_contains($style, 'white-space:nowrap')) {
                $out[] = 'a button cell whose label can wrap';
            }
            if ($tag === 'a' && str_contains((string) $el->getAttribute('class'), 'ch-btn') && ! str_contains($style, 'white-space:nowrap')) {
                $out[] = 'the CTA label can wrap';
            }
        }
        foreach ($doc->querySelectorAll('[data-block="issue"]') as $card) {
            $tr = $card->querySelector('tr');
            $cells = $tr === null ? [] : array_values(array_filter(iterator_to_array($tr->childNodes), static fn ($n): bool => $n instanceof Element));
            if (count($cells) !== 2 || $cells[0]->getAttribute('width') !== '76') {
                $out[] = 'a decision card that is not two columns with a fixed 76px leading column';
            }
            if ($card->querySelector('[data-button]') === null) {
                $out[] = 'a decision card without its bullet-proof button';
            }
        }
        // Equal-width blocks: every block directly in the content column spans it.
        $main = $doc->querySelector('td.ch-pad:not(.ch-ftr):not([data-test-send-notice])');
        foreach ($this->topBlocks($main) as $block) {
            if ($block->getAttribute('width') !== '100%') {
                $out[] = 'a top-level block without width="100%" ('.($block->getAttribute('data-block') ?: 'table').')';
            }
        }

        return array_values(array_unique($out));
    }

    /** The wrapper table's 600px cap and the full-bleed logo are the two sanctioned max-widths; the hidden preheader is not drawn. */
    private function allowedMax(Element $el): bool
    {
        $style = (string) $el->getAttribute('style');

        return str_contains((string) $el->getAttribute('class'), 'ch-wrap')
            || strtolower($el->localName) === 'img'
            || str_contains($style, 'display:none');
    }

    private function holdsText(Element $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof Text && preg_match('/[^\s\x{00A0}]/u', $c->data) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Element> */
    private function topBlocks(?Element $main): array
    {
        if ($main === null) {
            return [];
        }
        $out = [];
        $stack = [$main];
        while ($stack !== []) {
            $node = array_shift($stack);
            foreach ($node->childNodes as $c) {
                if (! $c instanceof Element) {
                    continue;
                }
                $tag = strtolower($c->localName);
                if ($tag === 'table') {
                    $out[] = $c;
                } elseif ($tag === 'div') {
                    $stack[] = $c;
                }
            }
        }

        return $out;
    }
}
