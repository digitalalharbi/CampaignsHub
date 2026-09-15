<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\Services\AccountDiscovery;
use Illuminate\Console\Command;

/**
 * GADS-CLOUD-PROJECT-001 — which Google Cloud project this install's Google Ads access follows.
 *
 * Since Google sunset developer tokens on 2026-09-09, production access belongs to the Google Cloud
 * project that owns the OAuth client, not to a token. So «is this install approved for production» is
 * really «does its OAuth client belong to the project that was approved», and until now nothing in the
 * product could answer that — which is why an owner holding an Explorer-approved project could still
 * watch every discovery answer 403 with no way to see the mismatch.
 *
 * A Google OAuth client id is `<PROJECT_NUMBER>-<random>.apps.googleusercontent.com`, and the leading
 * segment IS the Cloud project number. So the answer is derivable from the client id alone.
 *
 * ## What this deliberately does not print
 *
 * The client SECRET and the developer token are never read. A project number and a client-id suffix are
 * identifiers, not credentials — the suffix is shown truncated anyway, because an identifier nobody
 * needs in full is one nobody should have to redact before pasting a diagnosis into a ticket.
 */
final class GoogleAdsAccessCommand extends Command
{
    protected $signature = 'integrations:google-access {--probe : Ask Google now, instead of reporting the last answer it gave}';

    protected $description = 'Show which Google Cloud project the Google Ads OAuth client belongs to, and each connection\'s discovery outcome — stored, or attempted now with --probe.';

    public function handle(): int
    {
        $creds = PlatformCredentials::for('google');
        $clientId = $creds->get('client_id');

        if ($clientId === null) {
            $this->error('No GOOGLE_ADS_CLIENT_ID is configured, so this install has no Google Ads access to describe.');

            return self::FAILURE;
        }

        $project = $this->projectNumberOf($clientId);

        $this->line('OAuth client       : '.$this->safeClientId($clientId));
        $this->line('Cloud project #    : '.($project ?? 'UNRECOGNISED — the client id is not in Google\'s <project>-<random> form'));
        $this->line('Developer token    : '.($creds->get('developer_token') === null ? 'absent (optional since 2026-09-09)' : 'present (sent, but grants nothing)'));
        $this->newLine();
        $this->comment('Production access follows the Cloud project above. Compare that number with the project');
        $this->comment('showing Explorer/Basic access on its Google Ads API page; if they differ, this install is');
        $this->comment('authorising against a project that was never approved, whatever the other project says.');
        $this->newLine();

        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'google')->get();

        if ($connections->isEmpty()) {
            $this->line('No Google connections exist yet.');

            return self::SUCCESS;
        }

        if ($this->option('probe')) {
            foreach ($connections as $connection) {
                $this->probe($connection);
            }

            $connections = $connections->map(static fn (ProviderConnection $c) => $c->fresh() ?? $c);
        }

        $this->table(
            ['connection', 'status', 'last attempt', 'last success', 'blocked reason'],
            $connections->map(fn (ProviderConnection $c): array => [
                (string) $c->getKey(),
                (string) $c->status,
                optional($c->last_discovery_attempted_at)->toDateTimeString() ?? '—',
                optional($c->last_discovery_succeeded_at)->toDateTimeString() ?? 'never',
                $c->discovery_blocked_reason ?? '—',
            ])->all(),
        );

        /*
         * GADS-HIERARCHY-001 step 6 — what Google ACTUALLY said, not how we filed it.
         *
         * `discovery_blocked_reason` is a classification: `provider_project_not_approved`,
         * `discovery_failed`. It tells an operator which bucket the failure is in and nothing about
         * which customer, which login context, or which `GoogleAdsFailure` member. The connector
         * already assembles all three — «authorizationError=USER_PERMISSION_DENIED | customer … |
         * login-customer-id … | request …» — and `AccountDiscovery` stores it on `last_error`, where
         * this command was not reading it. A sentence computed carefully and shown to nobody.
         */
        foreach ($connections as $connection) {
            if ($connection->last_error === null || $connection->last_error === '') {
                continue;
            }

            $this->newLine();
            $this->line('Connection '.$connection->getKey().' — what Google said:');

            /*
             * One fact per line, because the sentence is pipe-delimited and long.
             *
             * Printed whole it wraps at the terminal width, and the wrap lands wherever it lands —
             * through the middle of «login-customer-id 1112223334» as often as not, which is the one
             * field an operator is scanning for. The delimiter is already there; using it costs
             * nothing and makes the answer readable at any width.
             */
            foreach (explode(' | ', $connection->last_error) as $fact) {
                $this->warn('  '.trim($fact));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Ask Google now.
     *
     * The table above reports the last answer, which for a connection nobody has re-authorised is an
     * answer from before the Cloud project was approved — and reads as a live refusal. This performs
     * the real discovery through the path the product itself uses, so a re-authorisation can be
     * confirmed in one command rather than by triggering the wizard and reading the result sideways.
     *
     * Failures are caught and reported rather than thrown: the point is to describe every connection
     * in one run, and `AccountDiscovery` has already recorded the outcome on the connection by the
     * time the exception reaches here.
     */
    private function probe(ProviderConnection $connection): void
    {
        $this->line('Probing connection '.$connection->getKey().' …');

        try {
            $result = app(AccountDiscovery::class)->refresh($connection);
        } catch (\Throwable $e) {
            $this->error('  discovery refused — see «what Google said» below.');

            return;
        }

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->get(['external_id', 'parent_external_id']);

        /*
         * GADS-MCC-001 — an account reached THROUGH a manager carries the manager it was reached
         * through; one held directly carries null. That single column is the hierarchy answer, and
         * printing the split is what distinguishes «the MCC resolved» from «one direct advertiser».
         */
        $throughManager = $accounts->filter(static fn ($a): bool => $a->parent_external_id !== null)->count();

        $this->info(sprintf(
            '  discovered %d, created %d, named %d, access lost %d — %d reached through a manager, %d held directly.',
            $result['discovered'],
            $result['created'],
            $result['named'],
            $result['access_lost'],
            $throughManager,
            $accounts->count() - $throughManager,
        ));
    }

    /** The leading segment of a Google OAuth client id is its Cloud project number. */
    private function projectNumberOf(string $clientId): ?string
    {
        return preg_match('/^(\d+)-/', $clientId, $m) === 1 ? $m[1] : null;
    }

    /**
     * Enough of the client id to recognise it, and no more.
     *
     * The project number is the part that answers the question, so it stays whole; the random half is
     * truncated because it identifies the client without needing to be complete, and a diagnosis that has
     * to be redacted before it can be shared is one nobody shares.
     */
    private function safeClientId(string $clientId): string
    {
        [$project, $rest] = array_pad(explode('-', $clientId, 2), 2, '');

        return $project.'-'.mb_substr($rest, 0, 6).'…'.(str_contains($rest, '.') ? '.apps.googleusercontent.com' : '');
    }
}
