<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message we owed somebody about their account or their money (NOTIF-SUB-001).
 *
 * NOT tenant-scoped, and it cannot be: the first message a customer ever receives is about a trial
 * fee owed before any tenant exists. Reads are a platform administrator's, or the recipient's own.
 *
 * `status` is the honest part. `queued` → one of `awaiting_credentials`, `sandbox`, `sent`, `failed`,
 * and those first three are kept apart on purpose — all of them look like success from the caller's
 * side, and only one of them means a human being received anything.
 */
final class SubscriptionNotification extends Model
{
    use HasUuidKey;

    protected $table = 'subscription_notifications';

    // `status`, `sent_at`, `attempts` and `error` are the record of what the TRANSPORT did, so they
    // are written by the sender rather than by whoever raised the notification.
    protected $guarded = ['status', 'sent_at', 'attempts', 'error'];

    protected $casts = [
        'context' => 'array',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /** True only when a real transport accepted it — never for sandbox or awaiting_credentials. */
    public function reachedSomebody(): bool
    {
        return $this->status === 'sent';
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<RegistrationRequest, $this> */
    public function registrationRequest(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class, 'registration_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
