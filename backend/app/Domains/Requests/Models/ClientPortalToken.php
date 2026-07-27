<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An external-client portal session, scoped to a verified contact (email/phone) + tenant. Delivered as an
 * httpOnly cookie (never localStorage). Only the token hash is stored.
 */
final class ClientPortalToken extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'client_portal_tokens';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
