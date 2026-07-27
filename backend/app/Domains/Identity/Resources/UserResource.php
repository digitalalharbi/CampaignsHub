<?php

declare(strict_types=1);

namespace App\Domains\Identity\Resources;

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
            'tenant_id' => $this->tenant_id,
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
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
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
