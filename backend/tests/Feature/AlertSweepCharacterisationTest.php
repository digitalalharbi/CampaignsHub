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
        $baseline = rtrim((string) file_get_contents(self::BASELINE), "\n");

        // Never `assertSame` on the two documents: PHPUnit's diff of two ~1 MB strings took 400–870 s, so a failure looked like a hang.
        if ($baseline !== $now) {
            $this->fail($this->differences((array) json_decode($baseline, true), $sweeps));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Where the two documents part, bounded: a count and one example per `sweep.table.column`, and at most 40 lines.
     *
     * @param  array<string, mixed>  $was
     * @param  array<string, mixed>  $is
     */
    private function differences(array $was, array $is): string
    {
        /** @var array<string, array{n: int, was: mixed, is: mixed}> $found */
        $found = [];
        $note = static function (string $where, mixed $a, mixed $b) use (&$found): void {
            $found[$where] ??= ['n' => 0, 'was' => $a, 'is' => $b];
            $found[$where]['n']++;
        };

        foreach (array_unique(array_merge(array_keys($was), array_keys($is))) as $part) {
            // `mail` is a list of messages; a sweep is `raised` plus one list of rows per table.
            $tablesWas = $part === 'mail' ? ['messages' => $was[$part] ?? null] : (array) ($was[$part] ?? []);
            $tablesIs = $part === 'mail' ? ['messages' => $is[$part] ?? null] : (array) ($is[$part] ?? []);

            foreach (array_unique(array_merge(array_keys($tablesWas), array_keys($tablesIs))) as $table) {
                $where = $part.'.'.$table;
                $rowsWas = $tablesWas[$table] ?? null;
                $rowsIs = $tablesIs[$table] ?? null;

                if (! is_array($rowsWas) || ! is_array($rowsIs)) {
                    if ($rowsWas !== $rowsIs) {
                        $note($where, $rowsWas, $rowsIs);
                    }

                    continue;
                }

                if (count($rowsWas) !== count($rowsIs)) {
                    $note($where.' (rows)', count($rowsWas), count($rowsIs));
                }

                foreach ($rowsWas as $i => $rowWas) {
                    $rowWas = (array) $rowWas;
                    $rowIs = (array) ($rowsIs[$i] ?? []);

                    foreach (array_unique(array_merge(array_keys($rowWas), array_keys($rowIs))) as $column) {
                        $a = array_key_exists($column, $rowWas) ? $rowWas[$column] : '(no such key)';
                        $b = array_key_exists($column, $rowIs) ? $rowIs[$column] : '(no such key)';

                        if ($a !== $b) {
                            $note($where.'.'.$column, $a, $b);
                        }
                    }
                }
            }
        }

        $short = static fn (mixed $v): string => mb_strimwidth((string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 90, '…');
        $lines = [];
        foreach (array_slice($found, 0, 40, true) as $where => $d) {
            $lines[] = sprintf('  %s ×%d  baseline %s  now %s', $where, $d['n'], $short($d['was']), $short($d['is']));
        }

        return sprintf(
            "The sweep no longer leaves what the baseline holds: %d place(s) differ%s.\n%s\nFor the full diff: WRITE_ALERT_SWEEP_BASELINE=1, then `git diff` the fixture.",
            count($found),
            count($found) > 40 ? ', the first 40 shown' : '',
            implode("\n", $lines),
        );
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
