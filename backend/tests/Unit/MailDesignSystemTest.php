<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Notifications\Support\MailDesign;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * MAIL-DS-001 — the email design system is a system, not a convention.
 *
 * Three templates carried fourteen distinct hex literals between them, several one shade apart, and
 * nothing said which was the border and which was the divider. That is what a design system decays
 * into when it lives in the authors' heads: every new template starts by copying the nearest
 * existing one, and the copy is where the drift enters.
 *
 * These tests are what make the tokens load-bearing. The most important is the last one — a template
 * that writes its own colour fails the suite, so the system cannot be bypassed quietly.
 */
final class MailDesignSystemTest extends TestCase
{
    /** A mistyped token must fail at render, not paint an element with nothing. */
    public function test_an_unknown_token_raises_rather_than_rendering_blank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown mail design token/');

        MailDesign::colour('bordr');
    }

    /**
     * The Arabic stack names Arabic faces, and names them FIRST.
     *
     * Every template shipped `-apple-system, Segoe UI, Roboto, Helvetica, Arial` for both languages,
     * which contains no Arabic face at all: Arabic fell through to whatever the client chose, which
     * on Windows is Tahoma at a size tuned for Latin — cramped letterforms and colliding diacritics.
     */
    public function test_the_arabic_stack_leads_with_a_face_that_can_render_arabic(): void
    {
        $ar = MailDesign::font(true);

        $this->assertStringContainsString('SF Arabic', $ar);
        $this->assertStringContainsString('Tahoma', $ar);
        $this->assertStringContainsString('Noto Sans Arabic', $ar);
        // First in the list, not merely present — order is what the client actually honours.
        $this->assertStringStartsWith("'SF Arabic'", $ar);
        // And it still closes with a generic, so a client with none of them renders text.
        $this->assertStringEndsWith('sans-serif', $ar);
    }

    /** Figures get tabular digits, or a column of money does not line up. */
    public function test_numbers_are_set_in_a_face_with_tabular_figures(): void
    {
        $this->assertStringContainsString('monospace', MailDesign::numericFont());
        $this->assertStringContainsString(MailDesign::numericFont(), MailDesign::figure());
    }

    /**
     * Nothing below 12px.
     *
     * On a phone that is the floor at which a label is still readable, and «small print» in an email
     * about somebody's money is a choice rather than a style.
     */
    public function test_no_size_in_the_scale_is_too_small_to_read(): void
    {
        foreach ((array) config('mail-design.size') as $role => $size) {
            $this->assertGreaterThanOrEqual(12, (int) $size, "{$role} is below the readable floor");
        }
    }

    /** Arabic needs more leading than Latin at the same size; 1.8 is what stopped looking tight. */
    public function test_body_text_is_given_room_to_breathe(): void
    {
        $this->assertGreaterThanOrEqual(1.7, (float) config('mail-design.leading.body'));
    }

    /**
     * Severity colours are only ever correct in pairs.
     *
     * `warn` ink on a `bad` background is unreadable, and picking the two separately is how that
     * happens.
     */
    public function test_a_severity_returns_a_matched_pair(): void
    {
        foreach (['critical', 'warning', 'positive', 'info'] as $severity) {
            $tone = MailDesign::tone($severity);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $tone['ink']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $tone['bg']);
            $this->assertNotSame($tone['ink'], $tone['bg']);
        }
    }

    /**
     * The one that makes the system load-bearing: no template invents a colour.
     *
     * Every hex in a mail view must be a value the palette defines. A template that wants a shade
     * the system does not have is a template asking for a decision the system should make.
     */
    public function test_no_mail_template_ships_a_colour_the_palette_does_not_define(): void
    {
        $palette = array_map('strtolower', array_values((array) config('mail-design.colour')));
        $offences = [];

        foreach (glob(resource_path('views/mail/**/*.blade.php')) ?: [] as $path) {
            $offences = array_merge($offences, $this->strayColours($path, $palette));
        }
        foreach (glob(resource_path('views/mail/*.blade.php')) ?: [] as $path) {
            $offences = array_merge($offences, $this->strayColours($path, $palette));
        }

        $this->assertSame([], $offences, "A mail template invented its own colour:\n".implode("\n", $offences));
    }

    /** @return list<string> */
    private function strayColours(string $path, array $palette): array
    {
        $out = [];

        foreach (file($path) ?: [] as $index => $line) {
            preg_match_all('/#[0-9a-fA-F]{6}\b/', $line, $matches);

            foreach ($matches[0] as $hex) {
                if (! in_array(strtolower($hex), $palette, true)) {
                    $out[] = '  '.basename($path).':'.($index + 1)." — {$hex}";
                }
            }
        }

        return $out;
    }
}
