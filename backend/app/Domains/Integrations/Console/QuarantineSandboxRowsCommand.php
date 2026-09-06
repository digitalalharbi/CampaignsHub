<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SANDBOX-PROD-001 §3 — the rows the write-path defect left behind, removed only where provenance
 * proves what they are.
 *
 * ## What this cleans, and what it must never touch
 *
 * `ProjectIntegrationController::sync()` ran the sandbox connector against whatever binding was
 * passed, so `sbx-cmp-1` and `sbx-cmp-2` were imported under live Snapchat, Meta and LinkedIn
 * accounts. That write path is closed. These rows are the residue, and they are still doing damage:
 * they inflate every campaign count, and on the bound Meta account they were the ONLY campaigns held
 * — so a genuinely empty account read as one with two campaigns.
 *
 * The identification is `raw.sandbox = true`, the marker `SandboxAdvertisingConnector` writes
 * itself. **Never an `sbx-` id prefix**: a prefix is a guess about a string, and a provider is
 * entitled to name a real campaign anything it likes. A row that carries the marker was written by
 * the sandbox; a row that does not is not this command's business, whatever it is called.
 *
 * And only on accounts whose provider is NOT `sandbox`. A sandbox account's sandbox campaigns are
 * the demo estate working correctly — deleting those would break the thing the sandbox exists for.
 *
 * ## Dry by default
 *
 * It reports and changes nothing unless `--apply` is passed, and it prints the counts before and
 * after either way. A cleanup whose effect nobody can see beforehand is one nobody can approve, and
 * this operates on production data that includes four years of somebody's advertising history.
 */
final class QuarantineSandboxRowsCommand extends Command
{
    protected $signature = 'integrations:quarantine-sandbox
        {--apply : Actually delete. Without this the command only reports.}
        {--provider= : Limit to one provider}';

    protected $description = 'Find campaigns the sandbox connector wrote into LIVE accounts, and remove them on --apply.';

    public function handle(): int
    {
        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider', '!=', 'sandbox')
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->get(['id', 'name', 'provider', 'external_id']);

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  SANDBOX QUARANTINE — '.($this->option('apply') ? 'APPLYING' : 'dry run, nothing will change'));
        $this->line(str_repeat('=', 78));

        $found = 0;
        $removed = 0;
        $touched = 0;

        foreach ($accounts as $account) {
            $rows = ExternalCampaign::withoutGlobalScopes()
                ->where('external_account_id', $account->id)
                ->whereJsonContains('raw->sandbox', true)
                ->get(['id', 'external_id', 'name']);

            if ($rows->isEmpty()) {
                continue;
            }

            $touched++;
            $found += $rows->count();

            $this->line('');
            $this->line(sprintf('  %s  [%s]  %s', $account->name ?: '(unnamed)', $account->provider, $account->external_id));

            /*
             * The total is printed beside the contaminated count, so the ratio is visible before
             * anything is deleted. «2 of 2» and «2 of 891» are the same command doing very different
             * things to somebody's account, and only the first is the case this was written for.
             */
            $total = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $account->id)->count();

            $this->line(sprintf('    contaminated %d of %d stored campaign(s)', $rows->count(), $total));

            foreach ($rows as $row) {
                $this->line(sprintf('      · %s  %s', $row->external_id, $row->name ?: '(unnamed)'));
            }

            if (! $this->option('apply')) {
                continue;
            }

            /*
             * Deleted inside a transaction per account, so a failure part-way through does not leave
             * one account half-cleaned — the state nobody could describe afterwards.
             */
            $removed += DB::transaction(fn (): int => ExternalCampaign::withoutGlobalScopes()
                ->whereIn('id', $rows->pluck('id')->all())
                ->delete());
        }

        $this->line('');
        $this->line(str_repeat('-', 78));
        $this->line(sprintf('  live accounts carrying sandbox rows : %d', $touched));
        $this->line(sprintf('  sandbox rows found                  : %d', $found));
        $this->line(sprintf('  sandbox rows removed                : %d', $removed));

        if (! $this->option('apply') && $found > 0) {
            $this->line('');
            $this->line('  Nothing was changed. Re-run with --apply to remove them.');
        }

        return self::SUCCESS;
    }
}
