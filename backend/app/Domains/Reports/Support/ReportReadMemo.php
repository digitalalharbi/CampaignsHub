<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use Closure;

/**
 * PERF-RECOVERY-001 — a read asked twice inside ONE report build is answered once.
 *
 * ## What it is for
 *
 * A public live report is assembled by many services that each restate the same question: which
 * campaigns may these accounts see, which providers owed this window figures, what did these
 * creatives do. Production measured 382 statements for one link, a large share of them the same
 * statement repeated with the same bindings. Each service is right to ask; none can know the others
 * did. This holds the answer for the length of the build and nothing longer.
 *
 * ## Why it cannot leak
 *
 *   - It is INACTIVE until {@see within()} opens a build. Outside one, {@see remember()} just runs
 *     the read, so a test, a command or a queue job that never opens a build sees no memo at all.
 *   - A build is bound to the tenant and project of the share it serves, and every key is prefixed
 *     with them. A nested build for another tenant or project gets a store of its own, and the
 *     outer one is not visible from inside it.
 *   - The store is discarded when the outermost build ends, in a `finally`, so an exception cannot
 *     leave answers behind for the next request on a long-running worker. The container binds this
 *     class `scoped`, which is a second line of the same defence.
 *   - Callers put everything the answer depends on in the key (accounts, window, providers,
 *     projects). A key that omitted an input would be a bug in that caller, and the isolation tests
 *     are written to fail on it.
 *
 * It memoises READS only. Nothing here may wrap a write, a dispatch or a cache fill.
 */
final class ReportReadMemo
{
    /** @var list<array{identity: string, store: array<string, mixed>}> */
    private array $builds = [];

    /**
     * Run a build with the memo open for the given tenant and project.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function within(string $tenantId, string $projectId, Closure $work): mixed
    {
        return app(self::class)->open($tenantId, $projectId, $work);
    }

    /**
     * The answer to `$key` for the build in progress, computing it on first use.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $key, Closure $compute): mixed
    {
        return app(self::class)->get($key, $compute);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function open(string $tenantId, string $projectId, Closure $work): mixed
    {
        $identity = $tenantId.'|'.$projectId;
        $top = array_key_last($this->builds);

        // A build inside a build of the SAME tenant and project shares its store; another tenant or project never does.
        if ($top !== null && $this->builds[$top]['identity'] === $identity) {
            return $work();
        }

        $this->builds[] = ['identity' => $identity, 'store' => []];

        try {
            return $work();
        } finally {
            array_pop($this->builds);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    private function get(string $key, Closure $compute): mixed
    {
        $top = array_key_last($this->builds);

        if ($top === null) {
            return $compute();
        }

        $full = $this->builds[$top]['identity'].'#'.$key;

        if (! array_key_exists($full, $this->builds[$top]['store'])) {
            // Computed BEFORE it is stored, so a read that throws is not remembered as an answer.
            $value = $compute();
            $this->builds[$top]['store'][$full] = $value;
        }

        return $this->builds[$top]['store'][$full];
    }
}
