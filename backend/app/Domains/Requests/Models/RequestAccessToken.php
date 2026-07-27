<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_id
 * @property string $token_hash
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 */
class RequestAccessToken extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalRequest::class, 'request_id');
    }
}
