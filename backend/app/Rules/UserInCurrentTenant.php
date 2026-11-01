<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A user id taken from a request (assignee, owner, enrolled member) names a person of THIS tenant.
 *
 * `Rule::exists('users', 'id')` is true for every user on the platform: User has no tenant scope, so it stored a
 * stranger's id on this tenant's rows, resolved their name and email back to the reader, and let a caller walk the
 * integer ids to learn which exist (422 versus success). Membership is what ties a person to a tenant, so the
 * check is the one `User::inTenant()` already defines — active membership — and the failure message is the same
 * for «no such user» and «not in your workspace».
 */
final class UserInCurrentTenant implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tenantId = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->tenantId() : null;

        $ok = (is_int($value) || (is_string($value) && ctype_digit($value)))
            && User::query()->whereKey((int) $value)->inTenant($tenantId)->exists();

        if (! $ok) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
