<?php

declare(strict_types=1);

namespace App\Domains\CRM\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Opportunity extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'pipeline_id', 'stage_id', 'company_id', 'contact_id', 'lead_id', 'owner_id',
        'name', 'amount', 'currency', 'probability', 'expected_close_date', 'status', 'lost_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_close_date' => 'date',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('occurred_at');
    }
}
