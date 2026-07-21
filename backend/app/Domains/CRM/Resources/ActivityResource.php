<?php

declare(strict_types=1);

namespace App\Domains\CRM\Resources;

use App\Domains\CRM\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
final class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'meta' => $this->meta,
            'user_id' => $this->user_id,
            'occurred_at' => optional($this->occurred_at)->toIso8601String(),
        ];
    }
}
