<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACCOUNT-SCOPE-ISOLATION-002 — does every stored campaign belong to the account it is filed under?
 *
 * `integrations:scope-audit` answers a different question: whether a row's ACCOUNT is bound to the
 * project that holds the row. On 2026-11-01 every row passed that check and a client report still
 * showed another advertiser's campaigns, because the rows had been written under the right account
 * from the WRONG credential — a ChatGPT Ads key scoped to someone else. The binding was fine; the
 * content was not.
 *
 * This command reads the content:
 *
 *   1. A provider campaign id belongs to exactly one ad account. The same `external_id` filed under
 *      two accounts of one provider is at most one legitimate copy and at least one that is not —
 *      listed per copy with its project and how many figures hang off it.
 *   2. A figure dated after today was not measured. Listed per account.
 *   3. The same provider account under two account rows, or bound to two projects: sharing that nobody
 *      declared. Listed for a person to judge, for every provider.
 *   4. `--probe` asks each connected ChatGPT Ads connection which account its key answers for NOW and
 *      classifies the account rows on it as OWNED or FOREIGN. The only provider call here, read-only.
 *      It says nothing about who owns rows already stored: a connection re-keyed yesterday answers
 *      for yesterday's advertiser, so a stored row under a FOREIGN account may be perfectly legitimate.
 *   5. `--apply --keep-account=<our account id>` quarantines the other copies of THAT account's
 *      campaigns: each copy under a different provider account, with its ad sets, ads, creatives and
 *      figures, copied whole into `isolation_quarantines` under one batch id and then removed from the
 *      live tables, in one transaction. The owner is NAMED by a person who has seen the evidence, never
 *      inferred: the current key cannot prove it, and the wrong guess takes a customer's own data out
 *      of their own project. A copy under the same provider account (another row of the same
 *      advertiser) is left alone and reported. Nothing is moved to another project and nothing merged.
 *   6. `--restore=<batch>` puts a batch back, row for row.
 *
 * Without `--apply` or `--restore` the command writes nothing (`AccountOwnershipAuditCommandTest`
 * holds a digest of every table). It prints internal ids, provider keys, counts and dates — never an
 * account name, a key or a url.
 */
final class AccountOwnershipAuditCommand extends Command
{
    protected $signature = 'integrations:account-ownership-audit
        {--provider= : Limit to one provider key}
        {--probe : Ask each ChatGPT Ads connection which account its key answers for (read-only provider call)}
        {--apply : With --keep-account: quarantine the other copies of that account\'s campaigns (copied to isolation_quarantines, then removed)}
        {--keep-account= : Our id of the account a person has established as the owner of its campaigns}
        {--restore= : Put a quarantine batch back, by batch id}';

    protected $description = 'Read-only unless --apply/--restore: campaigns filed under an account their credential does not answer for.';

    /**
     * Children before parents. Deleting an ad set cascades to its ads, so taking ad sets first would
     * delete the ads before they had been copied — lost, not quarantined.
     */
    private const TABLES_OF_A_CAMPAIGN = [
        'daily_metrics', 'entity_daily_metrics', 'external_ads', 'external_ad_sets', 'external_creatives',
    ];

    public function __construct(private readonly AdvertisingConnectorRegistry $registry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (is_string($this->option('restore')) && $this->option('restore') !== '') {
            return $this->restore((string) $this->option('restore'));
        }

        $provider = $this->option('provider') ?: null;

        $this->line('==============================================================================');
        $this->line('  ACCOUNT-OWNERSHIP AUDIT — '.($this->option('apply') ? 'APPLY: quarantining foreign copies' : 'read-only').'.');
        $this->line('  ids and counts only: internal uuids, provider keys, dates.');
        $this->line('==============================================================================');

        $duplicates = $this->duplicateCampaigns($provider);
        $this->printDuplicates($duplicates);
        $this->printFutureDated($provider);

        $this->printSharedAccounts($provider);

        if ($this->option('probe')) {
            $this->probeKeyOwnership($provider);
        }

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $keep = $this->option('keep-account');

        if (! is_string($keep) || $keep === '') {
            $this->error('  --apply needs --keep-account: nothing is quarantined on a guess about who owns a campaign.');

            return self::FAILURE;
        }

        return $this->quarantine($keep, $duplicates);
    }

    // ── 1. duplicate campaigns ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, list<object>> "provider|external_id" → the copies
     */
    private function duplicateCampaigns(?string $provider): array
    {
        $keys = DB::table('external_campaigns')
            ->select('provider', 'external_id')
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->groupBy('provider', 'external_id')
            ->havingRaw('COUNT(DISTINCT external_account_id) > 1')
            ->get();

        $out = [];

        foreach ($keys as $key) {
            $copies = DB::table('external_campaigns AS c')
                ->leftJoin('daily_metrics AS m', 'm.external_campaign_id', '=', 'c.id')
                ->where('c.provider', $key->provider)
                ->where('c.external_id', $key->external_id)
                ->groupBy('c.id', 'c.external_account_id', 'c.project_id', 'c.provider', 'c.external_id', 'c.unified_campaign_id')
                ->selectRaw('c.id, c.external_account_id, c.project_id, c.provider, c.external_id, c.unified_campaign_id, COUNT(m.id) AS figures, MIN(m.metric_date) AS first_day, MAX(m.metric_date) AS last_day')
                ->orderBy('c.external_account_id')
                ->get()
                ->all();

            $out[$key->provider.'|'.$key->external_id] = $copies;
        }

        return $out;
    }

    /** @param array<string, list<object>> $duplicates */
    private function printDuplicates(array $duplicates): void
    {
        $this->line('------------------------------------------------------------------------------');
        $this->line('  1. CAMPAIGNS FILED UNDER MORE THAN ONE ACCOUNT — a provider campaign has ONE account');
        $this->line('     provider campaign ids under two or more accounts : '.count($duplicates));

        $n = 0;

        foreach ($duplicates as $key => $copies) {
            [$provider] = explode('|', $key, 2);
            $this->line("     · [{$provider}] campaign #".(++$n).' — '.count($copies).' copies');

            foreach ($copies as $copy) {
                $this->line(sprintf(
                    '         account %s  project %s  row %s  figures %d  %s → %s',
                    $copy->external_account_id,
                    $copy->project_id ?? '—',
                    $copy->id,
                    (int) $copy->figures,
                    $copy->first_day ?? '—',
                    $copy->last_day ?? '—',
                ));
            }
        }
    }

    // ── 2. future-dated figures ──────────────────────────────────────────────────────────────────

    private function printFutureDated(?string $provider): void
    {
        $today = Carbon::now('UTC')->toDateString();
        $this->line('------------------------------------------------------------------------------');
        $this->line("  2. FIGURES DATED AFTER TODAY ({$today}) — a day nobody could have measured");

        foreach (['daily_metrics', 'entity_daily_metrics'] as $table) {
            $rows = DB::table($table)
                ->where('metric_date', '>', $today)
                ->when($provider, fn ($q) => $q->where('provider', $provider))
                ->groupBy('provider', 'external_account_id', 'project_id')
                ->selectRaw('provider, external_account_id, project_id, COUNT(*) AS rows_found, MAX(metric_date) AS latest')
                ->orderByDesc('rows_found')
                ->get();

            $this->line("     {$table}: ".$rows->sum('rows_found').' row(s)');

            foreach ($rows as $row) {
                $this->line(sprintf('       · [%s] account %s  project %s  rows %d  latest %s',
                    $row->provider, $row->external_account_id ?? '—', $row->project_id ?? '—', (int) $row->rows_found, $row->latest));
            }
        }
    }

    // ── 3. undeclared sharing ────────────────────────────────────────────────────────────────────

    private function printSharedAccounts(?string $provider): void
    {
        $this->line('------------------------------------------------------------------------------');
        $this->line('  3. ONE PROVIDER ACCOUNT, MORE THAN ONE PROJECT — sharing nobody declared');

        $rows = DB::table('external_accounts AS a')
            ->join('project_integration_bindings AS b', 'b.external_account_id', '=', 'a.id')
            ->where('a.account_type', 'ad_account')
            ->where('b.is_active', true)
            ->when($provider, fn ($q) => $q->where('a.provider', $provider))
            ->selectRaw('a.provider, a.external_id, a.id AS account_id, a.tenant_id, a.provider_connection_id, b.project_id')
            ->orderBy('a.provider')
            ->orderBy('a.external_id')
            ->get()
            ->groupBy(static fn (object $r): string => $r->provider.'|'.$r->external_id)
            ->filter(static fn ($group): bool => $group->pluck('project_id')->unique()->count() > 1);

        $this->line('     provider accounts active in two or more projects : '.$rows->count());

        $n = 0;

        foreach ($rows as $key => $group) {
            [$providerKey] = explode('|', (string) $key, 2);
            $this->line("     · [{$providerKey}] shared account #".(++$n));

            foreach ($group as $row) {
                $this->line(sprintf('         account %s  connection %s  tenant %s  project %s',
                    $row->account_id, $row->provider_connection_id, $row->tenant_id, $row->project_id));
            }
        }

        $this->line('     estate: tenants '.DB::table('tenants')->count()
            .' · projects '.DB::table('projects')->count()
            .' · connections '.DB::table('provider_connections')->count()
            .' · ad accounts '.DB::table('external_accounts')->where('account_type', 'ad_account')->count()
            .' · active bindings '.DB::table('project_integration_bindings')->where('is_active', true)->count()
            .' · campaigns '.DB::table('external_campaigns')->count());
    }

    // ── 4. key ownership ─────────────────────────────────────────────────────────────────────────

    /** Read-only, and a statement about the key as it stands NOW — see the class comment. */
    private function probeKeyOwnership(?string $provider): void
    {
        $this->line('------------------------------------------------------------------------------');
        $this->line('  4. KEY OWNERSHIP — which account each ChatGPT Ads credential answers for (one read per connection)');

        if ($provider !== null && $provider !== 'openai_ads') {
            $this->line('     (only ChatGPT Ads is key-scoped; nothing to probe for '.$provider.')');

            return;
        }

        $foreignRows = 0;
        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('provider', 'openai_ads')
            ->where('status', 'connected')
            ->orderBy('created_at')
            ->get();

        foreach ($connections as $connection) {
            $connector = $this->registry->get('openai_ads');

            if (! $connector instanceof ApiAdvertisingConnector) {
                $this->line('     no ChatGPT Ads connector is registered');

                return;
            }

            try {
                $owned = (string) ($connector->withConnection($connection)->listAdAccounts()[0]['external_id'] ?? '');
            } catch (\Throwable $e) {
                $this->line("     · connection {$connection->id}: the provider refused the key — ".ProviderErrorText::forDisplay($e->getMessage()));

                continue;
            }

            $accounts = ExternalAccount::withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->where('account_type', 'ad_account')
                ->orderBy('created_at')
                ->get();

            $this->line("     · connection {$connection->id}: key answers for provider account ".($owned === '' ? '(none)' : $this->short($owned)).'; '.$accounts->count().' account row(s)');

            foreach ($accounts as $account) {
                $isOwned = $owned !== '' && $account->external_id === $owned;
                $this->line(sprintf('         %-8s account %s  provider id %s  access_lost_at %s',
                    $isOwned ? 'OWNED' : 'FOREIGN', $account->id, $this->short($account->external_id), $account->access_lost_at?->toDateTimeString() ?? '—'));

                if (! $isOwned) {
                    $foreignRows++;
                }
            }
        }

        $this->line('     foreign account rows: '.$foreignRows);
    }

    // ── 5. quarantine ────────────────────────────────────────────────────────────────────────────

    /** @param  array<string, list<object>>  $duplicates */
    private function quarantine(string $keepAccountId, array $duplicates): int
    {
        $this->line('------------------------------------------------------------------------------');
        $this->line('  5. QUARANTINE — other copies of the named owner\'s campaigns, whole, into isolation_quarantines');

        $owner = ExternalAccount::withoutGlobalScopes()->find($keepAccountId);

        if ($owner === null) {
            $this->error("  no account {$keepAccountId}");

            return self::FAILURE;
        }

        $providerIdOf = ExternalAccount::withoutGlobalScopes()->pluck('external_id', 'id');
        $campaignIds = [];
        $sameAdvertiser = 0;

        foreach ($duplicates as $copies) {
            $held = array_filter($copies, static fn (object $c): bool => (string) $c->external_account_id === $keepAccountId);

            if ($held === []) {
                continue;
            }

            foreach ($copies as $copy) {
                if ((string) $copy->external_account_id === $keepAccountId) {
                    continue;
                }

                // Another row of the same advertiser is sharing to judge, not a foreign copy.
                if (($providerIdOf[(string) $copy->external_account_id] ?? null) === $owner->external_id) {
                    $sameAdvertiser++;

                    continue;
                }

                $campaignIds[] = (string) $copy->id;
            }
        }

        $campaignIds = array_values(array_unique($campaignIds));
        $this->line("     copies under the same provider account, left alone : {$sameAdvertiser}");

        if ($campaignIds === []) {
            $this->line('     nothing to quarantine: no other account holds a copy of this account\'s campaigns.');

            return self::SUCCESS;
        }

        $batch = (string) Str::uuid();
        $reason = 'ACCOUNT-SCOPE-ISOLATION-002: copy of a campaign of account '.$keepAccountId;
        $counts = [];

        DB::transaction(function () use ($campaignIds, $batch, $reason, &$counts): void {
            $creativeIds = DB::table('external_creatives')->whereIn('external_campaign_id', $campaignIds)->pluck('id')->all();

            $counts['creative_daily_metrics'] = $this->moveOut('creative_daily_metrics', fn ($q) => $q->whereIn('creative_id', $creativeIds), $batch, $reason);

            foreach (self::TABLES_OF_A_CAMPAIGN as $table) {
                $counts[$table] = $this->moveOut($table, fn ($q) => $q->whereIn('external_campaign_id', $campaignIds), $batch, $reason);
            }

            $unified = DB::table('external_campaigns')->whereIn('id', $campaignIds)->whereNotNull('unified_campaign_id')->pluck('unified_campaign_id')->unique()->all();

            $counts['external_campaigns'] = $this->moveOut('external_campaigns', fn ($q) => $q->whereIn('id', $campaignIds), $batch, $reason);

            // A unified campaign that was only ever the shell of a foreign copy goes with it; one still carrying this project's own rows stays.
            $orphans = array_values(array_filter($unified, static fn ($u): bool => DB::table('external_campaigns')->where('unified_campaign_id', $u)->doesntExist()
                && DB::table('influencer_collaborations')->where('unified_campaign_id', $u)->doesntExist()));
            $counts['unified_campaigns'] = $this->moveOut('unified_campaigns', fn ($q) => $q->whereIn('id', $orphans), $batch, $reason);
        });

        $this->line("     batch {$batch}");

        foreach ($counts as $table => $n) {
            $this->line(sprintf('     %-40s %d', $table, $n));
        }

        $this->line("     restore with: php artisan integrations:account-ownership-audit --restore={$batch}");

        return self::SUCCESS;
    }

    /** Copy the matching rows into the quarantine, then delete them. Returns how many. */
    private function moveOut(string $table, callable $where, string $batch, string $reason): int
    {
        $rows = $where(DB::table($table))->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $now = Carbon::now();

        DB::table('isolation_quarantines')->insert($rows->map(static fn (object $row): array => [
            'id' => (string) Str::uuid(),
            'batch' => $batch,
            'reason' => $reason,
            'table_name' => $table,
            'row_id' => (string) $row->id,
            'payload' => json_encode((array) $row, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ])->all());

        return DB::table($table)->whereIn('id', $rows->pluck('id')->all())->delete();
    }

    // ── 6. restore ───────────────────────────────────────────────────────────────────────────────

    private function restore(string $batch): int
    {
        $rows = DB::table('isolation_quarantines')->where('batch', $batch)->whereNull('restored_at')->get();

        if ($rows->isEmpty()) {
            $this->error("  no unrestored rows in batch {$batch}");

            return self::FAILURE;
        }

        // Parents before children, so the foreign keys hold.
        $order = ['unified_campaigns', 'external_campaigns', 'external_ad_sets', 'external_ads', 'external_creatives',
            'daily_metrics', 'entity_daily_metrics', 'creative_daily_metrics'];

        DB::transaction(function () use ($rows, $order, $batch): void {
            foreach ($order as $table) {
                $payloads = $rows->where('table_name', $table)
                    ->map(static fn (object $r): array => json_decode((string) $r->payload, true, 512, JSON_THROW_ON_ERROR))
                    ->values()
                    ->all();

                if ($payloads !== []) {
                    DB::table($table)->insertOrIgnore($payloads);
                    $this->line(sprintf('     %-28s restored %d', $table, count($payloads)));
                }
            }

            DB::table('isolation_quarantines')->where('batch', $batch)->update(['restored_at' => Carbon::now()]);
        });

        $this->line("  batch {$batch} restored.");

        return self::SUCCESS;
    }

    private function short(string $id): string
    {
        return mb_strlen($id) <= 12 ? $id : mb_substr($id, 0, 8).'…'.mb_substr($id, -4);
    }
}
