<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Engine\Mail\AlignEnvelopeSender;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\TestSendMark;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `mail:send-gallery {to} [--keys=*] [--locale=ar,en] [--send]` — EMAIL-CLIENT-COMPAT-001, the Owner's real-client check.
 *
 * The constraint lint and the engine simulations cannot say what the Gmail app does; only a message in a real mailbox can.
 * This sends the SAME gallery fixtures the platform admin's «test send» sends, through the SAME path: `MailGallery`
 * (fixtures only — no tenant, no database read of product data), `TestSendMark` (the «[اختبار] / [TEST]» subject and the
 * visible test strip, no unsubscribe link), and `TransactionalMailer::send()` with `TEST_KIND`, so every message is one
 * ledger row in `mail_deliveries` that customer-facing readers already leave out by name.
 *
 * Guards, all before anything is sent:
 * - **Dry run unless `--send`.** A dry run renders every message and reports sizes; it sends nothing and writes no row.
 * - **One address.** `to` must be exactly one valid address — no list, no display name, no separators.
 * - **At most 40 messages** (keys × locales) per invocation.
 *
 * - **Nothing secret printed.** The address is masked (`o***@domain`), the From is masked, and a refusal is reported by its
 *   class (`MailFailure::classify`), never the provider's raw text.
 * - **The configured From.** The command never sets a From; the mailables use `mail.from.*`, and `AlignEnvelopeSender`
 *   puts the authenticated mailbox on the envelope (Hostinger refuses a From it does not own — M-notes). The alignment is
 *   reported so a refusal of class `sender_rejected` can be read against it.
 */
final class SendMailGallery extends Command
{
    public const MAX_MESSAGES = 40;

    /** The Owner's review set: the seven external roles, the internal digests, the alert bundle, the spend summaries, the confirmation. */
    public const DEFAULT_KEYS = [
        'external-finance', 'external-project-manager', 'external-admin-owner', 'external-client',
        'external-achievement', 'external-continuity-planning', 'external-month-end',
        'digest-portfolio', 'digest-project', 'alerts-bundle',
        'spend-summary-weekly', 'spend-summary-external', 'external-confirmation',
    ];

    protected $signature = 'mail:send-gallery
        {to : ONE address to send the fixtures to}
        {--keys=* : Gallery keys (repeat or comma-separate); default: the Owner review set}
        {--locale=ar,en : ar, en or ar,en}
        {--send : Really send (without it this is a dry run)}
        {--dry-run : Dry run (the default; wins over --send)}';

    protected $description = 'Send gallery fixture messages, marked as tests, to ONE address through the production mailer (dry run unless --send).';

    public function handle(TransactionalMailer $mailer): int
    {
        $to = trim((string) $this->argument('to'));
        if (! self::isSingleAddress($to)) {
            $this->error('Refused: `to` must be exactly one valid email address.');

            return self::INVALID;
        }

        $locales = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('locale'))))));
        if ($locales === [] || array_diff($locales, ['ar', 'en']) !== []) {
            $this->error('Refused: --locale takes ar, en or ar,en.');

            return self::INVALID;
        }

        $keys = [];
        foreach ((array) $this->option('keys') as $opt) {
            foreach (explode(',', (string) $opt) as $k) {
                if (trim($k) !== '') {
                    $keys[] = trim($k);
                }
            }
        }
        $keys = $keys === [] ? self::DEFAULT_KEYS : array_values(array_unique($keys));

        $known = array_keys(MailGallery::compatMessages('ar'));
        $unknown = array_values(array_diff($keys, $known));
        if ($unknown !== []) {
            $this->error('Refused: unknown gallery key(s): '.implode(', ', $unknown));

            return self::INVALID;
        }

        $total = count($keys) * count($locales);
        if ($total > self::MAX_MESSAGES) {
            $this->error("Refused: {$total} messages requested; at most ".self::MAX_MESSAGES.' per invocation.');

            return self::INVALID;
        }

        $send = (bool) $this->option('send') && ! (bool) $this->option('dry-run');
        $sender = AlignEnvelopeSender::verifiedSender();
        $from = strtolower((string) config('mail.from.address'));
        $alignment = $sender === null ? 'no_smtp_mailbox' : ($sender === $from ? 'from_is_mailbox' : 'envelope_aligned_by_code');

        $this->line(($send ? 'SEND' : 'DRY RUN').": {$total} message(s) to ".MailAddress::mask($to));
        $this->line('transport: '.MailTransportState::current().' · from: '.MailAddress::mask($from).' · sender: '.$alignment);

        $run = Str::lower(Str::random(10));
        $states = [];
        $reasons = [];
        foreach ($locales as $locale) {
            $messages = MailGallery::compatMessages($locale);
            foreach ($keys as $key) {
                $mail = TestSendMark::apply($messages[$key], $locale);

                if (! $send) {
                    $kb = round(strlen($mail->render()) / 1024, 1);
                    $this->line(sprintf('  %-30s %-2s would send (%s KB)', $key, $locale, $kb));
                    $states['dry_run'] = ($states['dry_run'] ?? 0) + 1;

                    continue;
                }

                $dedupKey = 'test:cli:'.$run.':'.$key.':'.$locale;
                $state = $mailer->send(
                    recipient: $to,
                    mail: $mail,
                    kind: TransactionalMailer::TEST_KIND,
                    template: 'gallery.'.$key,
                    locale: $locale,
                    dedupKey: $dedupKey,
                );
                $code = DB::table('mail_deliveries')->where('dedup_key', $dedupKey)->value('failure_code');
                $states[$state] = ($states[$state] ?? 0) + 1;
                if (is_string($code) && $code !== '') {
                    $reasons[$code] = ($reasons[$code] ?? 0) + 1;
                }
                $this->line(sprintf('  %-30s %-2s %s%s', $key, $locale, $state, is_string($code) && $code !== '' ? " ({$code})" : ''));
            }
        }

        ksort($states);
        $this->line('states: '.implode(', ', array_map(static fn ($s, $n) => "{$s}={$n}", array_keys($states), $states)));
        if ($reasons !== []) {
            ksort($reasons);
            $this->line('refusal classes: '.implode(', ', array_map(static fn ($s, $n) => "{$s}={$n}", array_keys($reasons), $reasons)));
        }
        if ($send) {
            $this->line('«sent» means the provider accepted the message, not that it was delivered: check the mailbox.');
        }

        return $send && ($states['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private static function isSingleAddress(string $to): bool
    {
        return $to !== ''
            && preg_match('~[\s,;<>"\'()]~', $to) !== 1
            && substr_count($to, '@') === 1
            && filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
    }
}
