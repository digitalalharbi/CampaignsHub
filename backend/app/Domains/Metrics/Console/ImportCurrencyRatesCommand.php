<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Console;

use App\Domains\Metrics\Rates\CurrencyRateFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * FX-FEED-001 — ask the configured rate source for the conversions this deployment cannot make.
 *
 * ## It refuses loudly and writes nothing when no feed is configured
 *
 * Exit code 0, because an install that has not bought a rate subscription is not FAILING — it is
 * unconfigured, and a scheduled command that exits non-zero every night trains an operator to ignore
 * their own alerts. What it must never do is fill the gap: no default publisher, no last-known rate
 * re-dated to today, no 1.0 for an unknown pair. Every one of those would silently undo the
 * fail-closed rule that FX-001 and COMMERCE-FX-001 are built on.
 *
 * ## It asks only for pairs the DATA needs
 *
 * The pairs come from the figures already withheld, not from a configured list — see
 * {@see CurrencyRateFeed::unmetPairs()}. A list would go stale the first time a client connected a
 * shop in a currency nobody had listed, and those are exactly the figures nobody notices are missing.
 */
final class ImportCurrencyRatesCommand extends Command
{
    protected $signature = 'fx:rates {--date= : The publication date to ask for, defaults to today}';

    protected $description = 'Import exchange rates from the configured source for every conversion currently withheld.';

    public function handle(CurrencyRateFeed $feed): int
    {
        $date = $this->option('date') === null
            ? Carbon::today()
            : Carbon::parse((string) $this->option('date'));

        $result = $feed->import($date);

        if ($result['state'] !== 'ready') {
            $this->warn(match ($result['state']) {
                'awaiting_configuration' => 'No exchange-rate source is configured (FX_RATE_DRIVER is unset). Nothing was fetched and nothing was invented.',
                default => 'The configured exchange-rate source is missing something it needs. Nothing was fetched.',
            });

            if ($result['missing'] !== []) {
                $this->line('Conversions currently withheld for want of a rate: '.implode(', ', $result['missing']));
                $this->line('Rates can also be recorded by hand from /admin — an operator is a legitimate source.');
            }

            return self::SUCCESS;
        }

        if ($result['error'] !== null) {
            $this->error('The rate source could not be read: '.$result['error']);

            // A failed FETCH is a genuine failure, unlike an absent configuration: something was
            // asked and did not answer, and the scheduler should surface that.
            return self::FAILURE;
        }

        $this->info("Requested {$result['requested']} pair(s); recorded {$result['imported']}.");

        if ($result['missing'] !== []) {
            // Said out loud: a partial answer leaves figures withheld, and a silent partial import
            // looks exactly like a complete one.
            $this->warn('Still without a rate: '.implode(', ', $result['missing']));
        }

        return self::SUCCESS;
    }
}
