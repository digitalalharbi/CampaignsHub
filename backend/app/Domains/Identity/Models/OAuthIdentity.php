<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider account that has been deliberately linked to a local one (LOGIN-004).
 *
 * NOT tenant-scoped: an identity belongs to a person, and that person may hold memberships in
 * several workspaces. Scoping it to a tenant would mean re-linking Google for every workspace
 * someone joins.
 */
final class OAuthIdentity extends Model
{
    use HasUuidKey;

    protected $table = 'oauth_identities';

    protected $fillable = [
        'user_id', 'provider', 'provider_user_id',
        'email', 'email_verified', 'name', 'avatar_url',
        'linked_at', 'last_login_at',
    ];

    protected $casts = [
        'email_verified' => 'boolean',
        'linked_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
