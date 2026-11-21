<?php

declare(strict_types=1);

namespace App\Domains\Agents\Support;

/**
 * AGENTS-MEMORY-001 — what may become a memory, and how much of it reaches a prompt.
 *
 * A memory is a preference or a standing instruction («اختصر دائمًا», «ركّز على ROAS للمبيعات»), never a secret: a text that
 * carries a key, a password, an e-mail, a phone number or a card-like number is REFUSED (not trimmed and stored), so nothing
 * secret is ever at rest in a memory or sent to a provider. Each memory is short; the set a prompt carries is capped.
 */
final class MemoryScrubber
{
    public const MAX_CHARS = 280;

    public const MAX_PER_USER = 30;

    /** Total characters of memory one prompt carries. */
    public const PROMPT_CAP = 1500;

    private const SECRET_WORDS = '/(password|passwd|كلمة\s*(?:ال)?(?:مرور|سر)|الرقم\s*السري|api[\s_-]?key|secret|token|bearer|otp|رمز\s*(?:ال)?تحقق|iban|آيبان|ايبان|بطاقة\s*(?:ال)?(?:ائتمان|مدى))/iu';

    private const SECRET_SHAPES = [
        '/\bsk-[A-Za-z0-9_\-]{6,}/',
        '/\bAIza[0-9A-Za-z_\-]{10,}/',
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        '/(?:\+|00)\d[\d\s\-]{7,}\d/',
        '/\b05\d[\d\s\-]{6,}\d\b/',
        '/\b(?:\d[ \-]?){13,19}\b/',            // card-like runs
        '/\b[A-Za-z0-9_\-]{32,}\b/',             // long opaque strings
    ];

    /** The text to store, or null when it must not be stored (empty, too long after trimming, or secret-like). */
    public static function clean(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        // Edge punctuation off, multibyte-safe (`trim()` with «» in its list would cut UTF-8 bytes in half).
        $text = trim((string) preg_replace('/^[\s:،,.\-«»"\']+|[\s:،,.\-«»"\']+$/u', '', $text));
        if ($text === '' || mb_strlen($text) > self::MAX_CHARS) {
            return null;
        }
        if (self::looksSecret($text)) {
            return null;
        }

        return $text;
    }

    public static function looksSecret(string $text): bool
    {
        if (preg_match(self::SECRET_WORDS, $text) === 1) {
            return true;
        }
        foreach (self::SECRET_SHAPES as $shape) {
            if (preg_match($shape, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The memory as prompt lines, newest first, cut at the cap. Each line is re-checked: a memory stored before a rule tightened
     * is skipped rather than sent.
     *
     * @param  list<string>  $texts
     */
    public static function forPrompt(array $texts): string
    {
        $lines = [];
        $used = 0;
        foreach ($texts as $t) {
            if (self::looksSecret($t)) {
                continue;
            }
            $line = '- '.$t;
            if ($used + mb_strlen($line) > self::PROMPT_CAP) {
                break;
            }
            $lines[] = $line;
            $used += mb_strlen($line) + 1;
        }

        return implode("\n", $lines);
    }

    /**
     * «تذكّر …» / «remember …» at the start of a message: the text to remember, or null when the message is not such a request.
     */
    public static function rememberRequest(string $message): ?string
    {
        if (preg_match('/^\s*(?:تذكّر|تذكر|تذكَّر|احفظ\s+(?:أن|ان)?|remember(?:\s+that)?)\s*[:،,]?\s*(.+)$/isu', $message, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /** «انسَ …» / «forget …»: the words to forget, '' for «forget everything», null when the message is not such a request. */
    public static function forgetRequest(string $message): ?string
    {
        if (preg_match('/^\s*(?:انسَ|انس|إنس|forget)\s*[:،,]?\s*(.*)$/isu', $message, $m) === 1) {
            $what = trim($m[1]);

            return in_array(mb_strtolower($what), ['كل شيء', 'كل شي', 'الكل', 'everything', 'all'], true) ? '' : $what;
        }

        return null;
    }

    /**
     * A standing instruction worth offering as a memory chip («دائمًا …», «always …»). The person still decides; nothing is saved here.
     */
    public static function suggestion(string $message): ?string
    {
        if (self::rememberRequest($message) !== null || mb_strlen($message) > 200) {
            return null;
        }
        if (preg_match('/(دائم[اًا]?|دايم[اًا]?|كل مرة|من الآن|من الان|always|from now on|every time)/iu', $message) !== 1) {
            return null;
        }

        return self::clean($message);
    }
}
