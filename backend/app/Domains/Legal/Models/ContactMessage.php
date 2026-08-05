<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGAL-002 — a message from the public contact form.
 *
 * Not tenant-scoped, and that is deliberate: the sender is usually not a customer yet. Attaching a
 * stranger's enquiry to a workspace would either misfile it or require inventing a tenancy for them.
 * It is read only from the platform console.
 */
final class ContactMessage extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'name', 'email', 'phone', 'company', 'subject', 'message',
        'status', 'operator_note', 'handled_by', 'handled_at', 'ip', 'user_agent', 'locale',
    ];

    protected $casts = ['handled_at' => 'datetime'];

    /** `spam` is a STATE, not a deletion — deleting the message loses the fact that it was judged. */
    public const STATUSES = ['new', 'read', 'answered', 'closed', 'spam'];
}
