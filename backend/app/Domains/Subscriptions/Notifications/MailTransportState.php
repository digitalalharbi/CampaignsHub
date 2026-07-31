<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Notifications;

/**
 * Can this install actually send an email, and would anybody receive it? (NOTIF-SUB-001)
 *
 * Three answers, and keeping them apart is the whole point:
 *
 *   - `awaiting_credentials` — the configured driver has no credentials. Nothing can be sent.
 *   - `sandbox` — a driver that works but reaches nobody: `log`, `array`, `null`.
 *   - `live` — a real transport with the credentials it needs.
 *
 * The check is per driver and explicit, because "is a mailer configured?" has no general answer:
 * Laravel ships `ses` and `smtp` in `mail.mailers` whether or not anybody has supplied keys, so the
 * presence of the driver proves nothing. Asking each one for the credential it actually needs is the
 * only way to tell — and getting this wrong means an install reporting deliveries it never made.
 */
final class MailTransportState
{
    public const AWAITING_CREDENTIALS = 'awaiting_credentials';

    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    /** Drivers that succeed and reach nobody. */
    private const SANDBOX_DRIVERS = ['log', 'array', 'null'];

    public static function current(): string
    {
        $driver = (string) config('mail.default', 'log');

        if (in_array($driver, self::SANDBOX_DRIVERS, true)) {
            return self::SANDBOX;
        }

        $mailer = config("mail.mailers.{$driver}");

        if (! is_array($mailer)) {
            // Named but absent from the catalogue — nothing to send with.
            return self::AWAITING_CREDENTIALS;
        }

        return self::hasCredentials($driver, $mailer) ? self::LIVE : self::AWAITING_CREDENTIALS;
    }

    public static function isLive(): bool
    {
        return self::current() === self::LIVE;
    }

    /**
     * The one credential each driver cannot work without.
     *
     * Deliberately not exhaustive validation — a wrong password is the transport's problem to report.
     * What this answers is "has anybody supplied anything at all?", which is the difference between
     * an install that is waiting for credentials and one that is misconfigured.
     *
     * @param  array<string, mixed>  $mailer
     */
    private static function hasCredentials(string $driver, array $mailer): bool
    {
        return match ($driver) {
            // A host is not enough on its own: Laravel's default points at a placeholder that will
            // never accept mail, so an install that has changed nothing must not read as live.
            'smtp' => self::filled($mailer['host'] ?? null)
                && ! in_array($mailer['host'], ['smtp.mailgun.org', 'mailpit', 'localhost', '127.0.0.1'], true)
                && self::filled($mailer['username'] ?? null),
            'ses', 'ses-v2' => self::filled(config('services.ses.key')),
            'postmark' => self::filled(config('services.postmark.token')),
            'resend' => self::filled(config('services.resend.key')),
            'mailgun' => self::filled(config('services.mailgun.secret')),
            'sendmail' => self::filled($mailer['path'] ?? null),
            // An unrecognised driver is assumed NOT configured. Guessing the other way is how an
            // install starts reporting deliveries nobody received.
            default => false,
        };
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
