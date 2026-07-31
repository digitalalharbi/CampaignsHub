<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A challenge sent to an applicant who has no account yet (SIGNUP-002, SIGNUP-005).
 *
 * Nothing here is mass-assignable. Every column decides whether a gate opens, and a payload that
 * could set `consumed_at` or `expires_at` would be a way to mark yourself verified.
 */
final class RegistrationVerification extends Model
{
    use HasUlids;

    protected $table = 'registration_verifications';

    protected $guarded = ['*'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /** @return BelongsTo<RegistrationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class, 'registration_request_id');
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
