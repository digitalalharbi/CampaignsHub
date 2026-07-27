<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a thread. author_type records which side wrote it (client|team|system). read_by_client_at /
 * read_by_team_at stamp when each side has seen it — null means unread for that side. Tenant-scoped; uuid PK.
 */
final class Message extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'thread_id', 'author_type', 'author_user_id', 'body',
        'attachments', 'read_by_client_at', 'read_by_team_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'read_by_client_at' => 'datetime',
        'read_by_team_at' => 'datetime',
    ];

    /** @return BelongsTo<MessageThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }
}
