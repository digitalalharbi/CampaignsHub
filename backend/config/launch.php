<?php

declare(strict_types=1);

return [
    /*
     | LAUNCH-READINESS-001 — how an ad account's own synced results are read as evidence that an event is measured.
     | `eligible_events` is the volume (per window) at which a reported event is treated as enough for an optimiser to learn
     | from; below it the event is only `proven`. It is a product threshold, not a platform guarantee.
     */
    'readiness' => [
        'window_days' => (int) env('LAUNCH_READINESS_WINDOW_DAYS', 30),
        'eligible_events' => (int) env('LAUNCH_READINESS_ELIGIBLE_EVENTS', 50),
    ],
];
