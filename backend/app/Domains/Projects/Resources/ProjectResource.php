<?php

declare(strict_types=1);

namespace App\Domains\Projects\Resources;

use App\Domains\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
final class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_workspace_id' => $this->client_workspace_id,
            'name' => $this->name,
            'status' => $this->status,
            'setup_completion' => $this->setup_completion,
            'account_manager_id' => $this->account_manager_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
