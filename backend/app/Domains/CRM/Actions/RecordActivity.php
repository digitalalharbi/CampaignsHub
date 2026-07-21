<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\CRM\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Appends an entry to a subject's unified timeline. */
final class RecordActivity
{
    /** @param array<string,mixed> $meta */
    public function execute(Model $subject, string $type, ?string $body = null, array $meta = []): Activity
    {
        $activity = new Activity([
            'type' => $type,
            'body' => $body,
            'meta' => $meta === [] ? null : $meta,
            'user_id' => Auth::id(),
        ]);
        $activity->subject()->associate($subject);
        $activity->save();

        return $activity;
    }
}
