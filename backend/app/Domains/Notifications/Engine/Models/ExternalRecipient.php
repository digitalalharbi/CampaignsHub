<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An external recipient (spec §9). Tenant-scoped. Nothing is sent unless `status = active`, which only a confirmation
 * (double opt-in) or an internal approval for an org-domain address can set.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string|null $name
 * @property string $role_key
 * @property string $language
 * @property string $status
 * @property string $consent
 */
final class ExternalRecipient extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'notification_external_recipients';

    protected $fillable = [
        'tenant_id', 'email', 'name', 'salutation', 'role_key', 'language', 'display_currency', 'is_role_mailbox', 'status',
        'consent', 'confirmation_token_hash', 'confirmation_sent_at', 'confirmed_at', 'approved_by', 'approved_at',
        'legacy_source', 'legacy_id', 'created_by',
    ];

    protected $hidden = ['confirmation_token_hash'];

    protected $casts = [
        'is_role_mailbox' => 'boolean',
        'confirmation_sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** @return HasMany<NotificationSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class, 'recipient_id');
    }
}
