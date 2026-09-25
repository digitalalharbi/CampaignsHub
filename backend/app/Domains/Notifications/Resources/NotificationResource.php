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
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'project_id' => $this->project_id,
            /*
             * PROJECT-NOTIFICATION-SCOPE-001 — which SCOPE this notification is about.
             *
             * A reader cannot tell «تكلفة الطلب ارتفعت في مشروع رزة أفينيو» from «انتهت صلاحية
             * تفويض سناب شات» by looking at the fields: both are rows, and the only difference is
             * that one carries a project id and the other does not. So every surface that draws them
             * has to re-derive the distinction from a null check, and they drift — the owner's
             * §18 is precisely that the two must not share copy or treatment.
             *
             * Derived rather than stored: `project_id` already IS the fact, and a second column
             * saying the same thing is a second thing to keep true. Stating it here means the
             * distinction is made once, by the server, instead of four times by four components.
             *
             * `portfolio` is the honest word for the absent case. These are the tenant-wide
             * operational alerts — a token expiring, a connection erroring — which belong to the
             * agency rather than to any one client, and which `NotificationController` deliberately
             * raises with no recipient so they reach the whole team.
             */
            'scope' => $this->project_id === null ? 'portfolio' : 'project',
            'client_workspace_id' => $this->client_workspace_id,
            'action_url' => $this->action_url,
            'status' => $this->status,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
