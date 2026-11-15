<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * NOTIF-PUSH-001 — a VAPID key pair for Web Push, printed to the console and written nowhere.
 *
 * `php artisan webpush:vapid` — copy the two lines into the `.env` of the server that sends (and nowhere else: not a commit, not a
 * chat). The private key signs every push; whoever holds it can send as this product. Generating a new pair invalidates every
 * existing subscription (browsers bind a subscription to the public key), so people must press «فعّل إشعارات الجوال» again.
 */
final class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate a VAPID key pair for Web Push and print it (nothing is written to disk).';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('Copy these two lines into the .env of the server that sends pushes. Do not commit them.');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('A new pair invalidates every existing subscription: people must switch mobile notifications on again.');

        return self::SUCCESS;
    }
}
