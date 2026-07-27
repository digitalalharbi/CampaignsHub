<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $request_id
 * @property bool $is_client_visible
 */
class RequestFile extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['is_client_visible' => 'bool', 'size' => 'integer'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalRequest::class, 'request_id');
    }
}
