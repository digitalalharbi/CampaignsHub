<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoAnalyticsSeeder;
use Database\Seeders\DemoReportsSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/** php artisan demo:seed — (re)seed the demo tenant + rich demo analytics (dev/test/demo only). */
final class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Seed demo users and rich demo analytics data (never in production).';

    public function handle(): int
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            $this->error('demo:seed is disabled outside local/testing/demo.');

            return self::FAILURE;
        }
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoAnalyticsSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoReportsSeeder::class, '--force' => true]);
        $this->call('cache:clear');
        $this->info('Demo data seeded.');

        return self::SUCCESS;
    }
}
