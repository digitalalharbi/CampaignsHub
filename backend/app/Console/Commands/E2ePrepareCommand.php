<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Bring the E2E database up from nothing, then reset it (E2E-ISO-001).
 *
 * Called by Playwright's global setup before either server starts. It is the reason the gate can have
 * its own database without anybody creating one by hand: a clean checkout runs `npx playwright test`
 * and this makes the database, migrates it and seeds it.
 *
 * It refuses to touch anything but a database whose name ends in the E2E suffix. That guard is the
 * whole point — the command's job is `migrate:fresh`, which drops every table it can see, and a
 * mis-set `DB_DATABASE` in the environment would otherwise point that at development or production.
 * Naming is the only signal available this early, so it is treated as a hard precondition rather than
 * a hint.
 */
final class E2ePrepareCommand extends Command
{
    protected $signature = 'e2e:prepare {--fresh=1 : Migrate fresh and seed. Pass --fresh=0 to only create the database.}';

    protected $description = 'Create (if missing) and reset the isolated E2E database. Refuses any database not named *_e2e.';

    /** The gate's database must carry this suffix, or the command will not run against it. */
    public const REQUIRED_SUFFIX = '_e2e';

    public function handle(): int
    {
        $connection = Config::string('database.default');
        $database = Config::string("database.connections.{$connection}.database");

        if (! str_ends_with($database, self::REQUIRED_SUFFIX)) {
            $this->error("Refusing to run: '{$database}' does not end in '".self::REQUIRED_SUFFIX."'.");
            $this->line('This command drops every table it can reach. Point DB_DATABASE at the E2E database.');

            return self::FAILURE;
        }

        if (App::environment('production')) {
            $this->error('e2e:prepare is disabled in production.');

            return self::FAILURE;
        }

        if (! $this->databaseExists($connection, $database)) {
            $this->line("Creating database {$database}…");
            $this->createDatabase($connection, $database);
        }

        if ($this->option('fresh') === '0') {
            return self::SUCCESS;
        }

        $this->line("Resetting {$database} (migrate:fresh --seed)…");

        if ($this->call('migrate:fresh', ['--seed' => true, '--force' => true]) !== 0) {
            return self::FAILURE;
        }

        $this->forgetRedis();

        return self::SUCCESS;
    }

    /**
     * Reset the gate's REDIS too, not only its database — E2E-ISO-002.
     *
     * Sessions, the cache and the queues all live in Redis (see `e2e/env.ts`), and `migrate:fresh`
     * touches Postgres alone. That did not matter while a gate was one long invocation, because the
     * database and Redis were reset together at the start of it and stayed consistent throughout.
     *
     * Running Playwright once per browser broke that assumption in a way that produced two failures
     * with no obvious connection to each other: an advertiser was «not offered a password step», and
     * the analytics page rendered without its metric catalogue. Both were the second invocation
     * meeting a fresh database while Redis still held sessions and cached answers describing the
     * rows the first invocation had just dropped. A stale cache entry is indistinguishable from a
     * product defect from the outside, which is exactly why this belongs here rather than in a
     * retry.
     *
     * Only the gate's own keyspace is touched. Every Redis key Laravel writes carries `REDIS_PREFIX`
     * — `campaignshub-e2e-` for this environment — so `keys('*')` on the prefixed connection cannot
     * reach a developer's own stack sharing the same Redis server. `flushdb()` would, which is why
     * it is not used.
     */
    private function forgetRedis(): void
    {
        try {
            $connection = Redis::connection();
            $keys = $connection->keys('*');

            if ($keys === []) {
                $this->line('Redis: nothing to clear for this prefix.');

                return;
            }

            /*
             * `keys()` returns keys WITH the prefix already applied, and `del()` would apply it
             * again — deleting `campaignshub-e2e-campaignshub-e2e-…`, which exists nowhere. The
             * prefix is stripped back off so the two halves agree.
             */
            $prefix = (string) config('database.redis.options.prefix', '');
            $bare = array_map(
                static fn (string $key): string => $prefix !== '' && str_starts_with($key, $prefix)
                    ? substr($key, strlen($prefix))
                    : $key,
                $keys,
            );

            $connection->del($bare);
            $this->line('Redis: cleared '.count($bare).' key(s) under this prefix.');
        } catch (Throwable $e) {
            /*
             * A gate that cannot reach Redis is a gate whose sessions will not work at all, so this
             * is said out loud rather than swallowed — but it does not abort the reset, because the
             * database half has already succeeded and the failure will be obvious within seconds.
             */
            $this->warn('Redis: could not clear the gate keyspace — '.$e->getMessage());
        }
    }

    private function databaseExists(string $connection, string $database): bool
    {
        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * `CREATE DATABASE` needs a session, and a session needs a database — so it is issued over the
     * server's own maintenance database on the same host, port and credentials.
     */
    private function createDatabase(string $connection, string $database): void
    {
        $maintenance = "{$connection}_e2e_maintenance";
        Config::set("database.connections.{$maintenance}", array_merge(
            Config::array("database.connections.{$connection}"),
            ['database' => Config::string("database.connections.{$connection}.driver") === 'pgsql' ? 'postgres' : null],
        ));

        DB::connection($maintenance)->statement('CREATE DATABASE "'.$database.'"');
        DB::purge($maintenance);
    }
}
