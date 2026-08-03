<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PHONE-001 — one way to read a phone number, for the whole product.
 *
 * ## The problem this replaces
 *
 * A Saudi customer writes their number six different ways and means the same phone every time:
 * `+966 50 123 4567`, `00966501234567`, `966501234567`, `0501234567`, `050-123-4567`, and — on an
 * Arabic keyboard, which is the default here — `٠٥٠١٢٣٤٥٦٧`. Before this class each surface invented
 * its own reading: registration stored the raw string, the CRM stored the raw string, the trial check
 * stripped the country code entirely, and the OTP endpoint demanded a leading `+`. Two of those
 * disagree about whether `0501234567` and `+966501234567` are the same person, which means a duplicate
 * check between them was decided by which form the customer happened to type.
 *
 * Everything now stores **E.164** — `+966501234567` — and compares in that form. One shape, written
 * once, so «is this number already registered?» has one answer.
 *
 * ## The rules, and why each one
 *
 * - **Arabic-Indic digits are digits.** `٠٥٠` is `050`. Rejecting it would refuse the numeral system
 *   most of these customers type in, which is not a validation rule, it is a bug with an error message.
 * - **Separators are noise.** Spaces, dashes, brackets, dots — all removed. Nobody agrees on them and
 *   nobody means anything by them.
 * - **`00` is `+`.** The international prefix as dialled from a landline.
 * - **A leading zero is national-format, and it is dropped once a country is known.** `0501234567` in
 *   Saudi Arabia is `+966501234567`; the zero is a domestic dialling prefix, not part of the number.
 * - **A number with a country code keeps its country.** `+201234567890` stays Egyptian. Defaulting
 *   everything to Saudi Arabia would silently rewrite every foreign supplier and contact in the book.
 * - **No country code means the default region**, Saudi Arabia, because that is who this product is
 *   for and the alternative is refusing the way most customers write their own number.
 *
 * ## What this deliberately does NOT do
 *
 * It does not check that the number is *reachable*, or that the operator prefix exists. That is what
 * sending an OTP is for, and pretending otherwise would reject valid new number ranges the moment a
 * regulator issues them. The job here is one canonical form, not a claim about the real world.
 */
final class PhoneNumber
{
    /** Saudi Arabia — this product's home market, and the assumption when no country is given. */
    public const DEFAULT_COUNTRY = '966';

    /**
     * Country codes long enough to be ambiguous against each other, longest first.
     *
     * Matching must try three digits before two and two before one, or `+971` (UAE) is read as `+97`
     * followed by a stray `1`. Only the codes this product actually sees are listed; anything else
     * still normalises correctly because an unrecognised `+` prefix is kept verbatim — the list exists
     * to decide where a NATIONAL leading zero should be stripped, not to police which countries exist.
     *
     * @var list<string>
     */
    private const KNOWN_CODES = [
        '966', // Saudi Arabia
        '971', // United Arab Emirates
        '965', // Kuwait
        '973', // Bahrain
        '974', // Qatar
        '968', // Oman
        '962', // Jordan
        '961', // Lebanon
        '963', // Syria
        '964', // Iraq
        '212', // Morocco
        '216', // Tunisia
        '213', // Algeria
        '249', // Sudan
        '218', // Libya
        '967', // Yemen
        '970', // Palestine
        '20',  // Egypt
        '90',  // Türkiye
        '44',  // United Kingdom
        '49',  // Germany
        '33',  // France
        '39',  // Italy
        '34',  // Spain
        '91',  // India
        '92',  // Pakistan
        '1',   // US / Canada
    ];

    /** Arabic-Indic and Eastern Arabic-Indic digits, in order 0–9, mapped to ASCII. */
    private const EASTERN_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Normalise any of the accepted forms to E.164, or null if there is not a plausible number in there.
     *
     * Null rather than an exception: this runs on user input from half a dozen forms, and «I could not
     * read that» is a validation result, not an exceptional condition. The caller decides whether an
     * unreadable number is an error (a required field) or simply absent (an optional one).
     */
    public static function normalise(?string $input, string $defaultCountry = self::DEFAULT_COUNTRY): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = strtr(trim($input), self::EASTERN_DIGITS);

        // Remember whether the customer told us the country BEFORE stripping punctuation, because
        // "+" and a leading "00" are the only evidence of it and both are about to be removed.
        $explicit = str_starts_with($value, '+') || str_starts_with(preg_replace('/\D+/', '', $value) ?? '', '00');

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($explicit) {
            return self::plausible($digits) ? '+'.$digits : null;
        }

        /*
         * No "+" and no "00": the number may still open with a country code the customer typed bare
         * («966501234567»), or be in national format («0501234567»), or be a bare subscriber number.
         *
         * The country code is only honoured here when what FOLLOWS it does not itself start with a
         * zero. «9660501234567» is somebody who pasted the country code in front of the national form;
         * reading the 966 and keeping the 0 would produce +9660501234567, a number that does not exist.
         */
        foreach (self::KNOWN_CODES as $code) {
            if (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 5) {
                $rest = substr($digits, strlen($code));
                $rest = ltrim($rest, '0');

                return self::plausible($code.$rest) ? '+'.$code.$rest : null;
            }
        }

        $national = ltrim($digits, '0');

        return self::plausible($defaultCountry.$national) ? '+'.$defaultCountry.$national : null;
    }

    /**
     * Whether two numbers are the same phone, however each was written.
     *
     * The reason duplicate checks must not compare raw strings: `0501234567` and `+966 50 123 4567`
     * are one customer with two accounts unless something says otherwise, and this is that something.
     */
    public static function same(?string $a, ?string $b, string $defaultCountry = self::DEFAULT_COUNTRY): bool
    {
        $left = self::normalise($a, $defaultCountry);

        return $left !== null && $left === self::normalise($b, $defaultCountry);
    }

    /**
     * The number as a human reads it back: `+966 50 123 4567`.
     *
     * Display only. Nothing is ever stored or compared in this form — that is the entire point of
     * having one canonical form — but E.164 is unpleasant to read in a table, and a number a customer
     * cannot recognise as their own is a number they will "correct".
     */
    public static function forDisplay(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+')) {
            return $e164;
        }

        $digits = substr($e164, 1);
        foreach (self::KNOWN_CODES as $code) {
            if (str_starts_with($digits, $code)) {
                $rest = substr($digits, strlen($code));
                $grouped = trim(chunk_split($rest, 3, ' '));

                return '+'.$code.' '.$grouped;
            }
        }

        return $e164;
    }

    /**
     * A length check, and only a length check.
     *
     * E.164 allows up to fifteen digits including the country code; below eight there is no national
     * number long enough to dial anywhere. Anything stricter — operator prefixes, per-country lengths —
     * would start rejecting valid numbers the day a regulator opens a new range, and this class has no
     * way to know when that happens. Whether the phone ANSWERS is what the OTP is for.
     */
    private static function plausible(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= 8 && $length <= 15;
    }
}
