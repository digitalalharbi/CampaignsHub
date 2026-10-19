<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

/**
 * What one model call returned, in a provider-neutral shape.
 *
 * `blocks` is the provider's own content, to be stored and replayed unchanged; `text` and `toolCalls`
 * are what this product reads from it. `stop` is one of: tool_use, end, max_tokens, refusal.
 */
final class ModelTurn
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  array<string, int>  $usage
     */
    public function __construct(
        public readonly array $blocks,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly string $stop,
        public readonly array $usage,
        public readonly string $model,
    ) {}
}
