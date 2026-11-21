<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_id
 * @property string $visibility
 * @property string|null $author_label
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $read_by_team_at
 * @property Carbon|null $read_by_client_at
 */
class RequestComment extends Model
{
    protected $guarded = ['id'];

    /** CLIENT-CHAT-001 — when each side has read the other's message (null: not yet). */
    protected $casts = ['read_by_team_at' => 'datetime', 'read_by_client_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalRequest::class, 'request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
