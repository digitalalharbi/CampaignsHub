<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device a person turned mobile notifications on for. Not tenant-scoped on purpose: a device belongs to a PERSON, and which
 * tenant's notification may reach it is decided per notification by `PushAudience`.
 */
final class PushSubscription extends Model
{
    use HasUuidKey;

    protected $table = 'push_subscriptions';

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'content_encoding', 'label', 'last_used_at', 'last_failure_at', 'failure_count'];

    protected $hidden = ['auth', 'p256dh', 'endpoint'];

    protected $casts = [
        'auth' => 'encrypted',
        'last_used_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
