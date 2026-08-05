<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * LEGAL-002 — a support ticket, and the reference the sender can quote back.
 *
 * The reference is stored in the clear, unlike a report-share token which is hashed. The distinction
 * is what the string DOES: this one identifies a conversation so a human can find it again, and
 * grants access to nothing. A token that opened a client's figures without a session would be a
 * credential, and credentials are hashed.
 */
final class SupportTicket extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'reference', 'name', 'email', 'phone', 'subject', 'message', 'category',
        'status', 'priority', 'operator_note', 'assigned_to', 'resolved_at',
        'user_id', 'tenant_id', 'ip', 'user_agent', 'locale',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    public const STATUSES = ['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed'];

    public const CATEGORIES = ['general', 'account', 'billing', 'integrations', 'reports', 'bug'];

    /**
     * A reference a person can read down a phone.
     *
     * Uppercase, no vowels and no easily-confused characters: someone reading «CH-8K2M4Q» aloud to
     * support should not have to disambiguate O from 0 or I from 1. Collisions are handled by the
     * unique index and a retry, not by hoping.
     */
    public static function makeReference(): string
    {
        do {
            $code = 'CH-'.Str::upper(Str::random(6));
            $code = str_replace(['O', '0', 'I', '1', 'L'], ['X', 'Y', 'Z', 'W', 'V'], $code);
        } while (self::query()->where('reference', $code)->exists());

        return $code;
    }
}
