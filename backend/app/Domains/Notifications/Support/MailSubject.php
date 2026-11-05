<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * One way to write a subject line — EMAIL-SUBJECT-001 (Owner §17.3.1, as corrected on 2026-11-05).
 *
 * Every subject opens with the product's name, then says what happened. §17.3.1 wrote that name in Latin
 * for both languages. The Owner's later instruction — «إذا عربي اكتب كامبينز هب وإذا إنجليزي اكتب
 * بالإنجليزي» — supersedes that one part: an Arabic subject opens with the Arabic name, an English one
 * with the Latin. The name has one source, `MailShell::wordmark()`, so the inbox, the header and the
 * footer cannot drift apart. `EmailSystemGuardTest` fails for any message whose subject opens otherwise.
 */
final class MailSubject
{
    /** What a subject in this language opens with. */
    public static function prefix(bool $ar): string
    {
        return MailShell::wordmark($ar).' — ';
    }

    public static function of(string $line, bool $ar): string
    {
        return self::prefix($ar).trim($line);
    }
}
