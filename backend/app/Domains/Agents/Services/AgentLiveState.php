<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use Illuminate\Support\Facades\Cache;

/**
 * AGENTS-STREAM-001 — the reply as it is being written, and the Stop button.
 *
 * The turn runs on the queue (a turn with tool rounds outlasts a web request); the provider's tokens stream into this buffer as
 * they arrive and the screen reads it with a light, short poll (`GET …/live`) — one cache read, no worker held open per viewer on
 * the one-CPU host. Stop sets a flag the running turn checks between every streamed event and before every model call, so a
 * stopped reply stops spending tokens at once. Both keys expire on their own; nothing here is the record — the stored messages are.
 */
final class AgentLiveState
{
    private const TTL = 900;

    /** @param  array{phase?: string, specialist?: string|null, text?: string, tools?: list<string>}  $patch */
    public static function put(string $threadId, array $patch): void
    {
        $now = self::get($threadId);
        $next = array_merge($now, $patch);
        $next['seq'] = ($now['seq'] ?? 0) + 1;
        Cache::put(self::key($threadId), $next, self::TTL);
    }

    public static function appendText(string $threadId, string $delta): void
    {
        $now = self::get($threadId);
        self::put($threadId, ['text' => ($now['text'] ?? '').$delta, 'phase' => 'writing']);
    }

    /** @return array{phase?: string, specialist?: string|null, text?: string, tools?: list<string>, seq?: int} */
    public static function get(string $threadId): array
    {
        $v = Cache::get(self::key($threadId));

        return is_array($v) ? $v : [];
    }

    public static function clear(string $threadId): void
    {
        Cache::forget(self::key($threadId));
        Cache::forget(self::stopKey($threadId));
    }

    public static function requestStop(string $threadId): void
    {
        Cache::put(self::stopKey($threadId), true, self::TTL);
    }

    public static function stopRequested(string $threadId): bool
    {
        return Cache::get(self::stopKey($threadId)) === true;
    }

    private static function key(string $id): string
    {
        return 'agents:live:'.$id;
    }

    private static function stopKey(string $id): string
    {
        return 'agents:stop:'.$id;
    }
}
