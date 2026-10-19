<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AGENTS-ENGINE-001 — OpenAI, through the documented Chat Completions function-calling contract.
 *
 * Shapes are the ones OpenAI's own OpenAPI specification defines: a tool is `{type: function, function:
 * {name, description, parameters}}`; the model's calls come back as `tool_calls[].function.arguments`, a
 * JSON STRING the specification warns may be invalid, so it is decoded defensively and an undecodable call
 * is answered with an error result rather than run; each answer is a `tool` message carrying its
 * `tool_call_id`. `finish_reason` maps stop/length/tool_calls/content_filter onto the engine's own stops,
 * and a `refusal` string is a refusal.
 *
 * The engine stores turns in a neutral block shape (`text`, `tool_use`, `tool_result`); this client turns
 * them into Chat Completions messages and back, and ignores any block it does not know (such as another
 * provider's reasoning blocks), so a thread survives a change of key.
 */
final class OpenAiModelClient implements ModelClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $organization = null,
        private readonly ?string $project = null,
        private readonly string $base = 'https://api.openai.com/v1',
    ) {}

    public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn
    {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $turn) {
            array_push($messages, ...($turn['role'] === 'assistant' ? $this->assistant($turn['blocks']) : $this->user($turn['blocks'])));
        }

        $body = ['model' => $model, 'messages' => $messages, 'max_completion_tokens' => $maxTokens];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (array $t): array => ['type' => 'function', 'function' => [
                'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema'],
            ]], $tools);
        }

        $response = Http::withToken($this->apiKey)
            ->withHeaders(array_filter(['OpenAI-Organization' => $this->organization, 'OpenAI-Project' => $this->project]))
            ->acceptJson()->timeout(120)
            ->post(rtrim($this->base, '/').'/chat/completions', $body);

        if (! $response->successful()) {
            // The provider's status, never its body: an error body can echo the request.
            throw new RuntimeException('model_request_failed_'.$response->status());
        }

        $choice = (array) $response->json('choices.0', []);
        $message = (array) ($choice['message'] ?? []);
        $text = is_string($message['content'] ?? null) ? (string) $message['content'] : '';
        $refusal = is_string($message['refusal'] ?? null) ? (string) $message['refusal'] : null;

        $blocks = [];
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }
        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (($call['type'] ?? '') !== 'function') {
                continue;
            }
            $args = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $input = is_array($args) ? $args : ['_invalid_arguments' => true];
            $blocks[] = ['type' => 'tool_use', 'id' => (string) $call['id'], 'name' => (string) ($call['function']['name'] ?? ''), 'input' => $input];
            $calls[] = ['id' => (string) $call['id'], 'name' => (string) ($call['function']['name'] ?? ''), 'input' => $input];
        }

        $stop = match (true) {
            $refusal !== null => 'refusal',
            ($choice['finish_reason'] ?? null) === 'tool_calls' || $calls !== [] => 'tool_use',
            ($choice['finish_reason'] ?? null) === 'length' => 'max_tokens',
            ($choice['finish_reason'] ?? null) === 'content_filter' => 'refusal',
            default => 'end',
        };
        if ($refusal !== null) {
            $text = $refusal;
            $blocks = [['type' => 'text', 'text' => $refusal]];
        }

        return new ModelTurn($blocks, $text, $calls, $stop, [
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ], (string) $response->json('model', $model));
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function assistant(array $blocks): array
    {
        $text = '';
        $calls = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) $b['text'];
            }
            if (($b['type'] ?? '') === 'tool_use') {
                $calls[] = ['id' => (string) $b['id'], 'type' => 'function', 'function' => [
                    'name' => (string) $b['name'], 'arguments' => (string) json_encode((object) ($b['input'] ?? []), JSON_UNESCAPED_UNICODE),
                ]];
            }
        }

        $message = ['role' => 'assistant', 'content' => $text === '' ? null : $text];
        if ($calls !== []) {
            $message['tool_calls'] = $calls;
        }

        return [$message];
    }

    /**
     * A person's words and tool results arrive as ONE user turn in the engine; Chat Completions wants each
     * tool result as its own `tool` message, before any new user text.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function user(array $blocks): array
    {
        $out = [];
        $text = '';
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'tool_result') {
                $content = (string) ($b['content'] ?? '');
                $out[] = ['role' => 'tool', 'tool_call_id' => (string) $b['tool_use_id'], 'content' => ($b['is_error'] ?? false) ? 'ERROR: '.$content : $content];
            }
            if (($b['type'] ?? '') === 'text') {
                $text .= ($text === '' ? '' : "\n").(string) $b['text'];
            }
        }
        if ($text !== '') {
            $out[] = ['role' => 'user', 'content' => $text];
        }

        return $out;
    }
}
