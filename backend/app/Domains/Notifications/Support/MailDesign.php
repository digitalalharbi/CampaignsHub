<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * The design system, as the templates consume it — MAIL-DS-001.
 *
 * A thin reader over `config/mail-design.php`, and the reason it exists rather than templates
 * calling `config()` directly: a Blade file writing `config('mail-design.colour.bordr')` renders an
 * empty string and ships a card with no border. This fails loudly instead, at the first render, in
 * the test suite rather than in somebody's inbox.
 *
 * It also builds the few compound values every template needs — the font stack for a language, the
 * body style, a button — so «what a heading looks like» is decided once rather than re-typed in each
 * template with a slightly different weight.
 */
final class MailDesign
{
    /** @var array<string,mixed>|null */
    private static ?array $tokens = null;

    /** @return array<string,mixed> */
    private static function tokens(): array
    {
        return self::$tokens ??= (array) config('mail-design', []);
    }

    /**
     * One token, by dotted path — and an exception rather than a blank when it does not exist.
     *
     * The failure this prevents is silent: an email client given `color:` with no value simply
     * inherits, so a mistyped token produces a message that looks almost right and is wrong in a way
     * nobody notices until a customer mentions it.
     */
    public static function token(string $path): string
    {
        $value = data_get(self::tokens(), $path);

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Unknown mail design token: {$path}");
        }

        return $value;
    }

    public static function colour(string $name): string
    {
        return self::token("colour.{$name}");
    }

    public static function size(string $name): string
    {
        return self::token("size.{$name}");
    }

    public static function space(string $name): string
    {
        return self::token("space.{$name}");
    }

    public static function radius(string $name): string
    {
        return self::token("radius.{$name}");
    }

    /** The font stack for the reader's language — Arabic faces first when the mail is Arabic. */
    public static function font(bool $ar): string
    {
        return self::token($ar ? 'font.ar' : 'font.en');
    }

    /** Tabular figures, so a column of money lines up and the eye can compare down it. */
    public static function numericFont(): string
    {
        return self::token('font.numeric');
    }

    /**
     * A complete text style, so a template states its INTENT rather than five properties.
     *
     * @param  string  $role  display · title · heading · body · label · caption
     */
    public static function text(string $role, bool $ar, ?string $colour = null, string $weight = '400'): string
    {
        $leading = in_array($role, ['display', 'title', 'heading'], true)
            ? self::token('leading.tight')
            : self::token('leading.body');

        return implode('', [
            'font-family:'.self::font($ar).';',
            'font-size:'.self::size($role).';',
            'line-height:'.$leading.';',
            'font-weight:'.$weight.';',
            'color:'.self::colour($colour ?? 'ink').';',
        ]);
    }

    /**
     * A figure: the same style with tabular digits and an explicit direction.
     *
     * `dir="ltr"` belongs on every number in an Arabic email. A right-to-left context reorders a
     * string like «1,234 SAR» into «SAR 1,234» and, worse, can split a figure around its separator —
     * so the number a reader is being asked to act on is the one thing that must not follow the
     * paragraph's direction.
     */
    public static function figure(string $role = 'display', string $colour = 'ink', string $weight = '800'): string
    {
        return implode('', [
            'font-family:'.self::numericFont().';',
            'font-size:'.self::size($role).';',
            'line-height:'.self::token('leading.tight').';',
            'font-weight:'.$weight.';',
            'color:'.self::colour($colour).';',
        ]);
    }

    /** The one call to action a message is allowed. */
    public static function button(bool $ar): string
    {
        return implode('', [
            'display:inline-block;',
            'background-color:'.self::colour('brand').';',
            'color:'.self::colour('surface').';',
            'font-family:'.self::font($ar).';',
            'font-size:'.self::size('body').';',
            'font-weight:700;',
            'text-decoration:none;',
            'padding:11px 20px;',
            'border-radius:'.self::radius('sm').';',
        ]);
    }

    /**
     * Severity → the pair of colours that expresses it.
     *
     * Returned together because they are only ever correct together: `warn` text on a `bad`
     * background is unreadable, and picking them separately is how that happens.
     *
     * @return array{ink: string, bg: string}
     */
    public static function tone(string $severity): array
    {
        return match ($severity) {
            'critical', 'bad' => ['ink' => self::colour('bad'), 'bg' => self::colour('bad_bg')],
            'warning', 'warn' => ['ink' => self::colour('warn'), 'bg' => self::colour('warn_bg')],
            'positive', 'good' => ['ink' => self::colour('good'), 'bg' => self::colour('good_bg')],
            default => ['ink' => self::colour('muted'), 'bg' => self::colour('surface_soft')],
        };
    }

    /** Everything a template needs, in one array, so a view gets `$d` and not fifteen variables. */
    public static function forView(bool $ar): array
    {
        return [
            'colour' => (array) data_get(self::tokens(), 'colour', []),
            'size' => (array) data_get(self::tokens(), 'size', []),
            'space' => (array) data_get(self::tokens(), 'space', []),
            'radius' => (array) data_get(self::tokens(), 'radius', []),
            'font' => self::font($ar),
            'numericFont' => self::numericFont(),
            'width' => self::token('width'),
            'leadingBody' => self::token('leading.body'),
            'leadingTight' => self::token('leading.tight'),
        ];
    }
}
