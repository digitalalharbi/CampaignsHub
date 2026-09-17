<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use Illuminate\Console\Command;

/**
 * META-CANDIDATE-001 — the last Candidate Meta app round trip, printed. READ-ONLY.
 *
 * Calls no provider, writes nothing, and prints no token, secret or App ID — the step checklist,
 * Meta's error identifiers, and counts. Reachable from the read-only production diagnostics workflow.
 */
final class MetaCandidateStatusCommand extends Command
{
    protected $signature = 'integrations:meta-candidate';

    protected $description = 'Print the last Candidate Meta app round-trip result (read-only).';

    public function handle(MetaCandidateCredentials $credentials): int
    {
        $summary = $credentials->summary();

        $this->line('Candidate Meta app (profile=candidate)');
        $this->line('  configured      : '.($summary['configured'] ? 'yes' : 'no — missing '.implode(', ', $summary['missing'])));
        foreach ($summary['values'] as $v) {
            $this->line(sprintf('  %-15s : %s', $v['key'], $v['present'] ? 'set ('.$v['source'].')' : 'not set'));
        }
        $this->line('  scopes          : '.implode(' ', $summary['effective_scopes']));
        $this->line('  redirect_uri    : '.$summary['redirect_uri']);

        $run = MetaCandidateConnection::latestRun();

        if ($run === null) {
            $this->line('Last test         : none has run.');

            return self::SUCCESS;
        }

        $report = $run->toReport();
        $this->line('');
        $this->line("Last test {$report['id']}");
        $this->line('  status          : '.$report['status']);
        $this->line('  started_at      : '.($report['started_at'] ?? '—'));
        $this->line('  finished_at     : '.($report['finished_at'] ?? '—'));
        $this->line('  accounts found  : '.count($report['discovered_accounts']));
        $this->line('  scopes granted  : '.(implode(' ', $report['granted_scopes']) ?: '—'));

        foreach ($report['steps'] as $step) {
            $line = sprintf('  [%-7s] %s', $step['status'], $step['key']);
            $error = $step['error'] ?? null;

            if (is_array($error)) {
                $line .= ' — '.implode(' · ', array_filter([
                    isset($error['http_status']) ? 'HTTP '.$error['http_status'] : null,
                    isset($error['code']) ? 'code '.$error['code'] : null,
                    isset($error['subcode']) ? 'subcode '.$error['subcode'] : null,
                    $error['type'] ?? null,
                    isset($error['fbtrace_id']) ? 'fbtrace_id '.$error['fbtrace_id'] : null,
                    $error['message'] ?? null,
                ]));
            }

            $this->line($line);
        }

        return self::SUCCESS;
    }
}
