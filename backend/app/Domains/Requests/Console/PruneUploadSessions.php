<?php

declare(strict_types=1);

namespace App\Domains\Requests\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
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

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — what this sweep actually removed, on the ledger.
         *
         * The line below has always said it to whoever was watching a terminal. Nobody is watching a
         * terminal at 04:00, and the ops page could say the run SUCCEEDED without being able to say
         * whether it deleted four hundred sessions or none — which is the difference between a sweep
         * working and a sweep quietly matching nothing.
         */
        app(ScheduledRunRows::class)->report($sessions->count() + $files);

        $this->info("Pruned {$sessions->count()} expired session(s), {$files} orphan file(s).");

        return self::SUCCESS;
    }
}
