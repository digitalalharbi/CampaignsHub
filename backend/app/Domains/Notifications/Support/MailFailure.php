<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use Throwable;

/**
 * A transport's complaint, made safe to keep and useful to read — EMAIL-DELIVERY-LEDGER-001 (§17.4.6).
 *
 * Symfony's mailer puts the SMTP username in the message of a refused login, and a message can quote
 * whatever else the conversation held. These strings reach a database column, an operator's page and
 * `mail:diagnose`, so `redact()` is what stands between them. The `code` is what a screen translates
 * into the true reason and the step that fixes it.
 */
final class MailFailure
{
    /** A retry cannot help these: the same template breaks the same way, and the address is still wrong. */
    private const PERMANENT = ['template_error', 'recipient_rejected'];

    /** @return array{code: string, safe: string} */
    public static function from(Throwable $e): array
    {
        return ['code' => self::classify($e->getMessage()), 'safe' => self::redact($e->getMessage())];
    }

    public static function isPermanent(?string $code): bool
    {
        return in_array($code, self::PERMANENT, true);
    }

    /** Checked in this order because an auth refusal also contains a response code, and a TLS one also says «connect». */
    public static function classify(string $message): string
    {
        return match (true) {
            preg_match('~\bView:|Undefined (variable|array key|property)|\.blade\.php~i', $message) === 1 => 'template_error',
            preg_match('~authenticat|\b535\b|\b534\b~i', $message) === 1 => 'auth_failed',
            preg_match('~ssl|tls|certificate|crypto~i', $message) === 1 => 'tls_failed',
            preg_match('~\b(421|450|451|452)\b|too many|rate limit|throttl~i', $message) === 1 => 'throttled',
            preg_match('~\b(550|551|552|553)\b|user unknown|mailbox|no such user|recipient~i', $message) === 1 => 'recipient_rejected',
            preg_match('~\b554\b|sender|not allowed to send|relay access~i', $message) === 1 => 'sender_rejected',
            preg_match('~connection|could not be established|timed out|stream_socket|getaddrinfo|refused|smtp~i', $message) === 1 => 'connection_failed',
            default => 'unknown',
        };
    }

    public static function redact(string $message): string
    {
        $rules = [
            '~(username\s+)"[^"]*"~i' => '$1"[hidden]"',
            '~(user(?:name)?|login|password|passwd|pass|pwd|secret|api[_-]?key)(\s*[=:]\s*)\S+~i' => '$1$2[hidden]',
            '~(token|code|otp|key|signature|secret)=[^\s&"\']+~i' => '$1=[hidden]',
            '~(using password\s+)\S+~i' => '$1[hidden]',
            // A code in a sentence, never a port (`:587`) or an SMTP status (three digits).
            '~(?<![:.\d/-])\b\d{4,8}\b(?![:.\d])~' => '[hidden]',
            // AUTH payloads and other long opaque strings.
            '~\b[A-Za-z0-9+/_-]{24,}={0,2}~' => '[hidden]',
            '~\b([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})~' => '$1***@$2',
        ];

        $safe = (string) preg_replace(array_keys($rules), array_values($rules), $message);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $safe)), 0, 500);
    }
}
