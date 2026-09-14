<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * INTEG-SYNC-001 — refresh tokens BEFORE anything needs them.
 *
 * `TokenVault::fresh()` already refreshes on use, so why a command at all? Because refreshing on use
 * means the first sync after an expiry pays for the refresh, and if the refresh FAILS — the customer
 * revoked access, the app's permissions changed — that failure is discovered by a queue worker at
 * three in the morning and surfaces as a sync error, not as «أعد ربط حسابك».
 *
 * Running ahead of the need turns a silent data gap into a connection state somebody can act on, hours
 * before the numbers stop arriving.
 */
final class RefreshAdPlatformTokensCommand extends Command
{
    protected $signature = 'integrations:refresh-tokens';

    protected $description = 'Refresh ad-platform access tokens that are close to expiring.';

    public function handle(TokenVault $vault): int
    {
        $skew = (int) config('ad_platforms.refresh_skew_minutes', 60);

        $due = ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', Carbon::now()->addMinutes($skew))
            ->get();

        $refreshed = 0;
        $failed = 0;

        foreach ($due as $connection) {
            try {
                $vault->fresh($connection);
                $refreshed++;
            } catch (Throwable $e) {
                // `fresh()` has already stamped the connection `error` with the reason, which is what
                // the integrations page reads. Counting it here is for the operator running the
                // command; the customer-facing answer is already written.
                $failed++;
                $this->warn("{$connection->provider} ({$connection->id}): {$e->getMessage()}");
            }
        }

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Both: a refreshed credential and one marked as needing re-authorisation are each a row this run
         * WROTE. A run that reached ten and failed on nine did not touch one.
         *
         * The number was already computed and already printed to a terminal nobody is watching at
         * 04:00, while `scheduled_runs.rows_affected` stayed null — so the ops page could say the run
         * SUCCEEDED without being able to say whether it did anything.
         */
        app(ScheduledRunRows::class)->report($refreshed + $failed);

        $this->info("Refreshed {$refreshed} token(s); {$failed} need re-authorisation.");

        return self::SUCCESS;
    }
}
