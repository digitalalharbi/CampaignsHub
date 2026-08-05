<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * PROD-001 — a backup that either happens or says it did not.
 *
 * ## The property this command is built around
 *
 * A backup system's only real failure mode is the silent one. A nightly job that logs «done» while
 * writing a truncated dump, or writing nothing because the disk filled, is worse than no backup at
 * all — it converts «we have no backups» into «we believed we had backups», and the difference is
 * discovered on the single worst day. So every step here fails loudly: a missing `pg_dump`, an
 * unwritable target, a non-zero exit, or a dump that came out suspiciously small all abort the run
 * with a non-zero status and no manifest entry. Nothing is reported as backed up that was not.
 *
 * ## Why a manifest with checksums
 *
 * A file on disk is not a backup; a file you can prove is intact is. The manifest records the size
 * and SHA-256 of each artefact at the moment it was written, so a restore drill can verify the file
 * it is about to trust rather than assuming the bytes survived the year. `ops:backup --verify` re-reads
 * the manifest and re-hashes, which is the cheap half of a drill; the other half — actually restoring
 * into a scratch database — is a documented human step, because a command that could restore over a
 * live database is a command that will eventually restore over a live database.
 *
 * ## What is NOT in here
 *
 * No upload to object storage, and no encryption. Both are real requirements and both depend on
 * credentials this installation does not have; writing an S3 client that silently no-ops without them
 * is exactly the dishonesty above. The artefacts land on a local path, the runbook says to ship and
 * encrypt them with the platform's own tooling, and the command says so too rather than implying it
 * has already happened.
 */
final class BackupCommand extends Command
{
    protected $signature = 'ops:backup
        {--path= : Where to write the archive (default: storage/app/backups)}
        {--keep=14 : How many previous backups to retain}
        {--verify : Re-hash the artefacts of the latest backup instead of making a new one}';

    protected $description = 'Take a verifiable database + storage backup, or verify the last one.';

    /**
     * Below this, a dump is presumed truncated rather than merely small.
     *
     * A `pg_dump` of a schema this size cannot come out under a kilobyte even with no rows in it, so
     * anything that does is a write that failed halfway and returned zero — the exact case a size
     * check exists to catch, and one no exit code will report.
     */
    private const MIN_DUMP_BYTES = 1024;

    public function handle(): int
    {
        $root = rtrim((string) ($this->option('path') ?: storage_path('app/backups')), '/');

        if ($this->option('verify')) {
            return $this->verifyLatest($root);
        }

        if (! $this->ensureWritable($root)) {
            return self::FAILURE;
        }

        if (! $this->hasPgDump()) {
            $this->error('pg_dump is not on PATH. Nothing was backed up — install the PostgreSQL client tools first.');

            return self::FAILURE;
        }

        $stamp = Carbon::now()->format('Y-m-d_His');
        $dir = $root.'/'.$stamp;

        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}. Nothing was backed up.");

            return self::FAILURE;
        }

        $dump = $dir.'/database.dump';

        if (! $this->dumpDatabase($dump)) {
            return self::FAILURE;
        }

        $artefacts = ['database.dump' => $dump];

        // Uploaded files live outside the database; a dump without them restores a system whose every
        // attachment link is broken.
        $storage = $dir.'/storage.tar.gz';
        if ($this->archiveStorage($storage)) {
            $artefacts['storage.tar.gz'] = $storage;
        }

        $this->writeManifest($dir, $artefacts, $stamp);
        $this->prune($root, (int) $this->option('keep'));

        $this->info("Backup written to {$dir}");
        $this->line('This copy is LOCAL and UNENCRYPTED. Ship it off-host and encrypt it with your own tooling —');
        $this->line('a backup that only exists on the machine it protects is not a backup.');

        return self::SUCCESS;
    }

    private function dumpDatabase(string $target): bool
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        if (($db['driver'] ?? null) !== 'pgsql') {
            $this->error("Only the pgsql driver is supported; this installation uses '{$db['driver']}'. Nothing was backed up.");

            return false;
        }

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '--file='.$target,
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--username='.$db['username'],
            $db['database'],
        ], env: ['PGPASSWORD' => (string) $db['password']], timeout: 3600);

        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('pg_dump failed: '.trim($process->getErrorOutput()));

            return false;
        }

        $size = @filesize($target) ?: 0;

        if ($size < self::MIN_DUMP_BYTES) {
            $this->error("The dump is {$size} bytes, which is too small to be a real one. Treating this run as FAILED.");

            return false;
        }

        return true;
    }

    /** Storage is archived best-effort: a missing tar is reported, and does not invalidate the dump. */
    private function archiveStorage(string $target): bool
    {
        $source = storage_path('app');

        if (! is_dir($source)) {
            return false;
        }

        $process = new Process(['tar', '-czf', $target, '-C', $source, '--exclude=backups', '.'], timeout: 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->warn('The storage archive failed: '.trim($process->getErrorOutput()));
            $this->warn('The database dump is still valid; uploaded files are NOT in this backup.');

            return false;
        }

        return true;
    }

    /** @param array<string,string> $artefacts */
    private function writeManifest(string $dir, array $artefacts, string $stamp): void
    {
        $files = [];

        foreach ($artefacts as $name => $path) {
            $files[] = [
                'name' => $name,
                'bytes' => filesize($path),
                'sha256' => hash_file('sha256', $path),
            ];
        }

        file_put_contents($dir.'/manifest.json', json_encode([
            'taken_at' => Carbon::now()->toIso8601String(),
            'stamp' => $stamp,
            'app_env' => config('app.env'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'files' => $files,
            'encrypted' => false,
            'offsite' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Re-hash the newest backup against its manifest.
     *
     * The cheap half of a restore drill, and the half worth automating: it catches bit rot, a
     * truncated copy and a half-finished transfer. The expensive half — restoring into a scratch
     * database and opening the product against it — is a documented human step on purpose. A command
     * able to restore is a command that will one day restore over something live.
     */
    private function verifyLatest(string $root): int
    {
        $dirs = glob($root.'/*/manifest.json') ?: [];

        if ($dirs === []) {
            $this->error("No backup manifest found under {$root}. There is nothing to verify.");

            return self::FAILURE;
        }

        rsort($dirs);
        $manifestPath = $dirs[0];
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $dir = dirname($manifestPath);
        $ok = true;

        foreach ($manifest['files'] ?? [] as $file) {
            $path = $dir.'/'.$file['name'];

            if (! is_file($path)) {
                $this->error("MISSING  {$file['name']}");
                $ok = false;

                continue;
            }

            $actual = hash_file('sha256', $path);

            if ($actual !== $file['sha256']) {
                $this->error("CORRUPT  {$file['name']} — the checksum does not match the manifest.");
                $ok = false;

                continue;
            }

            $this->info("OK       {$file['name']} ({$file['bytes']} bytes)");
        }

        $this->line("Backup taken at {$manifest['taken_at']}");

        if (! $ok) {
            $this->error('This backup cannot be trusted. Take a fresh one and find out what happened to this one.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** Keep the newest `$keep` and remove the rest — never the newest, whatever `$keep` says. */
    private function prune(string $root, int $keep): void
    {
        $keep = max(1, $keep);
        $dirs = array_filter((array) glob($root.'/*'), 'is_dir');

        rsort($dirs);

        foreach (array_slice($dirs, $keep) as $old) {
            foreach ((array) glob($old.'/*') as $file) {
                @unlink($file);
            }
            @rmdir($old);
            $this->line("pruned {$old}");
        }
    }

    private function ensureWritable(string $root): bool
    {
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            $this->error("Cannot create {$root}. Nothing was backed up.");

            return false;
        }

        if (! is_writable($root)) {
            $this->error("{$root} is not writable. Nothing was backed up.");

            return false;
        }

        return true;
    }

    private function hasPgDump(): bool
    {
        $which = new Process(['which', 'pg_dump']);
        $which->run();

        return $which->isSuccessful();
    }
}
