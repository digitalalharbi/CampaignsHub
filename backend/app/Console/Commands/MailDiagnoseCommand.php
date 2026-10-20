<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MAIL-DIAGNOSE-001 — read-only: why did a transactional message not arrive?
 *
 * Prints the mail configuration's SHAPE (driver, host, port, encryption, whether a username and password
 * exist, the from address) and the last deliveries the product recorded, with the transport's own error
 * text. It prints no password, no key and no message body, and masks the local part of every recipient.
 * It sends nothing and writes nothing.
 *
 *   php artisan mail:diagnose [--limit=10]
 */
final class MailDiagnoseCommand extends Command
{
    protected $signature = 'mail:diagnose {--limit=10 : How many recent deliveries to show}';

    protected $description = 'Show the mail configuration shape and the last recorded deliveries (read-only, no secrets)';

    public function handle(): int
    {
        $default = (string) config('mail.default');
        $mailer = (array) config("mail.mailers.{$default}", []);

        $this->line('default mailer : '.$default);
        $this->line('transport      : '.(string) ($mailer['transport'] ?? '—'));
        $this->line('host           : '.(string) ($mailer['host'] ?? '—'));
        $this->line('port           : '.(string) ($mailer['port'] ?? '—'));
        $this->line('encryption     : '.(string) ($mailer['encryption'] ?? config('mail.mailers.smtp.encryption') ?? '—'));
        $this->line('username set   : '.(! empty($mailer['username']) ? 'yes' : 'no'));
        $this->line('password set   : '.(! empty($mailer['password']) ? 'yes' : 'no'));
        $this->line('from address   : '.(string) config('mail.from.address'));

        $rows = DB::table('mail_deliveries')->orderByDesc('created_at')->limit(max(1, min((int) $this->option('limit'), 50)))
            ->get(['kind', 'recipient', 'status', 'error', 'created_at']);

        $this->line('recent deliveries: '.$rows->count());
        foreach ($rows as $r) {
            $at = (string) $r->recipient;
            $masked = str_contains($at, '@') ? mb_substr($at, 0, 1).'***@'.substr($at, (int) strrpos($at, '@') + 1) : '***';
            $error = $r->error === null ? '' : ' — '.mb_substr(preg_replace('/\s+/', ' ', (string) $r->error) ?? '', 0, 240);
            $this->line("  {$r->created_at}  {$r->kind}  {$masked}  {$r->status}{$error}");
        }

        return self::SUCCESS;
    }
}
