<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\InsightPayloadRows;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;

/**
 * INTEG-RUNTIME §7 — «why is the answer 0?», answered with numbers instead of a theory.
 *
 * ## What this is for
 *
 * A live Snapchat connection reached 309 accounts and produced no metrics. `metrics_upserted = 0` is
 * where every investigation stopped, because that one figure is equally true of four different
 * situations with four different fixes:
 *
 *   the provider sent nothing           → NO_DATA. Not a defect. Nothing to fix.
 *   the provider sent rows, we parsed 0 → a connector defect, in the parse.
 *   we parsed rows, mapped 0            → structure was never discovered, or discovery is behind.
 *   we mapped rows, stored 0            → the rows carried no metric this pipeline keeps.
 *
 * This prints the four counts side by side for each of an account's recent runs, so which of those
 * four it is stops being a matter of opinion.
 *
 * ## Read-only, and that is a promise not a habit
 *
 * It calls no provider, refreshes no token, queues no job and writes no row. Everything it reports
 * comes from what is already stored — the run log and the provider's own retained bodies. It is safe
 * to point at production precisely because there is nothing it can break there.
 *
 * Runs whose counters are NULL predate the instrumentation; for those the numbers are recovered from
 * the retained payload by {@see InsightPayloadRows} and labelled as reconstructed, never presented as
 * though they had been measured at the time.
 */
final class DiagnoseSyncCommand extends Command
{
    protected $signature = 'integrations:diagnose
        {--provider= : Limit to one provider key, e.g. snapchat}
        {--account= : Limit to one external account id (ours) or the provider\'s own id}
        {--runs=5 : How many recent runs to show per account}
        {--accounts=10 : How many accounts to show}';

    protected $description = 'Read-only: where a sync\'s rows stopped, per account, with the four counts.';

    public function handle(): int
    {
        $provider = $this->stringOption('provider');
        $accountRef = $this->stringOption('account');
        $runLimit = max(1, (int) $this->option('runs'));
        $accountLimit = max(1, (int) $this->option('accounts'));

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->when($accountRef !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('id', $accountRef)->orWhere('external_id', $accountRef),
            ))
            ->orderByDesc('last_sync_attempt_at')
            ->orderByDesc('discovered_at')
            ->limit($accountLimit)
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No external account matches that filter.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  INTEGRATIONS DIAGNOSIS — read-only. No provider was called.');
        $this->line(str_repeat('=', 78));

        $this->reportProviderTotals($provider);

        foreach ($accounts as $account) {
            $this->reportAccount($account, $runLimit);
        }

        return self::SUCCESS;
    }

    /** The shape of the estate, before any single account: how much was discovered, how much assigned. */
    private function reportProviderTotals(?string $provider): void
    {
        $discovered = ExternalAccount::withoutGlobalScopes()
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->count();

        $assigned = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereIn(
                'external_account_id',
                ExternalAccount::withoutGlobalScopes()
                    ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
                    ->select('id'),
            )
            ->count();

        $this->line('');
        $this->line(sprintf(
            '  Estate%s: %d account(s) discovered, %d with an ACTIVE binding to a project.',
            $provider === null ? '' : " [{$provider}]",
            $discovered,
            $assigned,
        ));
    }

    private function reportAccount(ExternalAccount $account, int $runLimit): void
    {
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('is_active', true)
            ->first();

        $projectName = $binding === null
            ? null
            : Project::withoutGlobalScopes()->whereKey($binding->project_id)->value('name');

        $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->count();

        $this->line('');
        $this->line(str_repeat('-', 78));
        $this->line(sprintf('  %s  [%s]', $account->name ?: '(unnamed)', $account->provider));
        $this->line(sprintf('  ours=%s  provider=%s', $account->getKey(), $account->external_id));
        $this->line(sprintf(
            '  connection=%s  timezone=%s  currency=%s',
            $connection === null ? 'MISSING' : $connection->status,
            $account->timezone ?? 'NOT CAPTURED',
            $account->currency ?? 'NOT CAPTURED',
        ));
        $this->line(sprintf(
            '  binding=%s  campaigns discovered=%d',
            $binding === null ? 'NONE — nothing will sync' : "ACTIVE → {$projectName}",
            $campaigns,
        ));
        $this->line(str_repeat('-', 78));

        $runs = MetricSyncRun::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->orderByDesc('started_at')
            ->limit($runLimit)
            ->get();

        if ($runs->isEmpty()) {
            $this->line('  No metrics run has ever been recorded for this account.');

            return;
        }

        $this->table(
            ['started', 'window', 'status', 'raw', 'parsed', 'mapped', 'stored', 'source'],
            $runs->map(fn (MetricSyncRun $run) => $this->runRow($run, $account))->all(),
        );

        foreach ($runs as $run) {
            if (($run->error ?? '') !== '') {
                $this->line(sprintf('  %s → %s', $run->started_at?->toDateTimeString() ?? '?', $run->error));
            }
        }
    }

    /**
     * One run as a row, with the counts either as measured or as recovered from the kept payload.
     *
     * @return list<string>
     */
    private function runRow(MetricSyncRun $run, ExternalAccount $account): array
    {
        $measured = $run->parsed_rows !== null || $run->provider_raw_rows !== null;

        $counts = $measured
            ? [
                'raw' => $run->provider_raw_rows,
                'parsed' => $run->parsed_rows,
                'mapped' => $run->mapped_campaign_rows,
            ]
            : $this->reconstruct($run, $account);

        return [
            $run->started_at?->toDateTimeString() ?? '—',
            ($run->window_start?->toDateString() ?? '?').' → '.($run->window_end?->toDateString() ?? '?'),
            (string) $run->status,
            $this->cell($counts['raw'] ?? null),
            $this->cell($counts['parsed'] ?? null),
            $this->cell($counts['mapped'] ?? null),
            (string) (int) $run->metrics_upserted,
            $measured ? 'measured' : ($counts === [] ? 'no payload kept' : 'recovered from payload'),
        ];
    }

    /**
     * Recover what can be recovered for a run recorded before the counters existed.
     *
     * `parsed` is deliberately absent here and must stay absent: parsing is what the connector did at
     * the time, and re-running it now against a body would measure TODAY's connector, not the one
     * that produced this run. Raw records and the campaign ids they named are facts about the body
     * itself, so those two are recoverable and honest.
     *
     * @return array<string,int|null>
     */
    private function reconstruct(MetricSyncRun $run, ExternalAccount $account): array
    {
        $payloads = IntegrationRawPayload::withoutGlobalScopes()
            ->where('sync_run_id', $run->getKey())
            ->where('resource', 'insights')
            ->get(['payload']);

        if ($payloads->isEmpty()) {
            return [];
        }

        $rows = 0;
        $ids = [];
        $readable = false;

        foreach ($payloads as $payload) {
            $read = InsightPayloadRows::of($account->provider, (array) $payload->payload);

            if ($read === null) {
                continue;
            }

            $readable = true;
            $rows += $read['rows'];
            $ids = [...$ids, ...$read['campaign_ids']];
        }

        if (! $readable) {
            return [];
        }

        $ids = array_values(array_unique($ids));

        $known = $ids === [] ? 0 : ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->whereIn('external_id', $ids)
            ->count();

        return [
            'raw' => $rows,
            'parsed' => null,
            // Campaign IDENTITIES the body named that we know about — not rows. Stated as such in the
            // report: it answers «could these rows have been placed at all?», which is the question.
            'mapped' => $known,
        ];
    }

    private function cell(?int $value): string
    {
        return $value === null ? '—' : (string) $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
