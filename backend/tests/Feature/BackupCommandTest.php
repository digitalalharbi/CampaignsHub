<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROD-001 — a backup that either happens or says it did not.
 *
 * A backup system's only real failure mode is the silent one. A nightly job that logs «done» while
 * writing a truncated dump, or writing nothing at all because the disk filled, is worse than having
 * no backups: it turns «we have none» into «we believed we had some», and the difference is found out
 * on the single worst day of the year. Every test here is about a refusal being loud.
 */
final class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/backups-'.uniqid());
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
        parent::tearDown();
    }

    /** An unwritable target is a FAILED run, never a quiet one. */
    public function test_an_unwritable_target_fails_loudly_and_backs_nothing_up(): void
    {
        $this->artisan('ops:backup', ['--path' => '/proc/definitely-not-writable'])
            ->expectsOutputToContain('Nothing was backed up')
            ->assertExitCode(1);
    }

    /** Verifying when there is nothing to verify is a failure, not «all clear». */
    public function test_verifying_with_no_backup_present_is_a_failure(): void
    {
        $this->artisan('ops:backup', ['--path' => $this->root, '--verify' => true])
            ->expectsOutputToContain('There is nothing to verify')
            ->assertExitCode(1);
    }

    /**
     * A tampered artefact is CORRUPT, and the command exits non-zero so a cron wrapper notices.
     *
     * This is the property the manifest exists for: a file on disk is not a backup, a file you can
     * prove is intact is. Bit rot, a truncated copy and a half-finished transfer all look like a
     * perfectly ordinary file until something re-hashes it.
     */
    public function test_a_tampered_artefact_is_reported_as_corrupt(): void
    {
        $dir = $this->root.'/2026-01-01_000000';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/database.dump', 'the original bytes');

        file_put_contents($dir.'/manifest.json', json_encode([
            'taken_at' => '2026-01-01T00:00:00+00:00',
            'stamp' => '2026-01-01_000000',
            'files' => [[
                'name' => 'database.dump',
                'bytes' => 18,
                'sha256' => hash('sha256', 'the original bytes'),
            ]],
        ]));

        // Verifies clean first, so the failure below is the tampering and not the fixture.
        $this->artisan('ops:backup', ['--path' => $this->root, '--verify' => true])->assertExitCode(0);

        file_put_contents($dir.'/database.dump', 'the original bytes, plus one');

        $this->artisan('ops:backup', ['--path' => $this->root, '--verify' => true])
            ->expectsOutputToContain('CORRUPT')
            ->expectsOutputToContain('cannot be trusted')
            ->assertExitCode(1);
    }

    /** An artefact that vanished is MISSING — a different fact from a corrupt one, and stated as one. */
    public function test_a_missing_artefact_is_reported_as_missing(): void
    {
        $dir = $this->root.'/2026-01-01_000000';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/manifest.json', json_encode([
            'taken_at' => '2026-01-01T00:00:00+00:00',
            'files' => [['name' => 'database.dump', 'bytes' => 1, 'sha256' => 'whatever']],
        ]));

        $this->artisan('ops:backup', ['--path' => $this->root, '--verify' => true])
            ->expectsOutputToContain('MISSING')
            ->assertExitCode(1);
    }

    /**
     * Retention never removes the newest backup, whatever `--keep` says.
     *
     * `--keep=0` is a plausible typo and, taken literally, deletes everything — including the copy
     * taken thirty seconds ago. The floor is one.
     */
    public function test_retention_never_prunes_the_newest_backup(): void
    {
        foreach (['2026-01-01_000000', '2026-02-01_000000', '2026-03-01_000000'] as $stamp) {
            mkdir($this->root.'/'.$stamp, 0700, true);
            file_put_contents($this->root.'/'.$stamp.'/manifest.json', '{}');
        }

        $this->artisan('ops:backup', ['--path' => $this->root, '--keep' => 0])->run();

        $remaining = array_map('basename', array_filter((array) glob($this->root.'/*'), 'is_dir'));
        rsort($remaining);

        // Exactly one survives — never zero — and it is the newest, which is the backup just taken.
        $this->assertCount(1, $remaining);
        $this->assertGreaterThan('2026-03-01_000000', $remaining[0]);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.'/*') as $child) {
            is_dir($child) ? $this->rmrf($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
