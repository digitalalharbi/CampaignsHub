<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\MetaCandidate\MetaAppAudit;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Console\Command;

/**
 * META-MULTI-APP-001 — which Meta app issued each connection's token, asked of Meta itself.
 *
 * DRY-RUN IS THE DEFAULT and is strictly read-only: GET calls to Meta and SELECTs, nothing written, no token refreshed or extended.
 * `--apply` (mutually exclusive with `--dry-run`) records the verified issuer on ONE connection at a time; unresolved rows are listed
 * and left untouched. It never changes `app_profile` or a token, and never prints a token or secret.
 */
final class MetaAppAuditCommand extends Command
{
    protected $signature = 'integrations:meta-app-audit {--dry-run : Read-only report (the default)} {--apply : Record each VERIFIED issuer, one connection at a time} {--connection= : Audit one connection id}';

    protected $description = 'Verify with Meta which app issued each Meta connection token (read-only unless --apply).';

    public function handle(MetaAppAudit $audit): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply and --dry-run are mutually exclusive.');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');

        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')
            ->when(is_string($this->option('connection')) && $this->option('connection') !== '', fn ($q) => $q->whereKey((string) $this->option('connection')))
            ->orderBy('created_at')->get();

        if ($connections->isEmpty()) {
            $this->line('No Meta connection matched.');

            return self::SUCCESS;
        }

        $rows = [];
        $counts = ['verified-matches-recorded' => 0, 'verified-differs' => 0, 'unresolved' => 0];
        $written = 0;
        foreach ($connections as $connection) {
            $verdict = $audit->inspect($connection);
            $counts[$verdict['result']]++;
            $action = '-';
            if ($apply) {
                $action = $audit->record($connection, $verdict) ? 'recorded' : ($verdict['result'] === MetaAppAudit::UNRESOLVED ? 'left untouched' : 'no change');
                $written += $action === 'recorded' ? 1 : 0;
            }
            $rows[] = [$verdict['id'], $verdict['recorded_profile'], $verdict['recorded_app_id'] ?? '-', $verdict['verified_app_id'] ?? '-', $verdict['result'], $verdict['reason'] ?? '', $action];
        }

        $this->table(['id', 'recorded profile', 'recorded app', 'verified app', 'result', 'why', $apply ? 'applied' : 'dry-run'], $rows);
        $this->line(sprintf('matches: %d, differs: %d, unresolved: %d.', $counts['verified-matches-recorded'], $counts['verified-differs'], $counts['unresolved']));
        $this->line($apply ? "Recorded {$written} verified connection(s); every other row was left untouched." : 'Dry run: nothing was written. Re-run with --apply (the Owner\'s approval) to record verified rows.');

        return self::SUCCESS;
    }
}
