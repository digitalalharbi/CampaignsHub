<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $token_hash
 * @property Carbon $expires_at
 */
class RequestUploadSession extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime'];

    /** @return HasMany<RequestFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(RequestFile::class, 'upload_session_id');
    }
}
