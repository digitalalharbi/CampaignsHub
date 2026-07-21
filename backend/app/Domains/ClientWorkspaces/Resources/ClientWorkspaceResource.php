<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Resources;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientWorkspace */
final class ClientWorkspaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'mode' => $this->mode,
            'status' => $this->status,
            'branding' => $this->branding ?? [],
            'limits' => $this->limits ?? [],
            'custom_domain' => $this->custom_domain,
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
