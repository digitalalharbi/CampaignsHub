<?php

declare(strict_types=1);

namespace App\Domains\Projects\Resources;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectStatusDisplay;
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
            // PROJECT-LIFECYCLE-001 §8 — the «العميل» column, readable in every portal (only when the list loaded it).
            'client_name' => $this->whenLoaded('clientWorkspace', fn () => $this->clientWorkspace?->name),
            'name' => $this->name,
            // PROJECT-LIFECYCLE-DERIVED-001 — derived on read from what the project really has; the columns are not rewritten.
            'status' => ProjectStatusDisplay::status((string) $this->status, $this->list_summary ?? $this->lifecycle_facts),
            'setup_completion' => ProjectStatusDisplay::setupCompletion((int) $this->setup_completion, $this->list_summary ?? $this->lifecycle_facts),
            // PROJECT-LIFECYCLE-001 — the stored lifecycle state (draft|active|paused|archived|deleted) beside the display one.
            'lifecycle_status' => $this->lifecycleStatus()->value,
            'archived_at' => optional($this->archived_at)->toIso8601String(),
            'previous_status' => $this->previous_status,
            'purge_after' => optional($this->purge_after)->toIso8601String(),
            'account_manager_id' => $this->account_manager_id,
            // CLIENT-UNIT-001 — the project made with the client; the interface hides it until a second one exists.
            'internal' => (bool) (($this->meta ?? [])['internal'] ?? false),
            'created_at' => optional($this->created_at)->toIso8601String(),
            /*
             * PROJECT-LIST-SURFACE-001 — present on the LISTING, absent everywhere else.
             *
             * `whenNotNull` rather than a null field: a card that is drawn from `show` has no
             * summary to draw, and an always-present key holding null would have every consumer
             * write the same check to tell «not asked for» from «nothing to report».
             */
            'summary' => $this->whenNotNull($this->list_summary),
        ];
    }
}
