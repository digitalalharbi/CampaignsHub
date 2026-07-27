<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $external_request_id
 * @property string|null $client_id
 * @property string|null $project_id
 * @property string|null $campaign_id
 * @property string $status
 */
class RequestConversion extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failure_context' => 'array',
    ];

    /** @return BelongsTo<ExternalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalRequest::class, 'external_request_id');
    }
}
