<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\Drive\Models\DriveFile;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Drive\Providers\DriveProviderRegistry;
use App\Domains\Drive\Services\DriveService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * CONTENT-INTAKE-001 — imports the files of a PROJECT's linked Drive folder into the intake pipeline.
 *
 * Read-only and metadata-only: it keeps the Drive file id and modified time, never the bytes, so size, ratio and
 * duration are «not measured» for a Drive item (the check says so). Idempotent per (project, Drive file id): a second
 * run creates nothing and changes only a file whose modified time moved — and such a change sends an item that was
 * in review or approved back to «needs changes», through the one transition function, with the reason on the trail.
 *
 * Without credentials the provider is unconfigured: the link records `awaiting_credentials`, nothing is fetched and no
 * file is invented. A provider failure is recorded as the sync error (redacted and bounded), never thrown at the UI.
 */
final class DriveIntakeImporter
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly DriveProviderRegistry $providers,
        private readonly IntakeAnalysis $analysis,
        private readonly IntakeTransitions $transitions,
    ) {}

    /** @return array{state: string, created: int, updated: int, unchanged: int, skipped: int, truncated: bool} */
    public function sync(DriveLink $link): array
    {
        $summary = ['state' => 'awaiting_credentials', 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'truncated' => false];

        if ($link->scope !== 'project' || $link->scope_id === null) {
            $link->forceFill(['sync_state' => 'error', 'last_error' => 'not_a_project_link'])->save();

            return ['state' => 'error'] + $summary;
        }
        if (! $this->providers->default()->isConfigured()) {
            $link->forceFill(['sync_state' => 'awaiting_credentials', 'last_error' => null])->save();

            return $summary;
        }

        try {
            $listing = $this->drive->listFiles($link);
            $files = $listing['files'];
            $max = (int) config('content_intake.drive_max_files_per_sync', 50);
            $summary['truncated'] = count($files) > $max;
            foreach (array_slice($files, 0, $max) as $file) {
                $kind = str_starts_with((string) $file->mime, 'video/') ? 'video' : (str_starts_with((string) $file->mime, 'image/') ? 'image' : null);
                if ($kind === null) {
                    $summary['skipped']++;

                    continue;
                }
                $summary[$this->upsert($link, $file, $kind)]++;
            }
            $summary['state'] = $listing['state'];
            $link->forceFill([
                'sync_state' => $listing['state'], 'last_synced_at' => Carbon::now(), 'last_error' => null,
                'last_sync_summary' => $summary,
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $summary['state'] = 'error';
            $link->forceFill(['sync_state' => 'error', 'last_error' => $this->redact($e->getMessage()), 'last_sync_summary' => $summary])->save();
        }

        return $summary;
    }

    /** @return 'created'|'updated'|'unchanged' */
    private function upsert(DriveLink $link, DriveFile $file, string $kind): string
    {
        $item = ContentIntakeItem::query()->where('project_id', $link->scope_id)->where('drive_file_id', $file->file_id)->first();

        if ($item === null) {
            $item = new ContentIntakeItem([
                'project_id' => $link->scope_id, 'tenant_id' => $link->tenant_id, 'name' => $file->name, 'kind' => $kind, 'status' => 'draft',
                'source' => 'drive', 'mime' => $file->mime, 'size_bytes' => $file->size, 'drive_file_id' => $file->file_id,
                'drive_link_id' => $link->getKey(), 'drive_modified_time' => $file->modified_time, 'uploader_name' => 'Google Drive',
                'destination_url' => null,
            ]);
            $item->save();
            $this->transitions->record($item, 'drive_imported', null, null, 'draft', null, null, ['drive_file_id' => $file->file_id]);
            $this->analysis->run($item);

            return 'created';
        }

        $same = ($item->drive_modified_time === null && $file->modified_time === null)
            || ($item->drive_modified_time !== null && $file->modified_time !== null && $item->drive_modified_time->equalTo($file->modified_time));
        if ($same) {
            return 'unchanged';
        }

        $item->forceFill([
            'name' => $file->name, 'mime' => $file->mime, 'size_bytes' => $file->size,
            'drive_modified_time' => $file->modified_time, 'source_changed_at' => Carbon::now(),
        ])->save();
        $this->transitions->sourceChanged($item);
        $this->analysis->run($item);

        return 'updated';
    }

    private function redact(string $message): string
    {
        $message = (string) preg_replace('/[A-Za-z0-9_\-\.]{30,}/', '[redacted]', $message);

        return mb_substr($message, 0, 300);
    }
}
