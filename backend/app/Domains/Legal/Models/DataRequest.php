<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use App\Domains\Legal\Services\ReferenceGenerator;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGAL-002 — a data-subject request: export, correction, or deletion.
 *
 * ## Why `blocked` is a state and not a failure
 *
 * A deletion request against an account with unpaid invoices cannot be executed and must not be
 * discarded. Both halves matter: the operator is obliged to answer it, and obliged not to destroy an
 * accounting record the law requires them to keep. `blocked` records exactly that, with the reasons
 * in `blockers`, so the requester is told what is standing in the way rather than being met with
 * silence or a bare refusal.
 *
 * That is also why this is not a support ticket with a type on it. A ticket can be closed because
 * nobody replied; a statutory request cannot.
 */
final class DataRequest extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'reference', 'type', 'name', 'email', 'phone', 'details', 'status', 'blockers',
        'operator_note', 'reviewed_by', 'reviewed_at', 'completed_at',
        'user_id', 'tenant_id', 'ip', 'user_agent', 'locale',
    ];

    protected $casts = [
        'blockers' => 'array',
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const TYPES = ['export', 'correction', 'delete_data', 'delete_account'];

    /**
     * `withdrawn` exists because a requester may change their mind, and recording that is different
     * from the operator rejecting them.
     */
    public const STATUSES = ['pending', 'verifying', 'in_review', 'blocked', 'completed', 'rejected', 'withdrawn'];

    /** The two types that destroy data, and therefore the two that must pass the blocker check. */
    public function isDestructive(): bool
    {
        return in_array($this->type, ['delete_data', 'delete_account'], true);
    }

    /**
     * Delegated to {@see ReferenceGenerator} — see it for why the alphabet excludes O/0/I/1 and why a
     * short blocklist earns its keep on a string customers read aloud.
     */
    public static function makeReference(): string
    {
        return app(ReferenceGenerator::class)->make(
            'DR',
            static fn (string $candidate): bool => self::query()->where('reference', $candidate)->exists(),
        );
    }
}
