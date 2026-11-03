<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * One way to write a subject line — EMAIL-SUBJECT-001 (Owner §17.3.1).
 *
 * The inbox names the product the same way in both languages, in Latin, first: an Arabic subject that
 * opened «كامبينز هب —» and an English one that opened «CampaignsHub —» are two senders to a filter
 * rule and to a reader scanning a list. The Arabic name stays in the body and the header.
 * `EmailSystemGuardTest` fails for any message whose subject does not start with this prefix.
 */
final class MailSubject
{
    public const PREFIX = 'CampaignsHub — ';

    public static function of(string $line): string
    {
        return self::PREFIX.trim($line);
    }
}
