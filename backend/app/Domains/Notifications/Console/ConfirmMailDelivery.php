<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record that somebody actually read the message — EMAIL-DELIVERY-LEDGER-001 (§17.4.4, §17.5).
 *
 * SMTP ends at «I took it», so the only trustworthy evidence on this transport is a person looking at
 * an inbox. This is the one writer of `delivered_at`: it needs the row and a statement of what was
 * seen, and refuses a row a transport never accepted. A provider webhook would be a second writer, if
 * the install ever moves to a provider that has one; it has none today.
 *
 *   php artisan mail:confirm-delivery <id> --evidence="Owner read it in Gmail on 2026-11-03"
 */
final class ConfirmMailDelivery extends Command
{
    protected $signature = 'mail:confirm-delivery {id : A row id from either ledger} {--evidence= : What was seen, where, and by whom}';

    protected $description = 'Record inbox evidence for one message a transport accepted.';

    public function handle(): int
    {
        $evidence = trim((string) $this->option('evidence'));

        if ($evidence === '') {
            $this->error('--evidence is required: what was seen, where, and by whom.');

            return self::FAILURE;
        }

        foreach (['mail_deliveries', 'digest_sends', 'subscription_notifications'] as $table) {
            $row = DB::table($table)->where('id', (string) $this->argument('id'))->first();

            if ($row === null) {
                continue;
            }

            if ($row->status !== 'sent') {
                $this->error("This message is «{$row->status}»: no transport accepted it, so nothing can have been received.");

                return self::FAILURE;
            }

            DB::table($table)->where('id', $row->id)->update([
                'delivered_at' => now(),
                'delivery_evidence' => mb_substr($evidence, 0, 240),
                'updated_at' => now(),
            ]);
            $this->info('Recorded.');

            return self::SUCCESS;
        }

        $this->error('No delivery has that id.');

        return self::FAILURE;
    }
}
