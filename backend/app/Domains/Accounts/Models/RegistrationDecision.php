<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer's decision on one application (SIGNUP-DECISION-001).
 *
 * Nothing is mass-assignable: a decision can open the payment gate, so only `RegistrationDecisionService` writes it. Rows
 * are never rewritten into something else; a newer decision supersedes an older one and both stay readable.
 */
final class RegistrationDecision extends Model
{
    use HasUuidKey;

    public const PAYMENT_REQUIRED = 'approve_payment_required';

    public const DISCOUNT = 'approve_discount';

    public const WAIVER = 'approve_waiver';

    public const TRIAL = 'approve_trial';

    public const EXTERNAL_SETTLEMENT = 'approve_external_settlement';

    public const JOIN_EXISTING = 'approve_join_existing';

    /** «دخول مباشر دون دفع»: open-ended, with no end date, until the platform owner ends or converts it (ADMIN-FULL-CONTROL-001). */
    public const COMPLIMENTARY = 'approve_complimentary';

    public const REQUEST_INFO = 'request_info';

    public const DEFER = 'defer';

    public const REJECT = 'reject';

    public const APPROVALS = [
        self::PAYMENT_REQUIRED, self::DISCOUNT, self::WAIVER, self::TRIAL, self::EXTERNAL_SETTLEMENT, self::JOIN_EXISTING, self::COMPLIMENTARY,
    ];

    public const IN_FORCE = 'in_force';

    public const SUPERSEDED = 'superseded';

    /** External settlement evidence waiting on a second administrator. Clears nothing. */
    public const AWAITING_FINANCE = 'awaiting_finance';

    /** A note on the record (request info, defer) that changes no commercial term. */
    public const RECORDED = 'recorded';

    /** The commercial bases a subscription can carry. Deliberately not `paid`. */
    public const BASIS_WAIVED = 'waived_by_admin';

    public const BASIS_TRIAL = 'admin_trial';

    public const BASIS_EXTERNAL = 'external_settlement';

    public const BASIS_COMPLIMENTARY = 'complimentary';

    protected $table = 'registration_decisions';

    protected $guarded = ['*'];

    protected $casts = [
        'terms' => 'array',
        'access' => 'array',
        'verified_at' => 'datetime',
    ];

    /** @return BelongsTo<RegistrationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class, 'registration_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
