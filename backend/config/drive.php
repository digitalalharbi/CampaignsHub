<?php

declare(strict_types=1);

use App\Domains\Drive\Providers\NullDriveProvider;
use App\Domains\Drive\Providers\SandboxDriveProvider;

return [
    /*
    |---------------------------------------------------------------------------
    | Default Drive provider
    |---------------------------------------------------------------------------
    | The provider key used to resolve file metadata. Ships as the honest Null
    | adapter — no Google OAuth, no file metadata is ever fetched. Wire a real
    | integration by pointing this at a configured class; nothing else changes.
    */
    'default' => env('DRIVE_PROVIDER', 'null'),

    /*
    |---------------------------------------------------------------------------
    | Sandbox override (off-production only)
    |---------------------------------------------------------------------------
    | When true AND the environment is not production, the deterministic offline
    | Sandbox provider is selected so the content-linking UI is fully exercisable
    | in dev / E2E. It can never surface demo files in production.
    */
    'sandbox' => (bool) env('DRIVE_SANDBOX', false),

    /*
    |---------------------------------------------------------------------------
    | Provider adapters
    |---------------------------------------------------------------------------
    | Each provider key maps to a DriveProvider implementation. The Null adapter
    | reports "not configured" and returns no file metadata; the Sandbox adapter
    | returns clearly-labelled demo files offline.
    */
    'providers' => [
        'null' => NullDriveProvider::class,
        'sandbox' => SandboxDriveProvider::class,
    ],

    // Kept explicit so a static scan proves the shipped default is the honest Null adapter.
    'defaults' => [
        'null' => NullDriveProvider::class,
    ],
];
