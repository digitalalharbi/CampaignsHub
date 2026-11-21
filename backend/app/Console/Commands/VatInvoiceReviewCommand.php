<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Subscriptions\Services\VatInvoiceReview;
use Illuminate\Console\Command;

/** PRICING-VAT-002 — read-only: which subscription invoices need accounting review. Prints counts and invoice numbers only. */
final class VatInvoiceReviewCommand extends Command
{
    protected $signature = 'subscriptions:vat-review';

    protected $description = 'List subscription invoices whose totals, payments or tax treatment need accounting review (read-only)';

    public function handle(VatInvoiceReview $review): int
    {
        $r = $review->run();
        $this->line(sprintf('VAT-REVIEW checked=%d flagged=%d %s', $r['checked'], $r['flagged'], json_encode($r['by_issue'])));
        foreach ($r['rows'] as $row) {
            $this->line(sprintf('  %s %s total=%s charged=%s %s', $row['number'], $row['status'], $row['total'], $row['charged'] ?? '-', implode(',', $row['issues'])));
        }

        return self::SUCCESS;
    }
}
