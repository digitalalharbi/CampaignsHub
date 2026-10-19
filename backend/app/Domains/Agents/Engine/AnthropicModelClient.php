<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use Anthropic\Client;
use Anthropic\Messages\Message;

/**
 * AGENTS-ENGINE-001 — Claude, through the official Anthropic PHP SDK.
 *
 * History is replayed exactly as returned (thinking blocks included), rebuilt from the stored wire-format
 * blocks with the SDK's own `Message::fromArray`, because models that reason between tool calls refuse a
 * history that was edited. The system prompt is sent as a cached block: it is the same for every turn of
 * a role, and the rules it contains are long.
 */
final class AnthropicModelClient implements ModelClient
{
    public function __construct(private readonly string $apiKey) {}

    public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn
    {
        $client = new Client(apiKey: $this->apiKey);

        $messages = array_map(fn (array $turn): array => [
            'role' => $turn['role'],
            'content' => $turn['role'] === 'assistant' ? $this->replay($turn['blocks']) : $this->userContent($turn['blocks']),
        ], $history);

        $response = $client->messages->create(
            maxTokens: $maxTokens,
            messages: $messages,
            model: $model,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            tools: array_map(static fn (array $t): array => [
                'name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['input_schema'],
            ], $tools),
        );

        $blocks = [];
        $text = '';
        $calls = [];
        foreach ($response->content as $block) {
            $blocks[] = json_decode((string) json_encode($block), true);
            if ($block->type === 'text') {
                $text .= $block->text;
            }
            if ($block->type === 'tool_use') {
                $calls[] = ['id' => (string) $block->id, 'name' => (string) $block->name, 'input' => (array) $block->input];
            }
        }

        $stop = match ($response->stopReason) {
            'tool_use' => 'tool_use',
            'max_tokens' => 'max_tokens',
            'refusal' => 'refusal',
            default => 'end',
        };

        return new ModelTurn($blocks, $text, $calls, $stop, [
            'input_tokens' => (int) $response->usage->inputTokens,
            'output_tokens' => (int) $response->usage->outputTokens,
        ], $model);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<mixed>
     */
    private function replay(array $blocks): array
    {
        // Another provider's turn in the same thread: its private blocks (Gemini thoughts) are not sent,
        // and its extra keys are dropped from the neutral text and tool_use blocks.
        $blocks = array_values(array_filter($blocks, static fn (array $b): bool => in_array($b['type'] ?? '', ['text', 'tool_use', 'thinking', 'redacted_thinking'], true)));
        $blocks = array_map(static function (array $b): array {
            unset($b['gemini_signature']);

            return $b;
        }, $blocks);

        return Message::fromArray([
            'id' => 'replay', 'type' => 'message', 'role' => 'assistant', 'model' => 'replay',
            'stop_reason' => null, 'stop_sequence' => null, 'content' => $blocks,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ])->content;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function userContent(array $blocks): array
    {
        return array_map(static fn (array $b): array => ($b['type'] ?? '') === 'tool_result'
            ? ['type' => 'tool_result', 'toolUseID' => $b['tool_use_id'], 'content' => (string) $b['content'], 'isError' => (bool) ($b['is_error'] ?? false)]
            : ['type' => 'text', 'text' => (string) ($b['text'] ?? '')], $blocks);
    }
}
