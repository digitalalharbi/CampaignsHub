<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the sending domain publishes, and what is missing — REPORT ONLY (spec §4). Nothing here changes DNS, the
 * configuration or a row: it reads DNS and counts ledger rows.
 */
final class MailDnsReport
{
    /** Hostinger's DKIM selectors first; the rest are common defaults. */
    public const SELECTORS = ['hostingermail1', 'hostingermail2', 'hostingermail3', 'default', 'mail', 'selector1', 'selector2'];

    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @param  list<string>  $selectors
     * @return array{domain: string, checks: list<array{check: string, status: string, detail: string}>, missing: list<string>, stuck: array<string, int>}
     */
    public function for(string $domain, array $selectors = self::SELECTORS): array
    {
        $domain = strtolower(trim($domain));
        $checks = [];

        $mx = $this->dns->mx($domain);
        $checks[] = ['check' => 'MX', 'status' => $mx === [] ? 'missing' : 'ok', 'detail' => $mx === [] ? 'no MX record' : implode(', ', $mx)];

        $spf = array_values(array_filter($this->dns->txt($domain), static fn (string $t): bool => str_starts_with(strtolower($t), 'v=spf1')));
        $spfStatus = match (true) {
            $spf === [] => 'missing',
            count($spf) > 1 => 'invalid',
            ! str_contains(strtolower($spf[0]), 'hostinger') && ! str_contains(strtolower($spf[0]), 'include:') => 'weak',
            default => 'ok',
        };
        $checks[] = ['check' => 'SPF', 'status' => $spfStatus, 'detail' => $spf === [] ? 'no v=spf1 TXT record' : (count($spf) > 1 ? 'more than one v=spf1 record (receivers treat this as an error)' : $spf[0])];

        $found = [];
        foreach ($selectors as $selector) {
            $host = "{$selector}._domainkey.{$domain}";
            $txt = array_values(array_filter($this->dns->txt($host), static fn (string $t): bool => str_contains(strtolower($t), 'v=dkim1') || str_contains($t, 'p=')));
            if ($txt !== [] || $this->dns->cname($host) !== []) {
                $found[] = $selector;
            }
        }
        $checks[] = ['check' => 'DKIM', 'status' => $found === [] ? 'missing' : 'ok', 'detail' => $found === [] ? 'no DKIM key at '.implode(', ', $selectors) : 'selectors: '.implode(', ', $found)];

        $dmarc = array_values(array_filter($this->dns->txt('_dmarc.'.$domain), static fn (string $t): bool => str_starts_with(strtolower($t), 'v=dmarc1')));
        $policy = $dmarc === [] ? null : (preg_match('~\bp=(none|quarantine|reject)\b~i', $dmarc[0], $m) === 1 ? strtolower($m[1]) : null);
        $checks[] = ['check' => 'DMARC', 'status' => $dmarc === [] ? 'missing' : ($policy === null ? 'invalid' : ($policy === 'none' ? 'monitoring' : 'ok')), 'detail' => $dmarc === [] ? 'no v=DMARC1 TXT at _dmarc.'.$domain : $dmarc[0]];

        $from = strtolower((string) config('mail.from.address'));
        $sender = AlignEnvelopeSender::verifiedSender();
        $checks[] = [
            'check' => 'FROM',
            'status' => $sender === null || $sender === $from ? 'ok' : 'aligned_by_code',
            'detail' => $sender === null ? 'from '.$from.' (no SMTP username address to compare)'
                : ($sender === $from ? 'from = authenticated mailbox '.$from : 'from '.$from.' differs from the authenticated mailbox '.$sender.'; the envelope sender is set to '.$sender.' by code — set MAIL_FROM_ADDRESS='.$sender.' in the live env'),
        ];

        $missing = array_values(array_map(static fn (array $c): string => $c['check'], array_filter($checks, static fn (array $c): bool => in_array($c['status'], ['missing', 'invalid'], true))));

        return ['domain' => $domain, 'checks' => $checks, 'missing' => $missing, 'stuck' => $this->stuck()];
    }

    /** Ledger rows waiting longer than 30 minutes in a non-final state — evidence for «stuck queued». @return array<string, int> */
    private function stuck(): array
    {
        $before = Carbon::now()->subMinutes(30);
        $out = [];
        foreach (['mail_deliveries' => ['queued', 'claimed'], 'report_deliveries' => ['queued'], 'notification_outbox' => ['pending', 'claimed']] as $table => $states) {
            $column = $table === 'notification_outbox' ? 'state' : 'status';
            try {
                $out[$table] = DB::table($table)->whereIn($column, $states)->where('updated_at', '<', $before)->count();
            } catch (\Throwable) {
                $out[$table] = -1;
            }
        }

        return $out;
    }
}
