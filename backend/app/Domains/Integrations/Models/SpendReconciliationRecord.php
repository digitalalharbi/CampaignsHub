<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Reports\Support\ReportSpendReconciliation;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * SPEND-RECONCILE-RECORD-001 — one checked comparison of provider spend against stored spend.
 *
 * Written only by `integrations:spend-reconcile --record`. Totals only — never a campaign name, an
 * account name or a token. Read by the live report's freshness strip as «آخر مطابقة مؤكدة مع المصدر»,
 * scoped to the link's project and still-bound accounts ({@see ReportSpendReconciliation}).
 */
final class SpendReconciliationRecord extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const MATCHED = 'matched';

    public const DRIFTED = 'drifted';

    public const FAILED = 'failed';

    /** Stored and provider totals agree when they differ by no more than this share (0.5 %) or by less than a cent. */
    public const MATCH_TOLERANCE_PCT = 0.5;

    protected $table = 'spend_reconciliations';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'provider', 'window_from', 'window_to',
        'provider_total', 'stored_total', 'currency', 'diff', 'diff_pct', 'status', 'checked_at', 'run_ref',
    ];

    protected $casts = [
        'window_from' => 'date',
        'window_to' => 'date',
        'provider_total' => 'decimal:6',
        'stored_total' => 'decimal:6',
        'diff' => 'decimal:6',
        'diff_pct' => 'decimal:4',
        'checked_at' => 'datetime',
    ];

    /** The verdict for a comparison that reached the provider. */
    public static function verdict(float $diff, ?float $diffPct): string
    {
        if (abs($diff) < 0.01) {
            return self::MATCHED;
        }

        return $diffPct !== null && abs($diffPct) <= self::MATCH_TOLERANCE_PCT ? self::MATCHED : self::DRIFTED;
    }
}
