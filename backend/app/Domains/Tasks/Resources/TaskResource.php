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
            /*
             * OPS-LINK-001 — who holds it, who raised it, and what it came from, so a task is never an orphan.
             *
             * `source` is read from stored facts only: an alert event that recorded this task's id (the alert opened it),
             * or the `meta.source` the creating code wrote, or «manual». Set by the controller in one query per page;
             * absent on a response that did not ask for it.
             */
            'created_by' => $this->created_by,
            'assignee_name' => $this->resource->getAttribute('assignee_name'),
            'created_by_name' => $this->resource->getAttribute('created_by_name'),
            'source' => $this->resource->getAttribute('source_link'),
        ];
    }
}
