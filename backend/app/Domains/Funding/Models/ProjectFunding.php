<?php

declare(strict_types=1);

namespace App\Domains\Funding\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * FIN-LOOP-001 — the ONE approved budget a project has (Owner 2026-11-13: one budget, no branching).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_workspace_id
 * @property string $project_id
 * @property string $payment_method
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string $alert_floor
 * @property string $payment_mode direct | credit (BUDGET-MODES-001)
 * @property list<array{provider: string, percent: float, amount: float|null}>|null $platform_plan
 */
final class ProjectFunding extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const METHODS = ['prepaid', 'direct', 'unset'];

    /** BUDGET-MODES-001 — direct = a prepaid balance; credit = served now, invoiced later by the month's spend. */
    public const MODES = ['direct', 'credit'];

    protected $table = 'project_funding';

    protected $attributes = ['payment_mode' => 'direct'];

    protected $fillable = ['tenant_id', 'client_workspace_id', 'project_id', 'payment_method', 'starts_on', 'ends_on', 'alert_floor', 'voided_at', 'void_reason', 'voided_by', 'created_by', 'payment_mode', 'platform_plan'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'voided_at' => 'datetime', 'alert_floor' => 'decimal:2', 'platform_plan' => 'array'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<FundingPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(FundingPayment::class, 'project_id', 'project_id');
    }
}
