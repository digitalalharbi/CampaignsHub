<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

/**
 * AGENTS-ENGINE-001 — one model provider, behind the shape the engine needs.
 *
 * `history` is a list of turns: `['role' => 'user'|'assistant', 'blocks' => [...]]` where assistant blocks
 * are exactly what an earlier `respond()` returned and user blocks are either text or tool results
 * (`['type' => 'tool_result', 'tool_use_id' => ..., 'content' => string, 'is_error' => bool]`).
 */
interface ModelClient
{
    /**
     * @param  list<array{role: string, blocks: list<array<string, mixed>>}>  $history
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn;
}
