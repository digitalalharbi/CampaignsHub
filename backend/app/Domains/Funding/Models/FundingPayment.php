<?php

declare(strict_types=1);

namespace App\Domains\Funding\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * FIN-LOOP-001 — one amount finance approved for a project. Append-only.
 *
 * A renewal is a NEW row; nothing here is ever edited or deleted, so the spend counted before a renewal
 * is untouched by it and the record of who added what, when, and against which reference survives.
 *
 * @property string $id
 * @property string $project_id
 * @property string $kind
 * @property string $amount
 * @property string $currency
 * @property string $amount_sar
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $remaining_before
 * @property string|null $remaining_after
 * @property int|null $created_by
 * @property Carbon $created_at
 */
final class FundingPayment extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'kind', 'amount', 'currency', 'amount_sar', 'rate', 'rate_date',
        'rate_source', 'paid_on', 'reference', 'note', 'remaining_before', 'remaining_after', 'idempotency_key', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'amount_sar' => 'decimal:2', 'rate' => 'decimal:12', 'rate_date' => 'date',
        'paid_on' => 'date', 'remaining_before' => 'decimal:2', 'remaining_after' => 'decimal:2', 'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('An approved budget amount is never edited; add a new one.'));
        self::deleting(static fn (): never => throw new LogicException('An approved budget amount is never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
