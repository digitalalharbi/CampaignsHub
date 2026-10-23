<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * DEPLOY-ENV-CONTRACT-001 — the ads-env repair, proved on fixtures before it touches production.
 *
 * It may only APPEND the four missing key names. The cases are mostly about absence: a real value is never replaced, unrelated
 * lines (a production env file is full of secrets) come through byte-for-byte, no value reaches the log, and a result the
 * script cannot prove comes back from the backup.
 */
final class AdsEnvRepairTest extends TestCase
{
    private const KEYS = ['AD_PLATFORM_REDIRECT_BASE', 'GOOGLE_ADS_CLIENT_ID', 'GOOGLE_ADS_CLIENT_SECRET', 'GOOGLE_ADS_DEVELOPER_TOKEN'];

    private const SECRETS = <<<'ENV'
        APP_KEY=base64:NOT-A-REAL-KEY-BUT-TREAT-IT-AS-ONE=
        DB_PASSWORD=p@ss w0rd with spaces and #hash
        STRIPE_SECRET=sk_live_DO_NOT_PRINT_ME

        # a comment
        SNAPCHAT_CLIENT_SECRET=snap-oauth-secret-value
        ENV;

    private string $dir;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = \dirname(__DIR__, 3);
        $this->dir = sys_get_temp_dir().'/ads-env-repair-'.uniqid();
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

    private function contract(array $extra = []): string
    {
        $path = $this->dir.'/contract';
        file_put_contents($path, implode("\n", array_merge(array_map(fn ($k) => $k.'=', self::KEYS), $extra))."\n");

        return $path;
    }

    private function env(string $body): string
    {
        $path = $this->dir.'/env';
        file_put_contents($path, $body);

        return $path;
    }

    private function repair(string $env, string $contract): Process
    {
        $p = new Process(['bash', 'scripts/repair-ads-env.sh', $env, $contract], $this->repo);
        $p->run();

        return $p;
    }

    public function test_it_appends_the_four_keys_and_leaves_every_other_byte_alone(): void
    {
        $env = $this->env(self::SECRETS."\n");
        $before = (string) file_get_contents($env);

        $p = $this->repair($env, $this->contract());
        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());

        $after = (string) file_get_contents($env);
        self::assertStringStartsWith($before, $after, 'Appending is the only edit.');
        self::assertStringContainsString("AD_PLATFORM_REDIRECT_BASE=https://api.campaignshub.io\n", $after);
        foreach (['GOOGLE_ADS_CLIENT_ID', 'GOOGLE_ADS_CLIENT_SECRET', 'GOOGLE_ADS_DEVELOPER_TOKEN'] as $k) {
            self::assertStringContainsString("\n{$k}=\n", $after, 'Blank on purpose: Awaiting Credentials, nothing invented.');
        }
    }

    public function test_a_real_value_already_in_the_file_is_never_replaced(): void
    {
        $env = $this->env(self::SECRETS."\nGOOGLE_ADS_CLIENT_ID=real-client-id\nAD_PLATFORM_REDIRECT_BASE=https://staging.example\n");

        $p = $this->repair($env, $this->contract());
        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());

        $after = (string) file_get_contents($env);
        self::assertStringContainsString("GOOGLE_ADS_CLIENT_ID=real-client-id\n", $after);
        self::assertStringContainsString("AD_PLATFORM_REDIRECT_BASE=https://staging.example\n", $after);
        self::assertSame(1, substr_count($after, 'GOOGLE_ADS_CLIENT_ID='));
        self::assertSame(1, substr_count($after, 'AD_PLATFORM_REDIRECT_BASE='));
        self::assertStringContainsString("\nGOOGLE_ADS_CLIENT_SECRET=\n", $after);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $env = $this->env(self::SECRETS."\n");
        $this->repair($env, $this->contract());
        $once = (string) file_get_contents($env);

        $p = $this->repair($env, $this->contract());

        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        self::assertSame($once, (string) file_get_contents($env));
        self::assertStringContainsString('already declared', $p->getOutput());
    }

    public function test_a_file_without_a_final_newline_does_not_glue_a_key_onto_its_last_line(): void
    {
        $env = $this->env(self::SECRETS);   // no trailing newline

        $p = $this->repair($env, $this->contract());
        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());

        $after = (string) file_get_contents($env);
        self::assertStringContainsString("snap-oauth-secret-value\nAD_PLATFORM_REDIRECT_BASE=", $after);
        self::assertSame(1, substr_count($after, 'SNAPCHAT_CLIENT_SECRET=snap-oauth-secret-value'));
    }

    public function test_no_value_out_of_the_file_reaches_the_log(): void
    {
        $env = $this->env(self::SECRETS."\nGOOGLE_ADS_DEVELOPER_TOKEN=real-dev-token-do-not-print\n");

        $p = $this->repair($env, $this->contract());
        $out = $p->getOutput().$p->getErrorOutput();

        foreach (['NOT-A-REAL-KEY', 'p@ss', 'sk_live_DO_NOT_PRINT_ME', 'snap-oauth-secret-value', 'real-dev-token-do-not-print'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
    }

    public function test_a_result_the_contract_still_refuses_is_restored_from_the_backup(): void
    {
        $env = $this->env(self::SECRETS."\n");
        $before = (string) file_get_contents($env);

        // The contract declares a key this repair does not own: after the repair the deploy would still refuse, so it must undo itself.
        $p = $this->repair($env, $this->contract(['SOME_OTHER_REQUIRED_KEY=x']));

        self::assertSame(1, $p->getExitCode());
        self::assertSame($before, (string) file_get_contents($env), 'A half-repaired production env is worse than the one it started with.');
        self::assertStringContainsString('restored', $p->getErrorOutput());
    }

    public function test_it_refuses_to_create_a_production_env_from_nothing(): void
    {
        $p = $this->repair($this->dir.'/missing', $this->contract());

        self::assertSame(2, $p->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/missing');
    }

    public function test_the_four_keys_are_the_ones_the_real_contract_declares(): void
    {
        $example = (string) file_get_contents($this->repo.'/deploy/backend.production.env.example');
        foreach (self::KEYS as $k) {
            self::assertMatchesRegularExpression('/^'.$k.'=/m', $example);
        }
        $script = (string) file_get_contents($this->repo.'/scripts/repair-ads-env.sh');
        self::assertStringContainsString('KEYS=('.implode(' ', self::KEYS).')', $script);
    }
}
