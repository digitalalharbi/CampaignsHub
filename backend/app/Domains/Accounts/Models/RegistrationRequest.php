<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Concerns\NormalisesPhoneNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who has applied, and does not yet have a workspace (SIGNUP-002).
 *
 * NOT tenant-scoped, and it cannot be: the whole point is that no tenant exists yet. That also means
 * none of the usual isolation applies here, so every read of this table is either the applicant's own
 * (found by a token they hold) or a platform administrator's.
 *
 * `tenant_id` is the record of the crossing. Null means no workspace exists for this person,
 * whatever else the row says — and it is the one field to check before believing anything about
 * access.
 */
final class RegistrationRequest extends Model
{
    use NormalisesPhoneNumbers;

    /** PHONE-001 — normalised to E.164 on save, from every caller. See the trait. */
    protected array $phoneColumns = ['phone'];

    use HasUuidKey;

    protected $table = 'registration_requests';

    protected $fillable = [
        'email', 'name', 'tenant_name', 'account_type', 'requested_portal',
        'plan_code', 'service', 'phone',
    ];

    /*
     * `password`, `state`, `tenant_id`, `provisioned_at` and the review columns are absent from
     * $fillable ON PURPOSE.
     *
     * `state` decides whether this application has passed verification, approval and payment;
     * `tenant_id` decides whether a workspace exists. A request payload carrying either would let an
     * applicant grant themselves the thing the gate exists to withhold. They are written by
     * AdvanceRegistration and ProvisionWorkspace, which check first.
     */

    protected $hidden = ['password'];

    protected $casts = [
        'state' => AccountState::class,
        'email_verified_at' => 'datetime',
        'mobile_verified_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'info_requested_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'state_changed_at' => 'datetime',
        'review_concessions' => 'array',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Has this application become a real workspace? The only question that matters for access. */
    public function isProvisioned(): bool
    {
        return $this->tenant_id !== null;
    }

    public function emailIsVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function mobileIsVerified(): bool
    {
        return $this->mobile_verified_at !== null;
    }

    /**
     * What the applicant should be shown, in words rather than a state token.
     *
     * Every pre-activation state has a screen, because "nothing happened when I signed up" is the
     * failure this whole path exists to avoid — someone waiting on approval needs to be told they
     * are waiting on approval.
     *
     * @return array<string, mixed>
     */
    public function statusPayload(bool $ar = true): array
    {
        $state = $this->state ?? AccountState::Draft;

        return [
            'id' => (string) $this->getKey(),
            'state' => $state->value,
            'label' => $state->label($ar),
            'email' => $this->email,
            'requested_portal' => $this->requested_portal,
            'plan_code' => $this->plan_code,
            'email_verified' => $this->emailIsVerified(),
            'mobile_verified' => $this->mobileIsVerified(),
            // Present only when there is something the applicant can act on, so the screen never
            // shows an empty "next step" box.
            'next_step' => $this->nextStep($ar),
            'reason' => $this->state_reason,
            'provisioned' => $this->isProvisioned(),
        ];
    }

    /** The one thing this applicant should do next, or null when they are waiting on us. */
    private function nextStep(bool $ar): ?string
    {
        /*
         * A reviewer asked for something (SIGNUP-003).
         *
         * This is the one case where an application in a queue DOES have a next step, and it takes
         * precedence over the state's usual answer — the applicant is no longer waiting on us, and
         * a screen still saying "there is nothing for you to do" would leave the queue stuck with
         * neither side expecting to move.
         */
        if ($this->info_requested_at !== null && $this->state === AccountState::PendingApproval) {
            return $this->review_note;
        }

        return match ($this->state) {
            AccountState::EmailVerificationRequired => $ar
                ? 'أكّد بريدك الإلكتروني من الرسالة المرسلة إليك.'
                : 'Confirm your email address using the message we sent you.',
            AccountState::MobileVerificationRequired => $ar
                ? 'أكّد رقم جوالك بالرمز المرسل إليك.'
                : 'Confirm your mobile number with the code we sent you.',
            AccountState::ApprovedAwaitingPayment => $ar
                ? 'أكمل الدفع لتفعيل مساحة العمل.'
                : 'Complete payment to activate your workspace.',
            // Waiting on US, not on them — saying "next step" here would be misleading.
            AccountState::PendingApproval, AccountState::PaymentPending => null,
            default => null,
        };
    }
}
