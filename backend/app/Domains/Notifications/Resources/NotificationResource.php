<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Resources;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\AlertVisual;
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
            // DIGESTS-CADENCE-BUDGETS-001 — the same grammar as the email: chip state, the problem drawn, the next steps.
            'visual' => $this->visual($ar),
        ];
    }

    /** @return array{state: string, viz: array<string,mixed>|null, steps: list<string>} */
    private function visual(bool $ar): array
    {
        $event = $this->relationLoaded('alertEvent') ? $this->getRelation('alertEvent') : null;
        $severity = (string) $this->severity;
        if (! $event instanceof AlertEvent) {
            return ['state' => AlertVisual::chip($severity, $ar)['state'], 'viz' => null, 'steps' => []];
        }
        $ctx = (array) ($event->context ?? []);
        $type = (string) $event->type;
        $say = AlertEventText::plain($type, $ctx, $ar);
        $card = AlertVisual::present(['severity' => $severity, 'rule' => $type, 'ctx' => $ctx, 'detail' => '', 'action' => $say['action']], $ar);

        return ['state' => $card['chip']['state'], 'viz' => $card['viz'], 'steps' => $card['steps']];
    }
}
