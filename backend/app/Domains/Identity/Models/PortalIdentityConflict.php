<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Support\Concerns\NormalisesPhoneNumbers;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact the client-portal backfill refused to resolve on its own (PORTAL-AUTH-001).
 *
 * Deliberately NOT tenant-scoped by the global trait: the platform owner reads this register across
 * tenants to see whether the cutover is safe, and a per-tenant view would hide exactly the rows that
 * block it.
 */
final class PortalIdentityConflict extends Model
{
    use NormalisesPhoneNumbers;

    /** PHONE-001 — normalised to E.164 on save, from every caller. See the trait. */
    protected array $phoneColumns = ['contact_phone'];

    use HasUuidKey;

    protected $table = 'portal_identity_conflicts';

    protected $fillable = [
        'tenant_id', 'contact_email', 'contact_phone', 'reason', 'client_ids',
        'resolution', 'note', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'client_ids' => 'array',
        'resolved_at' => 'datetime',
    ];

    /** Open means: nobody has decided yet, and the cutover is not safe while any exist. */
    public function isOpen(): bool
    {
        return $this->resolution === null;
    }
}
