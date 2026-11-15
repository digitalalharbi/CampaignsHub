<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

/**
 * AGENTS-STREAM-001 — a provider that can stream a turn and be stopped mid-way.
 *
 * `$system` is the STABLE prompt (sent as the cached prefix); `$context` is the per-conversation part sent after it. `onText`
 * receives each text delta as it arrives; `shouldStop` is asked between events — when it answers true the client stops reading,
 * returns what was written so far with stop `stopped`, and the turn spends nothing more.
 *
 * @phpstan-type StreamOptions array{effort?: string, max_tokens?: int, timeout?: float}
 */
interface StreamingModelClient extends ModelClient
{
    /**
     * @param  list<array{role: string, blocks: list<array<string, mixed>>}>  $history
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @param  array{effort?: string, max_tokens?: int, timeout?: float}  $options
     * @param  callable(string): void  $onText
     * @param  callable(): bool  $shouldStop
     */
    public function stream(string $model, string $system, string $context, array $history, array $tools, array $options, callable $onText, callable $shouldStop): ModelTurn;
}
