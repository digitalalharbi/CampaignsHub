<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/** php artisan demo:reset — remove then re-seed demo analytics. */
final class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Remove and re-seed demo analytics data (dev/test/demo only).';

    public function handle(): int
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            $this->error('demo:reset is disabled outside local/testing/demo.');

            return self::FAILURE;
        }
        $this->call('demo:remove');
        $this->call('demo:seed');

        return self::SUCCESS;
    }
}
