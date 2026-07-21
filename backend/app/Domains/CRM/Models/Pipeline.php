<?php

declare(strict_types=1);

namespace App\Domains\CRM\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Pipeline extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = ['tenant_id', 'name', 'is_default'];

    protected $casts = ['is_default' => 'bool'];

    /** @return HasMany<PipelineStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort');
    }
}
