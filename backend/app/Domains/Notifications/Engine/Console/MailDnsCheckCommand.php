<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Mail\MailDnsReport;
use Illuminate\Console\Command;

/**
 * `mail:dns-check [--domain=] [--selector=*]` — READ-ONLY report of MX, SPF, DKIM and DMARC for the sending domain,
 * the From / authenticated-mailbox alignment, and ledger rows stuck in a waiting state. Changes no DNS, no config, no row.
 * Exit 1 when something required is missing, so it can gate a deploy check if wanted.
 */
final class MailDnsCheckCommand extends Command
{
    protected $signature = 'mail:dns-check {--domain= : Defaults to the domain of mail.from.address} {--selector=* : DKIM selectors to look for}';

    protected $description = 'Report SPF, DKIM, DMARC and MX of the sending domain (read-only; never changes DNS).';

    public function handle(MailDnsReport $report): int
    {
        $domain = (string) ($this->option('domain') ?: substr((string) strrchr((string) config('mail.from.address'), '@'), 1));
        if ($domain === '') {
            $this->error('No domain: pass --domain or set mail.from.address.');

            return self::FAILURE;
        }
        $selectors = (array) $this->option('selector');
        $r = $report->for($domain, $selectors !== [] ? array_values(array_map('strval', $selectors)) : MailDnsReport::SELECTORS);

        $this->line('domain: '.$r['domain']);
        foreach ($r['checks'] as $c) {
            $this->line(sprintf('  %-6s %-16s %s', $c['check'], $c['status'], $c['detail']));
        }
        foreach ($r['stuck'] as $table => $count) {
            $this->line(sprintf('  stuck  %-20s %s', $table, $count < 0 ? 'n/a' : (string) $count));
        }
        $this->line($r['missing'] === [] ? 'missing: none' : 'missing: '.implode(', ', $r['missing']).' (report only — no DNS was changed)');

        return $r['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
