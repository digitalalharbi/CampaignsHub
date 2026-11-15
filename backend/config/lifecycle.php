<?php

declare(strict_types=1);

/*
 * PROJECT-LIFECYCLE-001 (docs/rules/project-lifecycle.md).
 */
return [
    // Plan-feature fallback for `projects.trash_retention_days` (TrashRetention reads the plan first).
    'trash_retention_days' => (int) env('LIFECYCLE_TRASH_RETENTION_DAYS', 30),

    // The reason written on alerts and tasks the archive auto-closes (shown as-is in Arabic; the key is the i18n key).
    'archive_close_reason' => 'المشروع مؤرشف',
    'archive_close_reason_key' => 'projects.lifecycle.closed_reason.archived',
];
