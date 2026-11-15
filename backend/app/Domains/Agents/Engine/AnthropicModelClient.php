<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\Message;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\TextDelta;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;

/**
 * AGENTS-ENGINE-001 / AGENTS-STREAM-001 — Claude, through the official Anthropic PHP SDK.
 *
 * History is replayed exactly as returned (thinking blocks included), rebuilt from the stored wire-format blocks with the SDK's
 * own `Message::fromArray`, because models that reason between tool calls refuse a history that was edited. The stable system
 * prompt is sent as a cached block (it is the same for every turn of a role); the per-conversation context follows it uncached.
 *
 * `stream()` reads the SDK's event stream, folds it with the SDK's `MessageAccumulator`, hands each text delta to the caller and
 * stops reading the moment the caller asks — the provider stops billing output for a closed stream.
 *
 * The HTTP transport is injectable: production uses Guzzle with a connect and a total timeout; tests hand in a fake PSR-18 client
 * that replays a recorded event stream, so no test ever reaches the network.
 */
final class AnthropicModelClient implements StreamingModelClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?ClientInterface $transport = null,
    ) {}

    public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn
    {
        $response = $this->client(60.0)->messages->create(
            maxTokens: $maxTokens,
            messages: $this->messages($history),
            model: $model,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            tools: $this->tools($tools),
        );

        return $this->turn($response, $model);
    }

    public function stream(string $model, string $system, string $context, array $history, array $tools, array $options, callable $onText, callable $shouldStop): ModelTurn
    {
        $system = [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]];
        if ($context !== '') {
            $system[] = ['type' => 'text', 'text' => $context];
        }

        $stream = $this->client((float) ($options['timeout'] ?? 120.0))->messages->createStream(
            maxTokens: (int) ($options['max_tokens'] ?? 8000),
            messages: $this->messages($history),
            model: $model,
            outputConfig: isset($options['effort']) ? ['effort' => $options['effort']] : null,
            system: $system,
            tools: $tools === [] ? null : $this->tools($tools),
        );

        $acc = MessageAccumulator::forMessages();
        $text = '';
        foreach ($stream as $event) {
            $acc->accumulate($event);
            if ($event instanceof RawContentBlockDeltaEvent && $event->delta instanceof TextDelta) {
                $text .= $event->delta->text;
                $onText($event->delta->text);
            }
            if ($shouldStop()) {
                // Only the words already shown are kept: a half-written tool call is never stored, so the history stays valid.
                return new ModelTurn($text === '' ? [] : [['type' => 'text', 'text' => $text]], $text, [], 'stopped', ['input_tokens' => 0, 'output_tokens' => 0], $model);
            }
        }

        $message = $acc->message();
        assert($message instanceof Message);

        return $this->turn($message, $model);
    }

    private function client(float $timeout): Client
    {
        $transport = $this->transport ?? new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => 10, 'stream' => true]);

        return new Client(apiKey: $this->apiKey, requestOptions: [
            'transporter' => $transport, 'streamingTransporter' => $transport, 'maxRetries' => 1, 'timeout' => $timeout,
        ]);
    }

    /**
     * @param  list<array{role: string, blocks: list<array<string, mixed>>}>  $history
     * @return list<array<string, mixed>>
     */
    private function messages(array $history): array
    {
        return array_map(fn (array $turn): array => [
            'role' => $turn['role'],
            'content' => $turn['role'] === 'assistant' ? $this->replay($turn['blocks']) : $this->userContent($turn['blocks']),
        ], $history);
    }

    /**
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @return list<array<string, mixed>>
     */
    private function tools(array $tools): array
    {
        return array_map(static fn (array $t): array => [
            'name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['input_schema'],
        ], $tools);
    }

    private function turn(Message $response, string $model): ModelTurn
    {
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

        return new ModelTurn($blocks, $text, $calls, $stop, array_filter([
            'input_tokens' => (int) $response->usage->inputTokens,
            'output_tokens' => (int) $response->usage->outputTokens,
            'cache_read_input_tokens' => (int) ($response->usage->cacheReadInputTokens ?? 0),
            'cache_creation_input_tokens' => (int) ($response->usage->cacheCreationInputTokens ?? 0),
        ], static fn (int $v, string $k): bool => $v > 0 || in_array($k, ['input_tokens', 'output_tokens'], true), ARRAY_FILTER_USE_BOTH), $model);
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
