<?php

declare(strict_types=1);

namespace App\Domains\Funding\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * FIN-LOOP-001 part 3 — the fixed monthly reference of one project. Written once; never edited or deleted.
 *
 * @property string $id
 * @property string $project_id
 * @property string $month
 * @property array<string, mixed> $figures
 * @property Carbon $created_at
 */
final class FundingMonthSummary extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'project_id', 'month', 'figures'];

    protected $casts = ['figures' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A monthly summary is a fixed reference; it is never edited.'));
        self::deleting(static fn (): never => throw new LogicException('A monthly summary is a fixed reference; it is never deleted.'));
    }
}
