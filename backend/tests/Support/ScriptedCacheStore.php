<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 — a cache store that misbehaves on purpose.
 *
 * `throw` — every operation raises, as a Redis outage does. `poison` — every read answers with a value that would WIDEN a
 * decision if anything trusted it (`true`, `1`, `['*']`), as a stale or corrupted entry does. A boundary that depends on the
 * cache must deny under the first and ignore the second; a boundary that does not depend on it must not change under either.
 */
final class ScriptedCacheStore implements LockProvider, Store
{
    public int $touched = 0;

    public function __construct(private readonly string $mode) {}

    private function touch(): mixed
    {
        $this->touched++;

        if ($this->mode === 'throw') {
            throw new RuntimeException('cache store is down (test)');
        }

        return ['*'];
    }

    public function get($key): mixed
    {
        return $this->touch();
    }

    public function many(array $keys): array
    {
        $this->touch();

        return array_fill_keys($keys, ['*']);
    }

    public function put($key, $value, $seconds): bool
    {
        $this->touch();

        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        $this->touch();

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $this->touch();

        return 1;
    }

    public function decrement($key, $value = 1): int
    {
        $this->touch();

        return 0;
    }

    public function forever($key, $value): bool
    {
        $this->touch();

        return true;
    }

    public function forget($key): bool
    {
        $this->touch();

        return true;
    }

    public function flush(): bool
    {
        $this->touch();

        return true;
    }

    /** A unique-job lock: raises when the store is down; when it lies, it simply grants the lock. */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->touch();

        return (new ArrayStore)->lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        $this->touch();

        return (new ArrayStore)->restoreLock($name, $owner);
    }

    public function getPrefix(): string
    {
        return '';
    }
}
