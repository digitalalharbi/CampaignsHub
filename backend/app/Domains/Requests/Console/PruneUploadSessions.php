<?php

declare(strict_types=1);

namespace App\Domains\Requests\Console;

use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestUploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete expired upload sessions and their orphaned files (uploaded but never submitted). Schedule
 * hourly. Files already associated to a request (request_id set) are untouched.
 */
final class PruneUploadSessions extends Command
{
    protected $signature = 'requests:prune-uploads';

    protected $description = 'Remove expired upload sessions and their orphaned files';

    public function handle(): int
    {
        $sessions = RequestUploadSession::where('expires_at', '<', now())->get();
        $files = 0;

        foreach ($sessions as $session) {
            /** @var RequestFile $file */
            foreach ($session->files()->whereNull('request_id')->get() as $file) {
                Storage::disk($file->disk)->delete($file->path);
                $file->delete();
                $files++;
            }
            $session->delete();
        }

        $this->info("Pruned {$sessions->count()} expired session(s), {$files} orphan file(s).");

        return self::SUCCESS;
    }
}
