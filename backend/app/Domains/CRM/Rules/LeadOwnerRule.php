<?php

declare(strict_types=1);

namespace App\Domains\CRM\Rules;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SEC-1 — a lead's owner is somebody who can work it, not any row in `users`.
 *
 * `exists:users,id` accepted a user of another tenant (whose name the follow-up workspace then printed) and
 * a colleague with no access to the lead's project. The owner must hold an ACTIVE membership of the
 * current tenant and, when the lead belongs to a project, `leads.update` on it — the same bar
 * `LeadFormController::owner()` applies to a form's default owner.
 */
final class LeadOwnerRule implements ValidationRule
{
    public function __construct(private readonly ?string $projectId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! self::isEligible((int) $value, $this->projectId)) {
            $fail('That person cannot work this lead.');
        }
    }

    public static function isEligible(int $ownerId, ?string $projectId): bool
    {
        $tenantId = app(TenantContext::class)->tenantId();
        if ($tenantId === null) {
            return false;
        }

        $member = Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerId)
            ->where('status', 'active')
            ->exists();
        if (! $member) {
            return false;
        }

        if ($projectId === null) {
            return true;
        }

        $user = User::query()->find($ownerId);

        return $user !== null && app(ProjectAbilities::class)->allows($user, $projectId, ProjectCapability::LEADS_UPDATE);
    }
}
