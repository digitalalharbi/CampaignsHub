<?php

declare(strict_types=1);

/*
 * CONTENT-INTAKE-001 — the quality gate for NEW content: platform reference specs and limits.
 *
 * Every number below is a published platform specification used as a REFERENCE for a pre-check, never a
 * measurement and never a promise of performance. A check reports the measured value next to the required one,
 * so a wrong spec is visible and fixable here in one place. A platform without an entry is reported as
 * «no spec registered» (not_measured) — it is never passed by default.
 *
 * `placements`: aspect ratios (w/h) a placement accepts. `kinds.<image|video>`: mime types, max bytes, minimum
 * short/long side in pixels (image + video), duration bounds in seconds (video). `copy`: character limits of the
 * text fields. `tolerance` is the allowed relative distance between the measured ratio and a listed one.
 */
return [
    // Hard bound on an uploaded file (the VPS is one vCPU): larger files come in as a link or from Drive.
    'max_upload_kb' => 51200,

    // AI analysis is parked: AI Connect and the Anthropic/OpenAI keys are not provided. The flag exists so the
    // adapter can be switched on later; with no credential it stays «awaiting credentials» and sends nothing.
    'ai' => ['enabled' => false],

    // Optional workspace naming convention (regex). Null = no convention defined, the check says so.
    'naming_pattern' => null,

    // Drive import is bounded per run so a large folder never saturates the queue worker.
    'drive_max_files_per_sync' => 50,

    'tolerance' => 0.03,

    'specs' => [
        'meta' => [
            'placements' => [
                'feed' => [1.0, 0.8],
                'story' => [0.5625],
                'reel' => [0.5625],
            ],
            'kinds' => [
                'image' => ['mime' => ['image/jpeg', 'image/png'], 'max_bytes' => 31457280, 'min_side' => 600],
                'video' => ['mime' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 4294967296, 'min_side' => 600, 'min_seconds' => 1, 'max_seconds' => 240 * 60],
            ],
            'copy' => ['headline' => 40, 'copy_text' => 125],
        ],
        'snapchat' => [
            'placements' => [
                'story' => [0.5625],
            ],
            'kinds' => [
                'image' => ['mime' => ['image/jpeg', 'image/png'], 'max_bytes' => 5242880, 'min_side' => 1080],
                'video' => ['mime' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 1073741824, 'min_side' => 1080, 'min_seconds' => 3, 'max_seconds' => 180],
            ],
            'copy' => ['headline' => 34],
        ],
        'tiktok' => [
            'placements' => [
                'feed' => [0.5625, 1.0, 1.7778],
                'story' => [0.5625],
            ],
            'kinds' => [
                'image' => ['mime' => ['image/jpeg', 'image/png'], 'max_bytes' => 512000, 'min_side' => 540],
                'video' => ['mime' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 524288000, 'min_side' => 540, 'min_seconds' => 5, 'max_seconds' => 60],
            ],
            'copy' => ['copy_text' => 100],
        ],
    ],

    // Safe-zone heuristic for tall placements: share of the frame height reserved top and bottom by the platform UI.
    'safe_zone' => [
        'story' => ['top' => 0.14, 'bottom' => 0.20],
        'reel' => ['top' => 0.14, 'bottom' => 0.20],
    ],
    // A band whose measured detail density exceeds the centre's by this factor is flagged (heuristic, not a verdict).
    'safe_zone_detail_ratio' => 1.5,
    'safe_zone_max_megapixels' => 25,
];
