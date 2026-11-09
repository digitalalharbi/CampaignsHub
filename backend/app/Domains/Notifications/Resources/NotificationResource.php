<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Resources;

use App\Domains\Notifications\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // An Arabic reader gets the Arabic wording where there is one; everyone else, and every older row, reads what was stored.
        $ar = ($request->user()->locale ?? 'ar') !== 'en';

        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $ar && $this->title_ar ? $this->title_ar : $this->title,
            'message' => $ar && $this->message_ar ? $this->message_ar : $this->message,
            'project_id' => $this->project_id,
            'client_workspace_id' => $this->client_workspace_id,
            'action_url' => $this->action_url,
            'status' => $this->status,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
