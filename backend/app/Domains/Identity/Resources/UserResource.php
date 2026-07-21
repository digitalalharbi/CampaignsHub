<?php

declare(strict_types=1);

namespace App\Domains\Identity\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'tenant_id' => $this->tenant_id,
            'is_platform_admin' => $this->is_platform_admin,
            'permissions' => $this->permissionKeys(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
