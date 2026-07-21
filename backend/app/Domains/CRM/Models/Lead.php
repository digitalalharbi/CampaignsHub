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

final class Lead extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'company_id', 'contact_id', 'owner_id', 'name', 'email', 'phone',
        'source', 'status', 'estimated_value', 'currency', 'notes', 'tags',
        'lost_reason', 'converted_opportunity_id', 'converted_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'estimated_value' => 'decimal:2',
        'converted_at' => 'datetime',
    ];

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
