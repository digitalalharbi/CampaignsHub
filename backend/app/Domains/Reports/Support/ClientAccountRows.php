<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;

/**
 * REPORT-CLIENT-ACCOUNT-NAMES-OFF-001 — the ad-account rows a client link publishes, stated once.
 *
 * The Owner's Hanka link carried «Joodtelecom0227» and «joodtelecom Self Service»: the agency's own account names, which are
 * its internal arrangement and sometimes another client's name. Unless the link's `account_names` disclosure is on, each row is
 * named by its platform and an ordinal — «حساب سناب شات 1» — and the technical id is withheld. The figures are untouched.
 *
 * REPORT-FILTERS-FUNCTIONAL-001 — the rule lived inside the live payload's builder, so a STORED copy served through a link kept
 * every account's real name and id on its page, in its PDF and in its files, whatever the disclosure said. Both read it here.
 */
final class ClientAccountRows
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function for(array $rows, ReportShare $share, ?Report $report): array
    {
        // REPORT-GENERIC-LABELS-001 — a name switch on the link or the report outranks the disclosure.
        if ($share->sectionVisibility()->account_names && ! GenericNames::for($report, $share)->chose('account')) {
            return $rows;
        }

        $ordinal = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $provider = (string) ($row['provider'] ?? '');
            $ordinal[$provider] = ($ordinal[$provider] ?? 0) + 1;
            $rows[$i]['account_name'] = GenericNames::accountRow($provider, $ordinal[$provider], 'ar');
            $rows[$i]['account_name_en'] = GenericNames::accountRow($provider, $ordinal[$provider], 'en');
            $rows[$i]['external_account_id'] = null;
            $rows[$i]['account_name_withheld'] = true;
        }

        return $rows;
    }
}
