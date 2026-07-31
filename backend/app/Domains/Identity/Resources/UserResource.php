<?php

declare(strict_types=1);

namespace App\Domains\Identity\Resources;

use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
            'initials' => $this->initials(),
            // Was `users.tenant_id`, one id (ADR 0002). A person may belong to several, so the
            // resource reports the set — a single id here silently picked one and hid the rest.
            'tenant_ids' => $this->memberships
                ->where('status', 'active')->pluck('tenant_id')->map(fn ($id) => (string) $id)->unique()->values(),
            'workspace_name' => optional($this->tenant)->name,
            'role' => $role?->name,
            'role_slug' => $role?->slug,
            'status' => $this->accountStatus(),
            'is_platform_admin' => $this->is_platform_admin,
            'email_verified' => $this->email_verified_at !== null,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
            // Personal preferences (distinct from tenant/workspace settings).
            'locale' => $this->locale ?? 'ar',
            'timezone' => $this->timezone ?? 'Asia/Riyadh',
            'date_format' => $this->date_format ?? 'YYYY-MM-DD',
            'number_format' => $this->number_format ?? 'latin',
            'theme' => $this->theme ?? 'system',
            'permissions' => $this->permissionKeys(),
            /*
             * Account entitlements for the portal the request is actually in (REG-001).
             *
             * The portal comes from the active membership, never from the account type. Sending the
             * account type's menu was the regression: an advertiser's boot payload carried the
             * agency's sections, and the rail rendered them.
             *
             * A platform admin holds no membership and gets `null` here — correct, because `/admin`
             * is not entitlement-driven; it is gated by `is_platform_admin` and administers plans
             * rather than consuming one.
             */
            'account' => $this->tenant instanceof Tenant
                ? app(AccountEntitlements::class)->toArray($this->tenant, $this->activePortal())
                : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * The portal this payload describes.
     *
     * Normally the bound membership, set by `ResolveMembership`. LOGIN is the exception and the
     * reason this method exists: that route runs before any membership is bound, so the context is
     * empty and the boot payload came back with an empty `nav` — a signed-in user with no rail at
     * all, which is how the first attempt at REG-001 read on screen.
     *
     * Falling back to the resolver is not a guess. It is the same server-side rule that decides
     * where the browser is about to be sent, so the nav describes the portal the user is entering
     * rather than the absence of one.
     */
    private function activePortal(): ?Portal
    {
        $bound = app(MembershipContext::class)->membership()?->portal;

        if ($bound !== null) {
            return $bound;
        }

        return app(PortalResolver::class)->resolve($this->resource)?->portal;
    }

    /** Account lifecycle status used by the user menu header. */
    private function accountStatus(): string
    {
        if ($this->disabled_at !== null) {
            return 'suspended';
        }

        return $this->email_verified_at !== null ? 'active' : 'unverified';
    }

    /** Two-letter initials from the display name (Latin-safe fallback). */
    private function initials(): string
    {
        $source = trim((string) ($this->name ?: $this->email));
        $parts = preg_split('/\s+/', $source) ?: [];
        if (count($parts) >= 2) {
            return Str::upper(Str::substr($parts[0], 0, 1).Str::substr($parts[count($parts) - 1], 0, 1));
        }

        return Str::upper(Str::substr($source, 0, 2));
    }
}
