<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Sending\SenderPipeline;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-send` — one spread batch of the notifications outbox (every minute). */
final class SendOutboxCommand extends Command
{
    protected $signature = 'notifications:v2-send';

    protected $description = 'Send one batch of due notification deliveries (spread per workspace, 3 attempts, logged).';

    public function handle(SenderPipeline $pipeline): int
    {
        $r = $pipeline->run();
        app(ScheduledRunRows::class)->report(array_sum($r));
        $this->line(implode('  ', array_map(static fn (string $k, int $v): string => "{$k}: {$v}", array_keys($r), $r)));

        return self::SUCCESS;
    }
}
