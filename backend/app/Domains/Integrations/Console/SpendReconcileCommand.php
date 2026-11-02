<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Support\SpendReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPEND-ACCURACY-001 — asks the PROVIDER for an account's spend over a window and sets it beside what
 * we stored for the same account, day by day and campaign by campaign. Read-only: a GET to the
 * provider and SELECTs here, nothing written. Prints ids and amounts, never names or tokens.
 */
final class SpendReconcileCommand extends Command
{
    protected $signature = 'integrations:spend-reconcile
        {account : The external account — ours, or the provider\'s own id}
        {--from= : Window start, YYYY-MM-DD (default: 30 days back)}
        {--to= : Window end, YYYY-MM-DD (default: today)}
        {--top=15 : Campaigns with the largest difference to print}';

    protected $description = 'Read-only: provider spend vs stored spend for one account and window, with the differences.';

    public function handle(AdvertisingConnectorRegistry $registry): int
    {
        $ref = (string) $this->argument('account');

        $account = ExternalAccount::withoutGlobalScopes()
            ->where(function ($q) use ($ref): void {
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1) {
                    $q->where('id', $ref)->orWhere('external_id', $ref);

                    return;
                }
                $q->where('external_id', $ref);
            })
            ->where('account_type', 'ad_account')
            ->first();

        if ($account === null) {
            $this->error("No ad account matches '{$ref}'.");

            return self::FAILURE;
        }

        $from = (string) ($this->option('from') ?: now()->subDays(30)->toDateString());
        $to = (string) ($this->option('to') ?: now()->toDateString());

        $connector = $registry->get($account->provider);
        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);
            if ($connection === null) {
                $this->error('That account has no provider connection to ask through.');

                return self::FAILURE;
            }
            $connector = $connector->withConnection($connection);
        }
        if ($connector === null || $connector->status() === ConnectorStatus::AwaitingCredentials) {
            $this->error('No usable connector for this provider; nothing was asked.');

            return self::FAILURE;
        }

        try {
            $result = $connector->syncInsights($account->external_id, $from, $to);
        } catch (Throwable $e) {
            $this->error('The provider call threw: '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $result->success) {
            $this->error('The provider refused: '.($result->message ?? 'no message'));

            return self::FAILURE;
        }

        $provider = [];
        foreach ($result->records as $r) {
            $r = (array) $r;
            $provider[] = ['campaign_id' => (string) ($r['campaign_id'] ?? ''), 'date' => (string) ($r['date'] ?? ''), 'spend' => $r['spend'] ?? 0];
        }

        $rows = DB::table('daily_metrics as m')
            ->leftJoin('external_campaigns as c', 'c.id', '=', 'm.external_campaign_id')
            ->where('m.external_account_id', $account->getKey())
            ->where('m.metric_key', 'spend')
            ->whereBetween('m.metric_date', [$from, $to])
            ->get(['m.metric_date', 'm.project_id', 'm.value', 'm.original_amount', 'm.original_currency', 'm.project_currency', 'c.external_id as campaign']);

        /*
         * Like with like: the provider answers in the ACCOUNT's currency, and `original_amount` is that same figure as stored.
         * `value` is the project-currency conversion — comparing it to the provider read a SAR account's TikTok and Google spend
         * as 73% «missing» when every riyal was there (1 ÷ 3.75). The converted total is printed beside it, never compared.
         */
        $stored = [];
        $projects = [];
        $converted = 0.0;
        $currencies = [];
        foreach ($rows as $r) {
            $original = $r->original_amount !== null ? (float) $r->original_amount : (float) $r->value;
            $stored[] = ['campaign_id' => (string) $r->campaign, 'date' => substr((string) $r->metric_date, 0, 10), 'spend' => $original];
            $projects[(string) $r->project_id] = ($projects[(string) $r->project_id] ?? 0) + 1;
            $converted += (float) $r->value;
            $currencies[(string) ($r->original_currency ?? '?')] = true;
        }

        $cmp = SpendReconciliation::compare($provider, $stored);

        $this->line(str_repeat('=', 78));
        $this->line('  SPEND RECONCILE — read-only; nothing is stored by this command.');
        $this->line(sprintf('  %s [%s]  window %s → %s  account currency %s  timezone %s', substr((string) $account->getKey(), 0, 8), $account->provider, $from, $to, $account->currency ?? '?', $account->timezone ?? '?'));
        $this->line(sprintf('  provider rows %d · stored rows %d · projects holding stored rows: %s', count($provider), count($stored), json_encode(array_map(static fn ($p) => substr($p, 0, 8), array_keys($projects)))));
        $this->line(sprintf('  PROVIDER TOTAL %.2f · STORED TOTAL %.2f (both in the account currency %s) · difference %+.2f (%s%%) · stored converted to the project currency: %.2f', $cmp['provider_total'], $cmp['stored_total'], implode('/', array_keys($currencies)) ?: '?', $cmp['diff'], $cmp['diff_pct'] === null ? 'n/a' : sprintf('%+.2f', $cmp['diff_pct']), $converted));
        $this->line('');
        $this->line(sprintf('  %-12s %12s %12s %12s', 'day', 'provider', 'stored', 'diff'));
        foreach ($cmp['days'] as $d) {
            $this->line(sprintf('  %-12s %12.2f %12.2f %+12.2f%s', $d['date'], $d['provider'], $d['stored'], $d['diff'], abs($d['diff']) >= 0.01 ? '  <<' : ''));
        }
        $this->line('');
        $this->line('  campaigns with the largest difference:');
        foreach (array_slice($cmp['campaigns'], 0, max(1, (int) $this->option('top'))) as $c) {
            $this->line(sprintf('  %-38s provider %10.2f stored %10.2f diff %+10.2f', substr($c['campaign_id'], 0, 36), $c['provider'], $c['stored'], $c['diff']));
        }
        $this->line(sprintf('  only at the provider: %d · only in our store: %d', count($cmp['only_provider']), count($cmp['only_stored'])));

        return self::SUCCESS;
    }
}
