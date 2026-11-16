<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use TijsVerkoyen\CssToInlineStyles\Css\Processor as CssProcessor;
use TijsVerkoyen\CssToInlineStyles\Css\Rule\Rule;

/**
 * The email-safe rendering pass every message goes through after Blade — EMAIL-CLIENT-COMPAT-001.
 *
 * ## Why
 *
 * The Owner opened the real digests in the Gmail Android app on an account that is not a Google mailbox. That client drops the
 * whole `<style>` element, honours only part of the inline CSS, and in dark mode re-colours every element by its OWN declared
 * colours: an element that declares nothing is painted from Gmail's guess, which is why the outer page, the card and the text
 * came out as three unrelated browns. The templates were already table-built and mostly inline, so this pass does not redesign
 * anything; it makes the remaining implicit things explicit, deterministically, on the final HTML:
 *
 *  1. CSS from `<style>` outside `@media` is inlined (the library Laravel's own mail already depends on parses the rules;
 *     `@media`, `[data-ogsc]` and other progressive rules stay in `<style>` and are never needed for layout or colour).
 *  2. Vertical margins on block elements become fixed-height spacer rows (Outlook ignores margins on tables, Gmail drops
 *     negative ones). Sibling margins are collapsed exactly as a browser collapses them, so the rhythm stays the same.
 *  3. Every `<td>` carries `bgcolor` + inline `background-color` (inherited from its nearest painted ancestor), `dir`, `align`
 *     and an explicit text `color`; every text element states its `color` (no `inherit`). A cell that inherits the colour of a
 *     rounded card also inherits that card's corners, so a square cell never pokes out of a rounded card.
 *  4. Unitless line-heights become px against the element's font size, and an element that changes font size under a
 *     ratio line-height gets its own px line-height, so the rhythm no longer depends on the client's inheritance.
 *  5. Every table gets `role="presentation"`, `cellpadding/cellspacing/border="0"` and a `dir`; a `width="100%"` table also
 *     carries `width:100%` inline.
 *
 * The pass is idempotent and depends on nothing but its input, so a message renders byte-for-byte the same every time.
 */
final class EmailSafeHtml
{
    public const DEFAULT_COLOR = '#0b1a16';

    public const DEFAULT_BG = '#ffffff';

    private const BLOCKS = ['div', 'p', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol'];

    private const MARGIN_PARENTS = ['td', 'th', 'div'];

    private const TEXT = ['div', 'p', 'span', 'a', 'strong', 'b', 'em', 'i', 'small', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'font', 'bdi'];

    /** Render a Blade view and pass the result through the email-safe pass. */
    public static function view(string $view, array $data): string
    {
        unset($data['__env'], $data['app']);

        return self::process(view($view, $data)->render());
    }

    public static function process(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }
        preg_match('/^\s*(<!DOCTYPE[^>]*>)/i', $html, $dt);
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return $html;
        }

        self::inlineStyleBlocks($doc);
        self::collapseWhitespace($body);
        self::marginsToSpacers($doc);
        self::walk($body, [
            'bg' => self::bgOf($body) ?? self::DEFAULT_BG,
            'bgRadius' => null,
            'color' => self::colorOf($body) ?? self::DEFAULT_COLOR,
            'fs' => 16.0,
            'ratio' => null,
            'dir' => strtolower((string) ($body->getAttribute('dir') ?: $doc->documentElement?->getAttribute('dir') ?: 'ltr')),
            'align' => null,
            'tableDir' => null,
        ]);

        // The HTML5 parser adds an implied <tbody> to every table; no template writes one, every client implies it again, and
        // leaving it out keeps the bytes (and the markup the templates' own tests read) as authored.
        $out = str_replace(['<tbody>', '</tbody>'], '', $doc->saveHtml());
        // The document declares XHTML 1.0 Transitional; void elements are written closed, as the templates write them.
        $out = (string) preg_replace('#<(meta|img|br|hr|link|input)\b([^>]*?)\s*/?>#i', '<$1$2 />', $out);
        if (isset($dt[1])) {
            $out = (string) preg_replace('/^<!DOCTYPE[^>]*>/i', $dt[1], $out, 1);
        }

        return $out;
    }

    // ---------------------------------------------------------------- 1. <style> → inline

    private static function inlineStyleBlocks(HTMLDocument $doc): void
    {
        $css = '';
        foreach ($doc->querySelectorAll('style') as $style) {
            $css .= $style->textContent."\n";
        }
        if (trim($css) === '') {
            return;
        }
        $rules = (new CssProcessor)->getRules($css);
        usort($rules, static fn (Rule $a, Rule $b): int => $a->getSpecificity()->compareTo($b->getSpecificity()) ?: $a->getOrder() <=> $b->getOrder());
        foreach ($rules as $rule) {
            $selector = $rule->getSelector();
            if (str_contains($selector, '[data-og') || str_contains($selector, ':') || str_contains($selector, '@')) {
                continue;
            }
            try {
                $nodes = $doc->querySelectorAll($selector);
            } catch (\Throwable) {
                continue;
            }
            foreach ($nodes as $el) {
                $style = (string) $el->getAttribute('style');
                $add = [];
                foreach ($rule->getProperties() as $p) {
                    // An inline declaration always wins over a stylesheet one, as in a browser — unless the rule is !important.
                    if ($p->isImportant() || self::prop($style, $p->getName()) === null) {
                        $add[$p->getName()] = $p->getValue();
                    }
                }
                if ($add !== []) {
                    $el->setAttribute('style', self::append($style, $add));
                }
            }
        }
    }

    // ---------------------------------------------------------------- whitespace

    /**
     * Template indentation is bytes Gmail counts towards its ~102 KB clip. A run of white space renders as one space (or nothing,
     * between table parts), so it is written that way. `<style>`, `<pre>`, `<textarea>` and comments are left as they are.
     */
    private static function collapseWhitespace(Element $root): void
    {
        $stack = [$root];
        while ($stack !== []) {
            $el = array_pop($stack);
            $tag = strtolower($el->localName);
            if (in_array($tag, ['style', 'pre', 'textarea', 'script'], true)) {
                continue;
            }
            $structural = in_array($tag, ['table', 'tbody', 'thead', 'tfoot', 'tr'], true);
            foreach (iterator_to_array($el->childNodes) as $child) {
                if ($child instanceof Text) {
                    $text = $child->data;
                    if (trim($text, " \t\n\r\f") === '') {
                        if ($structural || str_contains($text, "\n")) {
                            // Between table parts it is nothing; elsewhere a newline-indent run is one space at most.
                            if ($structural) {
                                $child->remove();
                            } else {
                                $child->data = ' ';
                            }
                        }
                    } else {
                        $child->data = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $text);
                    }
                } elseif ($child instanceof Element) {
                    $stack[] = $child;
                }
            }
        }
    }

    // ---------------------------------------------------------------- 2. margins → spacers

    private static function marginsToSpacers(HTMLDocument $doc): void
    {
        $parents = [];
        foreach ($doc->body?->querySelectorAll(implode(',', self::BLOCKS)) ?? [] as $el) {
            $style = (string) $el->getAttribute('style');
            if (! preg_match('/(^|;)\s*margin(-top|-bottom)?\s*:/i', $style)) {
                continue;
            }
            $p = $el->parentNode;
            if ($p instanceof Element && in_array(strtolower($p->localName), self::MARGIN_PARENTS, true)) {
                $parents[spl_object_id($p)] = $p;
            }
        }
        foreach ($parents as $parent) {
            self::spaceChildren($doc, $parent);
        }
    }

    private static function spaceChildren(HTMLDocument $doc, Element $parent): void
    {
        $pending = null; // [pos, neg] of the previous block's bottom margin
        $prev = null;
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof Text && trim($node->textContent) === '') {
                continue;
            }
            $isBlock = $node instanceof Element && in_array(strtolower($node->localName), self::BLOCKS, true);
            if (! $isBlock) {
                if ($pending !== null && $prev !== null) {
                    self::insertSpacerAfter($doc, $prev, self::collapse([$pending]));
                }
                $pending = null;
                $prev = null;

                continue;
            }
            /** @var Element $node */
            [$top, $bottom] = self::takeVerticalMargins($node);
            $gap = self::collapse(array_values(array_filter([$pending, $top === null ? null : self::split($top)])));
            if ($gap > 0) {
                self::insertSpacerBefore($doc, $node, $gap);
            }
            $pending = $bottom === null ? null : self::split($bottom);
            $prev = $node;
        }
        if ($pending !== null && $prev !== null) {
            self::insertSpacerAfter($doc, $prev, self::collapse([$pending]));
        }
    }

    /** @return array{0: float|null, 1: float|null} top and bottom margin in px, removed from the element's style */
    private static function takeVerticalMargins(Element $el): array
    {
        $style = (string) $el->getAttribute('style');
        $top = $bottom = null;
        $decls = self::decls($style);
        $kept = [];
        foreach ($decls as [$name, $value]) {
            $n = strtolower($name);
            if ($n === 'margin') {
                $parts = preg_split('/\s+/', trim(str_ireplace('!important', '', $value))) ?: [];
                [$t, $r, $b, $l] = match (count($parts)) {
                    1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
                    2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
                    3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
                    default => [$parts[0], $parts[1], $parts[2], $parts[3]],
                };
                $top = self::px($t);
                $bottom = self::px($b);
                $kept[] = ['margin', "0 {$r} 0 {$l}"];
            } elseif ($n === 'margin-top') {
                $top = self::px($value);
            } elseif ($n === 'margin-bottom') {
                $bottom = self::px($value);
            } else {
                $kept[] = [$name, $value];
            }
        }
        if ($top !== null || $bottom !== null) {
            $el->setAttribute('style', implode(' ', array_map(static fn (array $d): string => $d[0].':'.$d[1].';', $kept)));
        }

        return [$top, $bottom];
    }

    /** @return array{0: float, 1: float} */
    private static function split(float $m): array
    {
        return [max(0.0, $m), min(0.0, $m)];
    }

    /** CSS margin collapsing: the largest positive plus the most negative. @param list<array{0: float, 1: float}> $ms */
    private static function collapse(array $ms): int
    {
        if ($ms === []) {
            return 0;
        }

        return max(0, (int) round(max(array_column($ms, 0)) + min(array_column($ms, 1))));
    }

    private static function spacer(HTMLDocument $doc, int $h): Element
    {
        $t = $doc->createElement('table');
        foreach (['role' => 'presentation', 'width' => '100%', 'cellpadding' => '0', 'cellspacing' => '0', 'border' => '0', 'data-spacer' => (string) $h, 'style' => 'width:100%;'] as $k => $v) {
            $t->setAttribute($k, $v);
        }
        $tr = $doc->createElement('tr');
        $td = $doc->createElement('td');
        $td->setAttribute('height', (string) $h);
        $td->setAttribute('style', "height:{$h}px; line-height:{$h}px; font-size:1px; mso-line-height-rule:exactly;");
        $td->appendChild($doc->createTextNode("\u{00A0}"));
        $tr->appendChild($td);
        $t->appendChild($tr);

        return $t;
    }

    private static function insertSpacerBefore(HTMLDocument $doc, Element $el, int $h): void
    {
        if ($h > 0) {
            $el->parentNode?->insertBefore(self::spacer($doc, $h), $el);
        }
    }

    private static function insertSpacerAfter(HTMLDocument $doc, Element $el, int $h): void
    {
        if ($h > 0) {
            $el->parentNode?->insertBefore(self::spacer($doc, $h), $el->nextSibling);
        }
    }

    // ---------------------------------------------------------------- 3–5. explicit cells, colours, line-heights, tables

    /** @param array{bg: string, bgRadius: string|null, color: string, fs: float, ratio: float|null, dir: string, align: string|null, tableDir: string|null} $ctx */
    private static function walk(Element $el, array $ctx): void
    {
        $tag = strtolower($el->localName);
        $style = (string) $el->getAttribute('style');
        $add = [];
        $tableDir = null;

        // font size and line height
        $fs = self::px(self::prop($style, 'font-size') ?? '') ?? $ctx['fs'];
        $lh = self::prop($style, 'line-height');
        $ratio = $ctx['ratio'];
        if ($lh !== null && preg_match('/^\s*(\d+(?:\.\d+)?)\s*$/', $lh, $mm)) {
            $style = self::replaceProp($style, 'line-height', ((int) round($fs * (float) $mm[1])).'px');
            $ratio = (float) $mm[1];
        } elseif ($lh !== null) {
            $ratio = null; // an explicit length: descendants inherit the length, as in a browser
        } elseif ($ratio !== null && self::prop($style, 'font-size') !== null && $fs > 1) {
            $add['line-height'] = ((int) round($fs * $ratio)).'px';
        }

        // direction and alignment
        $dirAttr = strtolower((string) $el->getAttribute('dir'));
        $dir = in_array($dirAttr, ['rtl', 'ltr'], true) ? $dirAttr : $ctx['dir'];
        $align = self::prop($style, 'text-align') ?? ($el->hasAttribute('align') && in_array($tag, ['td', 'th', 'div', 'p'], true) ? strtolower((string) $el->getAttribute('align')) : null) ?? $ctx['align'];

        // colour
        $own = self::colorOf($el);
        $colorDecl = self::prop($style, 'color');
        if ($colorDecl !== null && in_array(strtolower(trim($colorDecl)), ['inherit', 'currentcolor', 'initial', 'unset'], true)) {
            $style = self::replaceProp($style, 'color', $ctx['color']);
            $own = $ctx['color'];
        }
        $color = $own ?? $ctx['color'];

        // background
        $ownBg = self::bgOf($el);
        $bg = $ownBg ?? $ctx['bg'];
        // A rounded box passes its corners to the cells that fill it, whether it paints its own ground or not: a square cell
        // background would otherwise cover the rounded border at the corners.
        $ownRadius = in_array($tag, ['table', 'div'], true) ? self::prop($style, 'border-radius') : null;
        $radius = $ownRadius ?? ($ownBg !== null ? null : $ctx['bgRadius']);

        if ($tag === 'table') {
            foreach (['role' => 'presentation', 'cellpadding' => '0', 'cellspacing' => '0', 'border' => '0'] as $k => $v) {
                if (! $el->hasAttribute($k)) {
                    $el->setAttribute($k, $v);
                }
            }
            if (! $el->hasAttribute('dir')) {
                $el->setAttribute('dir', $dir);
            }
            $tableDir = $dir;
            if (trim((string) $el->getAttribute('width')) === '100%' && self::prop($style, 'width') === null) {
                $add['width'] = '100%';
            }
            if ($ownBg !== null && ! $el->hasAttribute('bgcolor')) {
                $el->setAttribute('bgcolor', $ownBg);
            }
            if ($ownRadius === null) {
                $radius = null; // a nested table sits inside the cell that already carries the corners
            }
        }

        if ($tag === 'td' || $tag === 'th') {
            if ($ownBg === null) {
                $el->setAttribute('bgcolor', $bg);
                $add['background-color'] = $bg;
                if ($ctx['bgRadius'] !== null && self::prop($style, 'border-radius') === null) {
                    $corners = self::corners($el, $ctx['bgRadius'], $dir);
                    if ($corners !== null) {
                        $add['border-radius'] = $corners;
                    }
                }
            } elseif (! $el->hasAttribute('bgcolor')) {
                $el->setAttribute('bgcolor', $ownBg);
            }
            $holdsText = self::holdsText($el);
            if ($colorDecl === null && $holdsText) {
                $add['color'] = $color;
            }
            // The cell's direction comes from its table's `dir` attribute (every table has one, below); a cell whose CSS direction
            // differs from it states its own.
            if (! $el->hasAttribute('dir') && $holdsText && $dir !== $ctx['tableDir']) {
                $el->setAttribute('dir', $dir);
            }
            if ($holdsText && ! $el->hasAttribute('align') && $align !== null && in_array($align, ['left', 'right', 'center'], true)) {
                $el->setAttribute('align', $align);
            }
            $radius = null; // the cell has taken its corners; its content sits inside them
        } elseif (in_array($tag, self::TEXT, true) && $colorDecl === null && $own === null) {
            $add['color'] = $color;
        }

        if ($add !== [] || $style !== (string) $el->getAttribute('style')) {
            $el->setAttribute('style', self::append($style, $add));
        }

        $next = ['bg' => $bg, 'bgRadius' => $radius, 'color' => $color, 'fs' => $fs, 'ratio' => $ratio, 'dir' => $dir, 'align' => $align, 'tableDir' => $tableDir ?? $ctx['tableDir']];
        foreach ($el->childNodes as $child) {
            if ($child instanceof Element) {
                self::walk($child, $next);
            }
        }
    }

    /** True when the element has text of its own (not only nested tables or elements). */
    private static function holdsText(Element $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof Text && preg_match('/[^\s\x{00A0}]/u', $c->data) === 1) {
                return true;
            }
            if ($c instanceof Element && in_array(strtolower($c->localName), ['span', 'a', 'strong', 'b', 'em', 'small', 'bdi', 'br', 'img'], true)) {
                return true;
            }
        }

        return false;
    }

    /** The corners a cell takes from the rounded card it fills: start/end by its column, top/bottom by its row. */
    private static function corners(Element $td, string $radius, string $dir): ?string
    {
        $tr = $td->parentNode;
        if (! $tr instanceof Element || strtolower($tr->localName) !== 'tr') {
            return null;
        }
        $cells = array_values(array_filter(iterator_to_array($tr->childNodes), static fn (Node $n): bool => $n instanceof Element && in_array(strtolower($n->localName), ['td', 'th'], true)));
        $rows = array_values(array_filter(iterator_to_array($tr->parentNode->childNodes), static fn (Node $n): bool => $n instanceof Element && strtolower($n->localName) === 'tr'));
        $r = trim(explode(' ', trim($radius))[0]);
        $first = $cells[0] === $td;
        $last = end($cells) === $td;
        $top = $rows[0] === $tr;
        $bottom = end($rows) === $tr;
        $leftCol = $dir === 'rtl' ? $last : $first;
        $rightCol = $dir === 'rtl' ? $first : $last;
        $c = [$top && $leftCol, $top && $rightCol, $bottom && $rightCol, $bottom && $leftCol];
        if (! in_array(true, $c, true)) {
            return null;
        }

        return implode(' ', array_map(static fn (bool $on): string => $on ? $r : '0', $c));
    }

    // ---------------------------------------------------------------- style helpers

    /** @return list<array{0: string, 1: string}> */
    private static function decls(string $style): array
    {
        $out = [];
        // Split on `;` that is not inside quotes or parentheses.
        foreach (preg_split('/;(?=(?:[^\'"()]|\'[^\']*\'|"[^"]*"|\([^)]*\))*$)/', $style) ?: [] as $d) {
            $pos = strpos($d, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($d, 0, $pos));
            if ($name !== '') {
                $out[] = [$name, trim(substr($d, $pos + 1))];
            }
        }

        return $out;
    }

    private static function prop(string $style, string $name): ?string
    {
        $found = null;
        foreach (self::decls($style) as [$n, $v]) {
            if (strtolower($n) === $name) {
                $found = trim(str_ireplace('!important', '', $v));
            }
        }

        return $found;
    }

    private static function replaceProp(string $style, string $name, string $value): string
    {
        return (string) preg_replace('/(^|;)(\s*'.preg_quote($name, '/').'\s*:)\s*[^;]*/i', '${1}${2}'.str_replace(['\\', '$'], ['\\\\', '\\$'], $value), $style);
    }

    /** @param array<string,string> $add */
    private static function append(string $style, array $add): string
    {
        $style = trim($style);
        foreach ($add as $k => $v) {
            $style = ($style === '' ? '' : rtrim($style, '; ').'; ').$k.':'.$v.';';
        }

        return $style;
    }

    private static function px(string $v): ?float
    {
        $v = trim(str_ireplace('!important', '', $v));
        if ($v === '0') {
            return 0.0;
        }

        return preg_match('/^(-?\d+(?:\.\d+)?)px$/i', $v, $m) ? (float) $m[1] : null;
    }

    private static function colorOf(Element $el): ?string
    {
        $c = self::prop((string) $el->getAttribute('style'), 'color');
        if ($c !== null && ! in_array(strtolower($c), ['inherit', 'currentcolor', 'initial', 'unset', 'transparent'], true)) {
            return $c;
        }
        $attr = trim((string) $el->getAttribute('color'));

        return strtolower($el->localName) === 'font' && $attr !== '' ? $attr : null;
    }

    private static function bgOf(Element $el): ?string
    {
        $style = (string) $el->getAttribute('style');
        $c = self::prop($style, 'background-color');
        if ($c === null && ($b = self::prop($style, 'background')) !== null && preg_match('/(#[0-9a-f]{3,8}|rgba?\([^)]*\))/i', $b, $m)) {
            $c = $m[1];
        }
        if ($c !== null && ! in_array(strtolower($c), ['transparent', 'inherit', 'none', 'initial'], true)) {
            return $c;
        }
        $attr = trim((string) $el->getAttribute('bgcolor'));

        return $attr !== '' ? $attr : null;
    }
}
