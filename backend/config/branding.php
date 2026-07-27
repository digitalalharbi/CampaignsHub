<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Brand asset storage disk
    |---------------------------------------------------------------------------
    | Brand files are written to a PRIVATE disk — the raw path is never exposed
    | by the API. Defaults to the app's `local` (private) disk. Point this at an
    | S3 disk in production; nothing else in the domain changes.
    */
    'disk' => env('BRANDING_DISK', 'local'),

    // Root prefix for every stored brand file on the disk above.
    'root' => env('BRANDING_ROOT', 'branding'),
];
