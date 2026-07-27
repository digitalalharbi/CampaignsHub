<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_id
 * @property string $type
 * @property string|null $to_status
 * @property bool $is_client_visible
 * @property string|null $message
 * @property Carbon|null $created_at
 */
class RequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['is_client_visible' => 'bool', 'meta' => 'array'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalRequest::class, 'request_id');
    }
}
