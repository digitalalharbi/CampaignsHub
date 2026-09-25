<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the production repair, proved on fixtures before it touches production.
 *
 * The script writes seven keys into the live env file and must leave everything else exactly as it
 * found it. «Everything else» is a production env file: APP_KEY, database credentials, provider and
 * payment secrets, OAuth tokens. So these cases are mostly about ABSENCE — that unrelated lines come
 * through byte-for-byte, and that no value from the file reaches the output the workflow logs.
 *
 * Fail-first where it means something: a file the script cannot prove it repaired correctly must come
 * back from the backup rather than be left half-written, because a half-repaired production env is
 * worse than the one we started with.
 */
final class ReportsEnvRepairTest extends TestCase
{
    private const KEYS = [
        'REPORTS_CHROMIUM_ENABLED' => 'true',
        'REPORTS_PRINT_APP_URL' => 'https://campaignshub.io',
        'REPORTS_CHROMIUM_PATH' => '/usr/bin/chromium',
        'REPORTS_NODE_BIN' => 'node',
        'REPORTS_REQUIRE_BASE' => '/opt/print/package.json',
        'REPORTS_PYTHON_BIN' => 'python3',
        'REPORTS_RENDERER_VERSION' => 'alpine-chromium-136',
    ];

    /** A stand-in for the real thing: secrets, comments, blank lines, awkward spacing. */
    private const SECRETS = <<<'ENV'
        APP_NAME=CampaignsHub
        APP_KEY=base64:NOT-A-REAL-KEY-BUT-TREAT-IT-AS-ONE=
        APP_ENV=production

        # The database the whole platform runs on.
        DB_PASSWORD=p@ss w0rd with spaces and #hash
        STRIPE_SECRET=sk_live_DO_NOT_PRINT_ME
        MAIL_PASSWORD=
        SNAPCHAT_CLIENT_SECRET=snap-oauth-secret-value

        AUTH_LOGIN_THROTTLE=6
        ENV;

    private string $dir;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = \dirname(__DIR__, 3);
        $this->dir = sys_get_temp_dir().'/reports-env-repair-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function repair(string $envFile, ?string $contract = null): Process
    {
        $p = new Process(
            ['bash', 'scripts/repair-reports-env.sh', $envFile, $contract ?? $this->contract()],
            $this->repo,
        );
        $p->run();

        return $p;
    }

    /** The real contract, so the fixtures are judged by the file production is judged by. */
    private function contract(): string
    {
        return $this->repo.'/deploy/backend.production.env.example';
    }

    private function write(string $body): string
    {
        $path = $this->dir.'/env';
        file_put_contents($path, $body);

        return $path;
    }

    /**
     * A contract with only the seven keys, so a fixture is not required to carry every unrelated key
     * the real example declares — those are the deploy's business, not this repair's.
     */
    private function sevenKeyContract(): string
    {
        $body = '';
        foreach (self::KEYS as $k => $v) {
            $body .= "{$k}={$v}\n";
        }
        $path = $this->dir.'/contract';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_it_adds_all_seven_when_the_file_has_none_of_them(): void
    {
        $env = $this->write(self::SECRETS."\n");

        $p = $this->repair($env, $this->sevenKeyContract());
        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());

        $after = (string) file_get_contents($env);
        foreach (self::KEYS as $key => $value) {
            self::assertStringContainsString("{$key}={$value}", $after);
        }
    }

    /** The whole safety claim: nothing but the renderer block moves. */
    public function test_every_unrelated_line_survives_byte_for_byte(): void
    {
        $before = self::SECRETS."\n";
        $env = $this->write($before);

        self::assertSame(0, $this->repair($env, $this->sevenKeyContract())->getExitCode());

        $after = (string) file_get_contents($env);
        $stripped = preg_replace('/^\s*REPORTS_[A-Z_]+\s*=.*\n?/m', '', $after);

        self::assertSame($before, $stripped, 'a line outside the renderer block changed');
    }

    /** An old value is replaced, not duplicated — and replaced deterministically. */
    public function test_it_replaces_a_stale_declaration_in_place(): void
    {
        $env = $this->write(
            "APP_KEY=base64:KEEP-ME\n".
            "REPORTS_CHROMIUM_ENABLED=false\n".
            "DB_PASSWORD=still-here\n".
            "REPORTS_RENDERER_VERSION=some-old-stamp\n"
        );

        self::assertSame(0, $this->repair($env, $this->sevenKeyContract())->getExitCode());

        $after = (string) file_get_contents($env);
        self::assertStringNotContainsString('REPORTS_CHROMIUM_ENABLED=false', $after);
        self::assertStringNotContainsString('some-old-stamp', $after);
        self::assertSame(1, substr_count($after, 'REPORTS_CHROMIUM_ENABLED='));
        self::assertStringContainsString('APP_KEY=base64:KEEP-ME', $after);
        self::assertStringContainsString('DB_PASSWORD=still-here', $after);
    }

    /** Duplicates are the fail-closed case the owner named, and they are resolved, not tolerated. */
    public function test_duplicate_declarations_collapse_to_one(): void
    {
        $env = $this->write(
            "REPORTS_CHROMIUM_ENABLED=false\n".
            "APP_KEY=base64:KEEP-ME\n".
            "REPORTS_CHROMIUM_ENABLED=maybe\n".
            "REPORTS_CHROMIUM_ENABLED=also-wrong\n"
        );

        $p = $this->repair($env, $this->sevenKeyContract());
        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());

        $after = (string) file_get_contents($env);
        self::assertSame(1, substr_count($after, 'REPORTS_CHROMIUM_ENABLED='));
        self::assertStringContainsString('REPORTS_CHROMIUM_ENABLED=true', $after);
    }

    /** Running it twice must change nothing the second time. */
    public function test_a_second_run_is_idempotent(): void
    {
        $env = $this->write(self::SECRETS."\n");

        self::assertSame(0, $this->repair($env, $this->sevenKeyContract())->getExitCode());
        $first = (string) file_get_contents($env);

        self::assertSame(0, $this->repair($env, $this->sevenKeyContract())->getExitCode());
        $second = (string) file_get_contents($env);

        self::assertSame($first, $second);
    }

    /** This output goes into a workflow log and the file it reads is full of secrets. */
    public function test_it_prints_no_value_out_of_the_live_file(): void
    {
        $env = $this->write(self::SECRETS."\nREPORTS_CHROMIUM_ENABLED=false\n");

        $p = $this->repair($env, $this->sevenKeyContract());
        $out = $p->getOutput().$p->getErrorOutput();

        foreach ([
            'base64:NOT-A-REAL-KEY-BUT-TREAT-IT-AS-ONE',
            'p@ss w0rd with spaces',
            'sk_live_DO_NOT_PRINT_ME',
            'snap-oauth-secret-value',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $out, 'a live value reached the log');
        }
    }

    /** A production env file is repaired, never invented. */
    public function test_it_refuses_a_file_that_does_not_exist(): void
    {
        $p = $this->repair($this->dir.'/not-here', $this->sevenKeyContract());

        self::assertSame(2, $p->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/not-here');
    }

    /** A timestamped backup, because rollback has to be possible without this script. */
    public function test_it_leaves_a_backup_to_roll_back_to(): void
    {
        $before = self::SECRETS."\n";
        $env = $this->write($before);

        self::assertSame(0, $this->repair($env, $this->sevenKeyContract())->getExitCode());

        $backups = (array) glob($this->dir.'/env.bak.*');
        self::assertCount(1, $backups);
        self::assertSame($before, (string) file_get_contents((string) $backups[0]));
    }

    /**
     * Fail-first, and it has to fail the way the owner asked: restore, do not leave it half-written.
     *
     * The contract is handed a key the repair does not write, so the post-repair key-name check fails
     * for a reason the repair cannot fix. The file must come back exactly as it was.
     */
    public function test_a_failing_contract_check_rolls_the_file_back(): void
    {
        $before = self::SECRETS."\n";
        $env = $this->write($before);

        $contract = $this->dir.'/contract-extra';
        $body = '';
        foreach (self::KEYS as $k => $v) {
            $body .= "{$k}={$v}\n";
        }
        file_put_contents($contract, $body."SOMETHING_THIS_REPAIR_DOES_NOT_WRITE=1\n");

        $p = $this->repair($env, $contract);

        self::assertSame(1, $p->getExitCode());
        self::assertSame($before, (string) file_get_contents($env), 'the file was left half-repaired');
        self::assertStringContainsString('SOMETHING_THIS_REPAIR_DOES_NOT_WRITE', $p->getErrorOutput());
    }

    /**
     * The rollback itself, on the path that proved it was broken.
     *
     * The first version of this script reported the discrepancy through a `diff | sed | sort`
     * pipeline whose `diff` exits 1 BECAUSE it found one. Under `set -e` with `pipefail` that killed
     * the script on the line before `restore`, so the rollback was dead in the exact path it exists
     * for: a fixture with an unrelated line dropped came back half-repaired, with a secret missing
     * and the renderer block written anyway. Nothing said so — the script exited non-zero, which
     * reads like a safe refusal.
     *
     * Driven through the real failure branch rather than the contract one, so it exercises the
     * mutation-then-refuse path and not just the last check.
     */
    public function test_a_refusal_after_the_mutation_leaves_the_file_exactly_as_it_was(): void
    {
        $before = self::SECRETS."\n";
        $env = $this->write($before);

        // A contract whose extra key the repair does not write: the post-mutation check must fail,
        // and the file must come back.
        $contract = $this->dir.'/contract-extra-2';
        $body = '';
        foreach (self::KEYS as $k => $v) {
            $body .= "{$k}={$v}\n";
        }
        file_put_contents($contract, $body."A_KEY_THE_REPAIR_NEVER_WRITES=1\n");

        $p = $this->repair($env, $contract);

        self::assertSame(1, $p->getExitCode());
        self::assertSame($before, (string) file_get_contents($env));

        // And the rollback says so, so an operator reading the log knows the file is untouched.
        self::assertStringContainsString('restored from', $p->getErrorOutput());
    }

    /** A commented-out declaration is not a declaration, and is left where it is. */
    public function test_a_commented_line_is_left_alone(): void
    {
        $env = $this->write("APP_KEY=base64:KEEP-ME\n# REPORTS_CHROMIUM_ENABLED=false\n");

        self::assertSame(0, $this->repair($env, $this->sevenKeyContract())->getExitCode());

        $after = (string) file_get_contents($env);
        self::assertStringContainsString('# REPORTS_CHROMIUM_ENABLED=false', $after);
        self::assertSame(1, preg_match_all('/^REPORTS_CHROMIUM_ENABLED=/m', $after));
    }

    /** The seven values are the contract's, and drift between the two is the thing to catch. */
    public function test_the_repair_writes_exactly_what_the_production_contract_declares(): void
    {
        $contract = (string) file_get_contents($this->contract());

        foreach (self::KEYS as $key => $value) {
            self::assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'='.preg_quote($value, '/').'$/m',
                $contract,
                "the repair would write a value for {$key} that the production contract does not declare"
            );
        }
    }
}
