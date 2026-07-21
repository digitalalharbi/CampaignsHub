<?php

declare(strict_types=1);

namespace App\Domains\Tasks\Resources;

use App\Domains\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
final class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'project_id' => $this->project_id,
            'client_workspace_id' => $this->client_workspace_id,
            'assignee_id' => $this->assignee_id,
            'due_date' => optional($this->due_date)->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'checklist' => $this->checklist ?? [],
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
