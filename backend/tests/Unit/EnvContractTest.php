<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the deploy must notice a server running a configuration nobody described.
 *
 * The example env file is the deployment contract: in git, reviewed, with comments explaining why each
 * value is what it is. The live file is on the server and in no repository. Nothing compared them, so
 * they drifted — the example gained `REPORTS_CHROMIUM_ENABLED=true` with a comment saying production
 * had taken «disable it» without the honesty of saying so, the live file never gained the block, and
 * every client PDF failed while the export button stayed on screen. The deploy's only check was that
 * the file EXISTS, which is exactly what a switched-off renderer passes.
 *
 * These cases are as much about what the check may PRINT as about what it catches: the live file holds
 * secrets, the output goes into a deploy log, and a drift report that quoted a value would trade one
 * silent failure for a louder one.
 */
final class EnvContractTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/env-contract-'.uniqid();
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

    private function check(string $contract, string $live): Process
    {
        $script = \dirname(__DIR__, 3).'/scripts/env-contract.sh';
        self::assertFileExists($script, 'the deploy calls this script — it must exist');

        $p = new Process(['bash', $script, $contract, $live]);
        $p->run();

        return $p;
    }

    private function write(string $name, string $body): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $body);

        return $path;
    }

    public function test_a_missing_key_stops_the_deploy_and_is_named(): void
    {
        $contract = $this->write('contract', "APP_KEY=\nREPORTS_CHROMIUM_ENABLED=true\n# a comment\nREPORTS_REQUIRE_BASE=/opt/print/package.json\n");
        $live = $this->write('live', "APP_KEY=a-real-secret\n");

        $p = $this->check($contract, $live);
        $out = $p->getOutput().$p->getErrorOutput();

        self::assertSame(1, $p->getExitCode(), 'drift must refuse the deploy, not warn into a log nobody reads');
        self::assertStringContainsString('REPORTS_CHROMIUM_ENABLED', $out);
        self::assertStringContainsString('REPORTS_REQUIRE_BASE', $out);
    }

    /** The live file is full of secrets and this output goes into a deploy log. */
    public function test_it_prints_no_value_from_either_file(): void
    {
        $contract = $this->write('contract', "APP_KEY=\nSTRIPE_SECRET=example-placeholder\nMISSING_ONE=contract-value\n");
        $live = $this->write('live', "APP_KEY=base64:REAL-APP-KEY\nSTRIPE_SECRET=sk_live_REAL\n");

        $p = $this->check($contract, $live);
        $out = $p->getOutput().$p->getErrorOutput();

        self::assertSame(1, $p->getExitCode());
        self::assertStringContainsString('MISSING_ONE', $out, 'the missing KEY is the point of the report');

        foreach (['base64:REAL-APP-KEY', 'sk_live_REAL', 'example-placeholder', 'contract-value'] as $value) {
            self::assertStringNotContainsString($value, $out, "«{$value}» reached a deploy log");
        }
    }

    /** Extra keys on the server are not drift — the contract is a floor, not a ceiling. */
    public function test_a_server_carrying_more_than_the_contract_is_accepted(): void
    {
        $contract = $this->write('contract', "A=1\nB=2\n");
        $live = $this->write('live', "A=x\nB=y\nC=an-extra-one\n");

        $p = $this->check($contract, $live);

        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());
    }

    /** Comments and blank lines declare nothing. */
    public function test_a_commented_key_is_not_a_declared_key(): void
    {
        $contract = $this->write('contract', "A=1\n\n# B=2\n#C=3\n");
        $live = $this->write('live', "A=x\n");

        $p = $this->check($contract, $live);

        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());
    }

    /** A path that is not there is a different failure from drift, and says so. */
    public function test_a_missing_file_is_its_own_exit_code(): void
    {
        $contract = $this->write('contract', "A=1\n");

        $p = $this->check($contract, $this->dir.'/not-here');

        self::assertSame(2, $p->getExitCode());
    }

    /**
     * The production contract and the deploy must not drift APART either.
     *
     * A check the deploy stops calling is a check that no longer exists, and this one is invisible
     * until a server is already wrong.
     */
    public function test_the_deploy_actually_calls_the_contract_check(): void
    {
        $deploy = (string) file_get_contents(\dirname(__DIR__, 3).'/scripts/deploy-production.sh');

        self::assertStringContainsString('scripts/env-contract.sh', $deploy);
        self::assertMatchesRegularExpression(
            '/env-contract\.sh[^\n]*\n(?:.*\n)*?.*up -d --build/',
            $deploy,
            'the contract has to be checked BEFORE the image is built and started'
        );
    }
}
