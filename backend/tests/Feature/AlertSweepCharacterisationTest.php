<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Notifications\Providers\MessageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAlertSweepWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — what `alerts:evaluate` raises, told and mailed is what it was before its reads were batched.
 *
 * The stored baseline `tests/Fixtures/alert_sweep_baseline.json` was written by the code as it stood BEFORE the query work, over the world in
 * {@see BuildsAlertSweepWorld}, across five sweeps: every event row, every notification and its delivery state, every task, every spend-limit crossing,
 * every digest ledger row and every mail with its recipient, language and items. Ids come from a counter, so a write that moved order would move them.
 *
 * Re-write the baseline only for an intended change of what alerts say, never for a performance change:
 * `WRITE_ALERT_SWEEP_BASELINE=1 ptest.sh <worktree> --filter=AlertSweepCharacterisationTest`.
 */
final class AlertSweepCharacterisationTest extends TestCase
{
    use BuildsAlertSweepWorld;
    use RefreshDatabase;

    private const BASELINE = __DIR__.'/../Fixtures/alert_sweep_baseline.json';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        config(['app.frontend_url' => 'https://app.alerts.test']);
        $this->freezeWorld();
        $this->buildAlertWorld();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    public function test_five_sweeps_leave_exactly_what_the_baseline_holds(): void
    {
        $now = (string) json_encode($this->runAlertSweeps(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // The world has to reach every handler, or identity proves nothing about it.
        $sweeps = json_decode($now, true);
        $fired = array_unique(array_column($sweeps['5_provider_stale']['alert_events'], 'type'));
        $periodic = AlertEvaluator::PERIODIC;
        sort($periodic);
        sort($fired);
        $this->assertSame($periodic, array_values($fired), 'every periodic type must fire in this world');
        $this->assertNotEmpty($sweeps['mail'], 'the world must mail somebody');
        $this->assertNotEmpty($sweeps['1_first']['spend_limit_events'], 'the workspace limit must be crossed');

        if (getenv('WRITE_ALERT_SWEEP_BASELINE') === '1') {
            file_put_contents(self::BASELINE, $now."\n");
        }

        $this->assertFileExists(self::BASELINE);
        $this->assertSame(rtrim((string) file_get_contents(self::BASELINE), "\n"), $now);
    }
}

final class ConfiguredAlertSweepEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'alert-sweep-ack', 'error' => null];
    }
}
