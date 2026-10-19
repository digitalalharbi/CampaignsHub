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
use Illuminate\Http\Client\PendingRequest;
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

    protected $description = 'Ask every connected Meta account whether it still answers: token, scopes, the user and business account edges, and optionally a real sync.';

    /** The same ceiling `MetaConnector::readAll()` puts on a paged edge. */
    private const MAX_PAGES = 50;

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
         *
         * Read to its END, exactly as `MetaConnector::fetchAdAccounts()` reads it — same edge, same
         * fields, same page size, every `paging.next` followed. A probe that read one page of fifty
         * would answer «17 accounts» about an estate of two hundred and the reader would conclude that
         * discovery, which pages, was the thing dropping rows.
         */
        $pages = 0;
        $rows = $this->readEdge($http, $base.'/me/adaccounts', ['fields' => 'account_id,account_status', 'limit' => 200], 'adaccounts', $pages);

        if ($rows === null) {
            return;
        }

        $this->line('   discovered    : '.count($rows).' account(s) via me/adaccounts, '.$pages.' page(s)');

        $viaUser = $this->accountIds($rows);
        $this->line('   me/adaccounts : '.($viaUser === [] ? '(none)' : implode(', ', $viaUser)));

        $viaBusiness = $this->businessAssets($http, $base, $granted, $viaUser);
        $this->storedAccounts($connection, $viaUser, $viaBusiness);

        if (! $this->option('sync')) {
            $this->info('   verdict       : the account answers. Re-run with --sync to write and prove fresh rows.');

            return;
        }

        $this->forceSync($connection);
    }

    /**
     * META-PARTNER-ACCOUNT-DISCOVERY-001 — the accounts a BUSINESS can reach that the USER's edge does not list.
     *
     * `me/adaccounts` is the only edge discovery reads, and it answers for the PERSON who authorised:
     * the ad accounts that person holds a role on. An ad account another company shares with
     * CampaignsHub's Business Manager as a Partner is reachable through the business's own edges —
     * `{business}/client_ad_accounts` (shared in) and `{business}/owned_ad_accounts` (its own) — and
     * reaches `me/adaccounts` only once that person is also assigned to the asset inside the
     * business. So one Partner-shared account can appear while another, shared the same way, does not,
     * and nothing in the user edge says why.
     *
     * This prints the two sets and their DIFFERENCE, as account ids only. A business id is an
     * identifier Meta's support asks for; a business NAME is a customer's and never reaches the log.
     * Nothing is written and nothing is decided here: discovery is left exactly as it is until a real
     * answer says which edge holds the missing account.
     *
     * @param  list<string>  $granted
     * @param  list<string>  $viaUser
     * @return list<string> every account id a business edge listed (empty when the edges were not askable)
     */
    private function businessAssets(PendingRequest $http, string $base, array $granted, array $viaUser): array
    {
        $this->line('   ── business assets');

        if (! in_array('business_management', $granted, true)) {
            $this->line('   businesses    : NOT ASKABLE — business_management is not granted on this token, so the business');
            $this->line('                   edges cannot be read. An ad account reachable only through a Business Manager');
            $this->line('                   partner assignment is invisible to this token until the person is assigned to it.');

            return [];
        }

        $pages = 0;
        $businesses = $this->readEdge($http, $base.'/me/businesses', ['fields' => 'id', 'limit' => 200], 'businesses', $pages);

        if ($businesses === null) {
            return [];
        }

        $this->line('   businesses    : '.count($businesses).' reachable by this person');

        /** @var array<string, list<string>> $viaBusiness account id → where it was found */
        $viaBusiness = [];

        foreach ($businesses as $business) {
            $id = (string) ($business['id'] ?? '');
            if ($id === '') {
                continue;
            }

            foreach (['owned_ad_accounts', 'client_ad_accounts'] as $edge) {
                $pages = 0;
                $rows = $this->readEdge($http, $base.'/'.$id.'/'.$edge, ['fields' => 'account_id,account_status', 'limit' => 200], $edge, $pages);

                if ($rows === null) {
                    $this->line('     · business '.$id.' '.str_pad($edge, 18).': (refused — see above)');

                    continue;
                }

                $ids = $this->accountIds($rows);
                $this->line('     · business '.$id.' '.str_pad($edge, 18).': '.count($ids).' account(s)'.($ids === [] ? '' : ' — '.implode(', ', $ids)));

                foreach ($rows as $row) {
                    $accountId = (string) ($row['account_id'] ?? '');
                    if ($accountId === '') {
                        continue;
                    }
                    $viaBusiness[$accountId][] = $edge.' of business '.$id.' (account_status='.($row['account_status'] ?? '?').')';
                }
            }
        }

        $onlyBusiness = array_diff(array_keys($viaBusiness), $viaUser);
        $onlyUser = array_diff($viaUser, array_keys($viaBusiness));

        // Not on the person's own edge. Discovery ALSO reads the business edges (see MetaConnector), so this
        // is where an account is found, not a claim that it is missed — the stored section below says which.
        $this->line('   in a business, NOT in me/adaccounts : '.count($onlyBusiness).' account(s)'.($onlyBusiness === [] ? '' : ' ← not on the person\'s own edge'));

        foreach ($onlyBusiness as $accountId) {
            foreach ($viaBusiness[$accountId] as $where) {
                $this->line('     · '.$accountId.'  via '.$where);
            }
        }

        $this->line('   in me/adaccounts, NOT in a business : '.count($onlyUser).' account(s)'.($onlyUser === [] ? '' : ' — '.implode(', ', $onlyUser)));

        return array_values(array_map('strval', array_keys($viaBusiness)));
    }

    /**
     * What this install HOLDS for the connection, beside what Meta answers right now.
     *
     * The two can disagree in both directions: a row discovered under an earlier grant that the
     * person has since lost (`access_lost_at` set), and an account Meta lists that no sweep has filed
     * yet. Ids only.
     *
     * @param  list<string>  $viaUser
     * @param  list<string>  $viaBusiness  account ids the business edges listed
     */
    private function storedAccounts(ProviderConnection $connection, array $viaUser, array $viaBusiness = []): void
    {
        $stored = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->get(['external_id', 'access_lost_at']);

        $held = [];
        $lost = [];

        foreach ($stored as $account) {
            $id = preg_replace('/^act_/', '', (string) $account->external_id) ?? '';
            $held[] = $id;
            if ($account->access_lost_at !== null) {
                $lost[] = $id;
            }
        }

        $this->line('   ── stored on this install');
        $this->line('   stored        : '.count($held).' account(s), '.count($lost).' marked access lost'.($lost === [] ? '' : ' — '.implode(', ', $lost)));

        $unfiled = array_diff($viaUser, $held);
        $this->line('   listed by Meta, not stored yet      : '.count($unfiled).' account(s)'.($unfiled === [] ? '' : ' — '.implode(', ', $unfiled)));

        /*
         * META-PARTNER-ACCOUNT-DISCOVERY-001 — the question the user-edge line above cannot answer: of the
         * accounts only a BUSINESS edge lists (Partner-shared in, or owned), does this install hold each one?
         * Ids only; a business NAME is a customer's and never reaches the log.
         */
        $businessOnly = array_values(array_diff($viaBusiness, $viaUser));
        $businessHeld = array_values(array_intersect($businessOnly, $held));
        $businessMissing = array_values(array_diff($businessOnly, $held));
        $this->line('   business-edge accounts stored       : '.count($businessHeld).' of '.count($businessOnly).($businessHeld === [] ? '' : ' — '.implode(', ', $businessHeld)));
        $this->line('   business-edge accounts NOT stored   : '.count($businessMissing).' account(s)'.($businessMissing === [] ? '' : ' — '.implode(', ', $businessMissing)));
    }

    /**
     * Read one Graph edge to its end — `paging.next` followed, as discovery follows it.
     *
     * Returns null when the edge refused, after printing Meta's own five fields; the caller decides
     * whether that ends the probe (the user edge) or only that line (a business edge).
     *
     * @param  array<string, string|int>  $query
     * @return list<array<string,mixed>>|null
     */
    private function readEdge(PendingRequest $http, string $url, array $query, string $what, int &$pages): ?array
    {
        $rows = [];
        $pages = 0;
        $next = $url;

        for ($page = 0; $page < self::MAX_PAGES && $next !== null; $page++) {
            $response = $page === 0 ? $http->get($next, $query) : $http->get($next);
            $pages++;

            if ($response->failed()) {
                $this->line('   '.str_pad($what, 14).': HTTP '.$response->status());
                $this->report($response);

                return null;
            }

            foreach ((array) ($response->json('data') ?? []) as $row) {
                $rows[] = (array) $row;
            }

            $after = $response->json('paging.next');
            $next = is_string($after) && $after !== '' ? $after : null;
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    private function accountIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = (string) ($row['account_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
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
