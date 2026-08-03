<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHONE-001 — every form a customer actually types, and the one form we store.
 *
 * Written as a table because the failure this guards against is a *missing case*, not a wrong
 * algorithm: the old behaviour was correct for the shape each surface happened to have been tested
 * with and wrong for the other five. Listing them together is what makes a gap visible.
 */
final class PhoneNumberTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string|null}> */
    public static function saudiForms(): iterable
    {
        yield 'international with plus' => ['+966501234567', '+966501234567'];
        yield 'international with spaces' => ['+966 50 123 4567', '+966501234567'];
        yield 'international with dashes' => ['+966-50-123-4567', '+966501234567'];
        yield 'international with brackets' => ['+966 (50) 123 4567', '+966501234567'];
        yield 'double zero prefix' => ['00966501234567', '+966501234567'];
        yield 'bare country code' => ['966501234567', '+966501234567'];
        yield 'national with leading zero' => ['0501234567', '+966501234567'];
        yield 'national with spaces' => ['050 123 4567', '+966501234567'];
        yield 'national with dashes' => ['050-123-4567', '+966501234567'];
        yield 'subscriber only' => ['501234567', '+966501234567'];
        yield 'arabic-indic digits' => ['٠٥٠١٢٣٤٥٦٧', '+966501234567'];
        yield 'arabic-indic international' => ['+٩٦٦٥٠١٢٣٤٥٦٧', '+966501234567'];
        yield 'persian digits' => ['۰۵۰۱۲۳۴۵۶۷', '+966501234567'];
        yield 'country code pasted before national form' => ['9660501234567', '+966501234567'];
        yield 'leading and trailing whitespace' => ['  +966501234567  ', '+966501234567'];
    }

    #[DataProvider('saudiForms')]
    public function test_every_saudi_form_normalises_to_one_e164_string(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalise($input));
    }

    /**
     * A number that names its country keeps it.
     *
     * The failure this prevents is the loud one: defaulting everything to Saudi Arabia would rewrite
     * every foreign contact in the book into a Saudi number that belongs to somebody else.
     */
    public function test_a_number_with_a_country_code_keeps_its_own_country(): void
    {
        $this->assertSame('+201234567890', PhoneNumber::normalise('+20 123 456 7890'));
        $this->assertSame('+971501234567', PhoneNumber::normalise('+971 50 123 4567'));
        $this->assertSame('+447700900123', PhoneNumber::normalise('+44 7700 900123'));
        $this->assertSame('+905321234567', PhoneNumber::normalise('0090 532 123 4567'));
    }

    public function test_the_default_country_can_be_changed(): void
    {
        $this->assertSame('+971501234567', PhoneNumber::normalise('0501234567', '971'));
    }

    /** @return iterable<string, array{0: string|null}> */
    public static function unreadable(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'letters only' => ['not a phone'];
        yield 'punctuation only' => ['---'];
        yield 'far too short' => ['123'];
        yield 'far too long' => ['+9665012345678901234'];
    }

    #[DataProvider('unreadable')]
    public function test_unreadable_input_is_null_rather_than_a_guess(?string $input): void
    {
        $this->assertNull(PhoneNumber::normalise($input));
    }

    /**
     * The comparison that duplicate checks depend on.
     *
     * Before this, `0501234567` and `+966 50 123 4567` were two customers, and which one you got
     * depended on which form they typed at which form.
     */
    public function test_the_same_phone_written_differently_compares_equal(): void
    {
        $this->assertTrue(PhoneNumber::same('0501234567', '+966 50 123 4567'));
        $this->assertTrue(PhoneNumber::same('٠٥٠١٢٣٤٥٦٧', '00966501234567'));
        $this->assertFalse(PhoneNumber::same('0501234567', '0501234568'));
        $this->assertFalse(PhoneNumber::same(null, '0501234567'));
        $this->assertFalse(PhoneNumber::same('nonsense', 'nonsense'), 'two unreadable strings are not a match');
    }

    public function test_display_form_is_readable_without_being_stored(): void
    {
        $this->assertSame('+966 501 234 567', PhoneNumber::forDisplay('+966501234567'));
        $this->assertNull(PhoneNumber::forDisplay(null));
        // Anything not already E.164 is handed back untouched rather than mangled into a wrong shape.
        $this->assertSame('0501234567', PhoneNumber::forDisplay('0501234567'));
    }
}
