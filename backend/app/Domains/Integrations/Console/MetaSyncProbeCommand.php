<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * META-SYNC-PROOF-001 — «the OAuth app is valid» is not «the accounts are syncing».
 *
 * The platform-admin page proves a Meta app's configuration. It says nothing about whether a
 * CONNECTED ad account still answers, because the two fail for different reasons: an app can be
 * perfectly configured while the account owner has revoked `ads_read`, the token has expired, or
 * Business Manager access has been withdrawn. This asks the account itself.
 *
 * ## What it does NOT do
 *
 * It rotates nothing, refreshes nothing and writes no credential. Diagnosis that mutates the thing
 * being diagnosed destroys the evidence — and a token refreshed during a probe reports a state that
 * did not exist a moment earlier. The only writes are the ones the product's own discovery performs,
 * and only under `--sync`.
 *
 * ## What it records
 *
 * Meta answers a refusal with `error.code`, `error.error_subcode`, `error.type`, `error.message` and
 * an `fbtrace_id`, and nothing in this codebase read any of them — the connector collapses a refusal
 * to its human message. Those five fields are what Meta's own support asks for, and the subcode is
 * usually the only thing that separates «expired» from «revoked». They are identifiers, not secrets.
 *
 * The blocker is then classified into the owner's vocabulary rather than left as prose, because
 * `TOKEN_REVOKED` and `PERMISSION_REVOKED` lead to two different conversations with two different
 * people.
 */
final class MetaSyncProbeCommand extends Command
{
    protected $signature = 'integrations:meta-probe {--sync : Run a real discovery and sync now, not only the read-only checks}';

    protected $description = 'Ask every connected Meta account whether it still answers: token, scopes, discovery, and optionally a real sync.';

    /** Meta's own codes, mapped to the classification an operator acts on. */
    private const CLASSIFY = [
        190 => 'TOKEN_REVOKED',
        102 => 'TOKEN_REVOKED',
        200 => 'PERMISSION_REVOKED',
        10 => 'APP_RESTRICTED',
        3 => 'APP_RESTRICTED',
        294 => 'BUSINESS_ACCESS_RESTRICTED',
    ];

    public function handle(): int
    {
        $creds = PlatformCredentials::for('meta');

        if ($creds->get('client_id') === null) {
            $this->error('BLOCKED_EXTERNAL_CREDENTIALS — no Meta client is configured on this install.');
            $this->line('Nothing here can reach Meta, so any verdict about syncing would be invented.');

            return self::FAILURE;
        }

        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->get();

        if ($connections->isEmpty()) {
            $this->line('No Meta connection exists on this install.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $this->probe($connection);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function probe(ProviderConnection $connection): void
    {
        $this->line('── Connection '.$connection->getKey().' · '.($connection->connection_name ?? '—'));
        $this->line('   stored status : '.$connection->status);
        $this->line('   last error    : '.($connection->last_error === null ? '—' : mb_substr($connection->last_error, 0, 160)));
        $this->line('   last success  : '.(optional($connection->last_discovery_succeeded_at)->toDateTimeString() ?? 'never'));

        $tokens = null;

        try {
            /*
             * `stored()`, never `fresh()`. The vault's `fresh()` will REFRESH an expiring token,
             * which is precisely the mutation this probe must not perform: it would report a state
             * that did not exist a moment before it ran, and destroy the evidence of the one that
             * did.
             */
            $tokens = app(TokenVault::class)->stored($connection);
        } catch (Throwable $e) {
            $this->error('   token         : UNREADABLE — '.mb_substr($e->getMessage(), 0, 120));
            $this->error('   verdict       : TOKEN_REVOKED (this install cannot produce a usable token)');

            return;
        }

        $base = (string) config('ad_platforms.platforms.meta.api_base');
        $http = PlatformHttp::client('meta')->withToken($tokens->accessToken);

        /*
         * `/me/permissions` is the question «what did the person actually grant», which is the one
         * the historical `(#200) Ad account owner has NOT grant ads_management or ads_read` answers.
         * It is also read-only and cannot rotate anything.
         */
        $permissions = $http->get($base.'/me/permissions');
        /** @var list<string> $granted */
        $granted = [];
        /** @var list<string> $declined */
        $declined = [];

        foreach ((array) ($permissions->json('data') ?? []) as $row) {
            $name = (string) ($row['permission'] ?? '');
            if ($name === '') {
                continue;
            }
            if ((string) ($row['status'] ?? '') === 'granted') {
                $granted[] = $name;
            } else {
                $declined[] = $name;
            }
        }

        $this->line('   HTTP          : '.$permissions->status());

        if ($permissions->failed()) {
            $this->report($permissions);

            return;
        }

        sort($granted);
        sort($declined);
        $this->line('   granted       : '.($granted === [] ? '(none)' : implode(', ', $granted)));
        $this->line('   declined      : '.($declined === [] ? '(none)' : implode(', ', $declined)));

        foreach (['ads_read', 'ads_management'] as $required) {
            $this->line('   '.str_pad($required, 14).': '.(in_array($required, $granted, true) ? 'GRANTED' : 'NOT GRANTED'));
        }

        if (! in_array('ads_read', $granted, true)) {
            $this->error('   verdict       : PERMISSION_REVOKED — this is the historical «(#200) … has NOT grant ads_management or ads_read», still true.');

            return;
        }

        /*
         * The account list, which is the first call that actually exercises `ads_read`. A token can
         * be valid and a permission granted while the ad account itself is unreachable, and that is
         * the case the platform-admin page cannot see.
         */
        $accounts = $http->get($base.'/me/adaccounts', ['fields' => 'account_id,name,account_status', 'limit' => 50]);
        $this->line('   adaccounts    : HTTP '.$accounts->status());

        if ($accounts->failed()) {
            $this->report($accounts);

            return;
        }

        $rows = (array) ($accounts->json('data') ?? []);
        $this->line('   discovered    : '.count($rows).' account(s)');

        foreach (array_slice($rows, 0, 10) as $row) {
            $this->line('     · '.($row['account_id'] ?? '?').'  '.mb_substr((string) ($row['name'] ?? '—'), 0, 40).'  status='.($row['account_status'] ?? '?'));
        }

        if (! $this->option('sync')) {
            $this->info('   verdict       : the account answers. Re-run with --sync to write and prove fresh rows.');

            return;
        }

        $this->forceSync($connection);
    }

    /**
     * The product's OWN discovery and sync, not a second implementation.
     *
     * A probe that fetched rows its own way would prove that Meta answers and nothing about whether
     * CampaignsHub can store what it answers, which is the half the owner is asking about.
     */
    private function forceSync(ProviderConnection $connection): void
    {
        $startedAt = Carbon::now();
        $this->line('   sync start    : '.$startedAt->toDateTimeString());

        try {
            $result = app(AccountDiscovery::class)->refresh($connection);
            $this->line('   discovery     : discovered '.$result['discovered'].', created '.$result['created'].', named '.$result['named'].', access lost '.$result['access_lost']);
        } catch (Throwable $e) {
            $this->error('   discovery     : FAILED — '.mb_substr($e->getMessage(), 0, 200));
            $this->error('   verdict       : see the connection‘s last_error above for Meta‘s own words.');

            return;
        }

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->pluck('external_id');

        $this->line('   sync end      : '.Carbon::now()->toDateTimeString());
        $this->line('   accounts held : '.$accounts->count());
        $this->comment('   Rows written by the sync are counted where they land: run');
        $this->comment('     php artisan integrations:diagnose-sync --provider=meta');
        $this->comment('   which reports campaigns, metric rows and the latest freshness timestamp per account.');
    }

    /**
     * Meta's own five fields, which nothing in this codebase was reading.
     *
     * `code` and `error_subcode` are what separate «expired» from «revoked» from «app restricted»,
     * and `fbtrace_id` is the reference Meta's support asks for. All identifiers; no secret is read
     * here and the token is never printed.
     */
    private function report(Response $response): void
    {
        /** @var array<string,mixed> $error */
        $error = (array) ($response->json('error') ?? []);

        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;

        /*
         * One fact per line. `error()` wraps at the terminal width, and a wrap lands wherever it
         * lands — through the middle of «subcode 463» as often as not, which is the field that
         * separates an expired session from a revoked one and the field an operator is scanning for.
         * The same wrap hid a field in the Google probe before this one was written.
         */
        $this->error('   meta code     : '.($code ?? '—'));
        $this->error('   meta subcode  : '.($subcode ?? '—'));
        $this->error('   meta type     : '.((string) ($error['type'] ?? '—')));
        $this->error('   meta message  : '.mb_substr((string) ($error['message'] ?? '—'), 0, 200));
        $this->error('   fbtrace_id    : '.((string) ($error['fbtrace_id'] ?? '—')));

        $verdict = self::CLASSIFY[$code] ?? 'BLOCKED_EXTERNAL_CREDENTIALS';

        /*
         * Subcode 458/463 are «app not installed» and «session expired»: both are the person's token,
         * not the app's configuration, and calling them APP_RESTRICTED would send somebody to the
         * wrong screen.
         */
        if (in_array($subcode, [458, 463, 467], true)) {
            $verdict = 'TOKEN_REVOKED';
        }

        $this->error('   verdict       : '.$verdict);
    }
}
