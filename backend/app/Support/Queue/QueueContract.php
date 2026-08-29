<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * SNAP-STRUCTURE-RETRY-001 — the one place that knows how the queue's three timeouts relate.
 *
 * ## Why this exists at all
 *
 * `retry_after`, the worker timeout and a job's `$timeout` live in three different files, are read by
 * three different processes, and have no relationship the framework will check. Production held
 * `retry_after = 90` against a job declaring `$timeout = 900` for as long as the job existed. Nothing
 * threw. Redis simply handed the same structure sweep to a worker every ninety seconds while the
 * first one was still working, until the attempts ran out — and the only visible symptom was a
 * Campaigns page that stayed empty. Every test passed the whole time, because no test knew these
 * three numbers were a contract.
 *
 * The contract:
 *
 *     longest job timeout  <=  worker/supervisor timeout  <  retry_after
 *
 * Left to right: a worker must be allowed to run the longest job to completion; and the broker must
 * not re-deliver work until after the worker would have given up on it. `QueueRetryContractTest`
 * asserts this against the real configuration, and `queue:contract` prints it from the running
 * application so a deploy log is evidence rather than an assumption.
 *
 * Jobs are DISCOVERED rather than listed. A list is a thing somebody forgets to update, and the
 * failure this guards against is precisely a long job arriving beside a `retry_after` nobody thought
 * to revisit.
 */
final class QueueContract
{
    /**
     * Every queueable job in the application, with the timeout it declares.
     *
     * A null timeout means the job accepts the worker's, which is why the worker's own value is part
     * of the contract and not merely a default.
     *
     * @return array<class-string, int|null>
     */
    public static function jobTimeouts(): array
    {
        $timeouts = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR)
            );

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->implementsInterface(ShouldQueue::class) || $reflection->isAbstract()) {
                continue;
            }

            $timeouts[$class] = $reflection->hasProperty('timeout')
                ? $reflection->getProperty('timeout')->getDefaultValue()
                : null;
        }

        ksort($timeouts);

        return $timeouts;
    }

    /**
     * AUTOMATION-FIRST-OPERATIONS-001 — the retry STANCE every queued job has to take.
     *
     * `jobTimeouts()` above answers «how long may this run». This answers the other half the
     * requirement names: retry, backoff and failure classification. Discovered the same way and for
     * the same reason — a written list is the thing nobody updates, and the failure being guarded
     * against is a NEW job shipping with no stance at all.
     *
     * Two numbers, both read from the class rather than from a convention:
     *
     *   - `tries`: Laravel treats an absent or zero `$tries` as «retry forever» unless `retryUntil()`
     *     bounds it. Unbounded is not a stance — it is how a deterministic product defect becomes a
     *     job that fails every ninety seconds until somebody notices the queue, which is precisely
     *     the «automatic retry that hides a deterministic defect» this requirement forbids.
     *   - `backoff`: only meaningful when a job retries at all. Three immediate attempts against a
     *     throttled provider is not a retry policy; it is the same failure three times in a second,
     *     and against a rate limiter it is how a 429 becomes a longer 429.
     *
     * `retryUntil` counts as a bound, and `failed` as a declared classification, so a job may satisfy
     * the policy in more than one way — the point is that it says something, not that it says this.
     *
     * @return array<class-string, array{tries: int|null, retryUntil: bool, backoff: bool, failed: bool, unique: bool}>
     */
    public static function jobRetryStances(): array
    {
        $stances = [];

        foreach (array_keys(self::jobTimeouts()) as $class) {
            $reflection = new ReflectionClass($class);

            $stances[$class] = [
                'tries' => $reflection->hasProperty('tries')
                    ? $reflection->getProperty('tries')->getDefaultValue()
                    : null,
                'retryUntil' => $reflection->hasMethod('retryUntil'),
                'backoff' => $reflection->hasMethod('backoff') || $reflection->hasProperty('backoff'),
                'failed' => $reflection->hasMethod('failed'),
                'unique' => $reflection->implementsInterface(ShouldBeUnique::class),
            ];
        }

        return $stances;
    }

    /**
     * The longest a single job is allowed to run, across the whole application.
     */
    public static function longestJobTimeout(): int
    {
        $declared = array_filter(self::jobTimeouts(), fn ($t) => is_int($t));

        return $declared === [] ? 0 : max($declared);
    }

    /**
     * Every worker timeout Horizon can be started with — the defaults, and each environment's
     * override, because an override is exactly where a corrected default gets quietly undone.
     *
     * @return array<string, int> keyed «environment/supervisor»
     */
    public static function workerTimeouts(): array
    {
        $defaults = (array) config('horizon.defaults', []);
        $timeouts = [];

        foreach ($defaults as $supervisor => $settings) {
            $timeouts["defaults/{$supervisor}"] = (int) ($settings['timeout'] ?? 60);
        }

        foreach ((array) config('horizon.environments', []) as $environment => $supervisors) {
            foreach ((array) $supervisors as $supervisor => $settings) {
                $timeouts["{$environment}/{$supervisor}"] = (int) (
                    $settings['timeout']
                    ?? $defaults[$supervisor]['timeout']
                    ?? 60
                );
            }
        }

        return $timeouts;
    }

    /**
     * The connections whose broker re-delivers on a clock — the only ones this contract can bind.
     *
     * `sync` runs inline, `sqs` carries its own visibility timeout on the queue itself, and `null`
     * discards. None of them can re-deliver a job that is still running, so none of them belong here.
     *
     * @return array<string, int>
     */
    public static function retryAfterByConnection(): array
    {
        $found = [];

        foreach ((array) config('queue.connections', []) as $name => $settings) {
            if (isset($settings['retry_after'])) {
                $found[$name] = (int) $settings['retry_after'];
            }
        }

        return $found;
    }

    /**
     * Everything wrong with the current configuration, in the operator's words.
     *
     * @return list<string>
     */
    public static function violations(): array
    {
        $problems = [];
        $longestJob = self::longestJobTimeout();
        $workers = self::workerTimeouts();
        $retryAfters = self::retryAfterByConnection();

        foreach ($workers as $where => $workerTimeout) {
            if ($workerTimeout < $longestJob) {
                $problems[] = "Worker '{$where}' has timeout {$workerTimeout}s, below the longest job timeout of {$longestJob}s: "
                    .'a job that declares no timeout of its own would be killed before it could finish.';
            }
        }

        $mustExceed = max($longestJob, $workers === [] ? 0 : max($workers));

        foreach ($retryAfters as $connection => $retryAfter) {
            if ($retryAfter <= $mustExceed) {
                $problems[] = "Queue connection '{$connection}' has retry_after {$retryAfter}s, which is not above {$mustExceed}s "
                    .'(the longest job timeout and worker timeout): the broker would re-deliver a job that is still running, '
                    .'burning its attempts until MaxAttemptsExceeded. Raise retry_after above every timeout.';
            }
        }

        return $problems;
    }
}
