<?php

declare(strict_types=1);

namespace App\Domains\Funding\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * FIN-LOOP-001 — real spend of one account on one day, as counted against a project's budget.
 *
 * Keyed (project, account, day): a resync or a reconnect rewrites the row for that key and can never
 * add the same day a second time (F07). `amount_sar` stays null when no dated rate exists.
 */
final class FundingSpendDay extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'provider', 'spend_date', 'original_amount', 'original_currency',
        'amount_sar', 'rate', 'rate_date', 'rate_source',
    ];

    protected $casts = ['spend_date' => 'date', 'original_amount' => 'decimal:6', 'amount_sar' => 'decimal:2', 'rate_date' => 'date'];
}
