<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use Illuminate\Support\Str;

/**
 * LEGAL-002 — a reference a person can read aloud without wincing.
 *
 * ## Two properties, both learned the hard way
 *
 * **Unambiguous when spoken.** A customer reading «CH-8K2M4Q» down a phone should not have to
 * disambiguate O from 0 or I from 1. Those characters are removed from the alphabet outright rather
 * than substituted after generation — substitution skews the distribution and, worse, maps several
 * inputs onto the same output, quietly raising the collision rate the unique index then has to catch.
 *
 * **Not accidentally offensive.** Six random letters produce a recognisable word often enough that a
 * support desk will eventually read one out to a customer, or print it on an invoice. The first live
 * test of this code generated `DR-SEX9YP`. A blocklist is a crude instrument and does not have to be
 * exhaustive to be worth having: it costs one regeneration and removes the cases somebody would
 * otherwise have to apologise for.
 *
 * Both are why this is a service rather than a line in each model — two copies of this reasoning
 * would have drifted the moment one of them was touched.
 */
final class ReferenceGenerator
{
    /**
     * The alphabet: uppercase letters and digits, minus everything confusable when spoken.
     *
     * Removed: O and 0, I and 1 and L, S and 5, B and 8, Z and 2. What remains is unambiguous read
     * aloud in either language, at the cost of a slightly shorter effective space — which is fine,
     * because this identifies a conversation and grants access to nothing.
     */
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    /**
     * Substrings a reference must not contain, checked case-insensitively.
     *
     * Deliberately short. The aim is not a profanity filter — it is to avoid the handful of sequences
     * that would embarrass somebody reading a ticket number to a customer.
     */
    private const AVOID = ['SEX', 'ASS', 'FUK', 'FCK', 'CUM', 'TIT', 'PIS', 'DIE', 'WAR', 'KKK', 'NAZ'];

    /**
     * @param  callable(string): bool  $exists  returns true when the candidate is already taken
     */
    public function make(string $prefix, callable $exists, int $length = 6): string
    {
        // Bounded rather than `while (true)`: a saturated namespace or a mistaken `$exists` that always
        // returns true would otherwise spin forever inside a web request.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $body = '';
            for ($i = 0; $i < $length; $i++) {
                $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $candidate = $prefix.'-'.$body;

            if ($this->isUnfortunate($body) || $exists($candidate)) {
                continue;
            }

            return $candidate;
        }

        /*
         * The fallback widens rather than fails.
         *
         * Fifty collisions means the short namespace is genuinely crowded, and refusing to create the
         * ticket would be the wrong answer to that — the sender's problem is real whether or not our
         * reference space is comfortable. A longer code is uglier and correct.
         */
        return $prefix.'-'.Str::upper(Str::random(10));
    }

    private function isUnfortunate(string $body): bool
    {
        foreach (self::AVOID as $bad) {
            if (str_contains($body, $bad)) {
                return true;
            }
        }

        return false;
    }
}
