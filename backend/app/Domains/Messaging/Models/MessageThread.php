<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client ⇄ internal-team conversation, optionally tied to a client workspace / request / project.
 * Tenant-scoped; uuid PK. last_message_at is bumped on every post to order the inbox. Unread is not stored
 * here — it is derived from the thread's messages (read_by_<side>_at null = unread for that side).
 */
final class MessageThread extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'request_id', 'project_id',
        'subject', 'status', 'last_message_at', 'created_by',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }
}
