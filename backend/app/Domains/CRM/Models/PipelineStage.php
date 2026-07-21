<?php

declare(strict_types=1);

namespace App\Domains\CRM\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PipelineStage extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = ['tenant_id', 'pipeline_id', 'name', 'sort', 'probability', 'is_won', 'is_lost'];

    protected $casts = ['is_won' => 'bool', 'is_lost' => 'bool'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
