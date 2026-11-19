<?php

declare(strict_types=1);

return [
    'fields' => ['platform' => 'platform', 'objective' => 'objective'],
    'why' => [
        'drive' => 'the file is in Drive and its bytes are not read',
        'unread' => 'the file header could not be read',
        'video' => 'a video frame is not inspected',
        'too_large' => 'the image is too large to inspect cheaply',
        'flat' => 'the image has no measurable detail in the centre',
        'no_url' => 'no destination link is set',
    ],
    'utm_missing' => 'it is missing :missing',
    'checks' => [
        'target' => [
            'pass' => 'Platform and objective are set.',
            'fail' => 'Missing: :missing_text. The platform rules and the comparison need both.',
        ],
        'assets' => [
            'pass' => 'The content has its asset.',
            'fail' => 'No :kind asset, text or link was provided.',
        ],
        'spec' => ['not_measured' => 'No published spec is registered for ":platform", so size, ratio and length were not checked.'],
        'format' => [
            'pass' => 'File type :measured is accepted.',
            'fail' => 'File type :measured is not accepted (accepted: :required).',
            'not_measured' => 'File type not measured: :why_text.',
        ],
        'file_size' => [
            'pass' => 'File size :measured MB is within :required MB.',
            'fail' => 'File size :measured MB is above the :required MB limit.',
            'not_measured' => 'File size not measured.',
        ],
        'aspect_ratio' => [
            'pass' => 'Aspect ratio :ratio (:measured) is accepted.',
            'fail' => 'Aspect ratio :ratio (:measured) is not one of: :required.',
            'not_measured' => 'Aspect ratio not measured: :why_text.',
        ],
        'resolution' => [
            'pass' => 'Shortest side :measured px meets the :required px minimum.',
            'fail' => 'Shortest side :measured px is below the :required px minimum.',
            'not_measured' => 'Resolution not measured: :why_text.',
        ],
        'duration' => [
            'pass' => 'Duration :measured s is within :required s.',
            'fail' => 'Duration :measured s is outside :required s.',
            'not_measured' => 'Duration not measured: :why_text.',
        ],
        'safe_zone' => [
            'pass' => 'Detail in the top and bottom bands (:top / :bottom) does not exceed :factor times the centre (:centre).',
            'warn' => 'Heuristic: detail in the top (:top) or bottom (:bottom) band exceeds :factor times the centre (:centre). Check text and logos against the platform interface.',
            'not_measured' => 'Safe zone not measured: :why_text.',
        ],
        'copy_length' => [
            'pass' => 'Text lengths are within the platform limits.',
            'warn' => 'Text above the platform limit: :fields.',
        ],
        'utm' => [
            'pass' => 'The link carries utm_source, utm_medium and utm_campaign.',
            'warn' => 'Link: :detail.',
        ],
        'naming' => [
            'pass' => 'The name follows the workspace convention.',
            'warn' => 'The name does not match the workspace convention :pattern.',
            'not_measured' => 'The workspace has no naming convention defined.',
        ],
        'duplicate' => [
            'pass' => 'No identical file in review, approved or live.',
            'warn' => 'An identical file exists in another project: ":name" (:status).',
            'fail' => 'An identical file already exists in this project: ":name" (:status).',
        ],
    ],
    'recommendations' => [
        'history_insufficient' => 'Only :peers measured items for this objective and platform; at least :needed are needed for a comparison.',
        'format_stronger' => 'In the last :days days, :format content for this objective and platform averaged :same :metric against :other for other formats (:n_same / :n_other items).',
        'format_weaker' => 'In the last :days days, :format content for this objective and platform averaged :same :metric against :other for other formats (:n_same / :n_other items), which is weaker.',
    ],
    'notify' => [
        'submitted' => 'New content awaits review',
        'decided' => 'A decision on your content',
    ],
];
