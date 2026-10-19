<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AGENTS-ENGINE-001 — Gemini, through `models.generateContent` as Google's v1beta discovery document defines it.
 *
 * Declarations use `parametersJsonSchema` (plain JSON Schema, so the engine's schemas are sent unchanged).
 * The model's `functionCall` parts are answered with `functionResponse` parts that carry the function NAME
 * and the call id. Thinking models sign their parts with `thoughtSignature` and refuse a history that drops
 * it (finish reason MISSING_THOUGHT_SIGNATURE), so every signature and every thought part is stored with
 * the turn and replayed to Gemini exactly; other providers ignore those blocks.
 */
final class GeminiModelClient implements ModelClient
{
    private const REFUSAL_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'LANGUAGE'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $base = 'https://generativelanguage.googleapis.com/v1beta',
    ) {}

    public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn
    {
        // A functionResponse names its function; the engine's tool_result carries only the call id.
        $names = [];
        foreach ($history as $turn) {
            foreach ($turn['blocks'] as $b) {
                if (($b['type'] ?? '') === 'tool_use') {
                    $names[(string) $b['id']] = (string) $b['name'];
                }
            }
        }

        $contents = [];
        foreach ($history as $turn) {
            $parts = $turn['role'] === 'assistant' ? $this->modelParts($turn['blocks']) : $this->userParts($turn['blocks'], $names);
            if ($parts !== []) {
                $contents[] = ['role' => $turn['role'] === 'assistant' ? 'model' : 'user', 'parts' => $parts];
            }
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(static fn (array $t): array => [
                'name' => $t['name'], 'description' => $t['description'], 'parametersJsonSchema' => $t['input_schema'],
            ], $tools)]];
        }

        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])->acceptJson()->timeout(120)
            ->post(rtrim($this->base, '/').'/models/'.rawurlencode($model).':generateContent', $body);

        if (! $response->successful()) {
            throw new RuntimeException('model_request_failed_'.$response->status());
        }

        $usage = [
            'input_tokens' => (int) $response->json('usageMetadata.promptTokenCount', 0),
            'output_tokens' => (int) $response->json('usageMetadata.candidatesTokenCount', 0) + (int) $response->json('usageMetadata.thoughtsTokenCount', 0),
        ];

        if ($response->json('promptFeedback.blockReason') !== null) {
            $note = 'The model declined this request.';

            return new ModelTurn([['type' => 'text', 'text' => $note]], $note, [], 'refusal', $usage, $model);
        }

        $candidate = (array) $response->json('candidates.0', []);
        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        if ($finish === 'MALFORMED_FUNCTION_CALL') {
            throw new RuntimeException('model_malformed_call');
        }

        $blocks = [];
        $text = '';
        $calls = [];
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            $signature = $part['thoughtSignature'] ?? null;
            if (($part['thought'] ?? false) === true) {
                $blocks[] = ['type' => 'gemini_part', 'part' => $part];   // replayed to Gemini only

                continue;
            }
            if (isset($part['functionCall'])) {
                $id = (string) ($part['functionCall']['id'] ?? 'call_'.Str::random(12));
                $input = is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [];
                $blocks[] = array_filter(['type' => 'tool_use', 'id' => $id, 'name' => (string) $part['functionCall']['name'], 'input' => $input, 'gemini_signature' => $signature], static fn ($v) => $v !== null);
                $calls[] = ['id' => $id, 'name' => (string) $part['functionCall']['name'], 'input' => $input];

                continue;
            }
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
                $blocks[] = array_filter(['type' => 'text', 'text' => (string) $part['text'], 'gemini_signature' => $signature], static fn ($v) => $v !== null);
            }
        }

        $stop = match (true) {
            $calls !== [] => 'tool_use',
            $finish === 'MAX_TOKENS' => 'max_tokens',
            in_array($finish, self::REFUSAL_REASONS, true) => 'refusal',
            default => 'end',
        };

        return new ModelTurn($blocks, $text, $calls, $stop, $usage, $model);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function modelParts(array $blocks): array
    {
        $parts = [];
        foreach ($blocks as $b) {
            $type = $b['type'] ?? '';
            if ($type === 'gemini_part') {
                $parts[] = (array) $b['part'];
            } elseif ($type === 'text') {
                $parts[] = array_filter(['text' => (string) $b['text'], 'thoughtSignature' => $b['gemini_signature'] ?? null], static fn ($v) => $v !== null);
            } elseif ($type === 'tool_use') {
                $parts[] = array_filter([
                    'functionCall' => ['id' => (string) $b['id'], 'name' => (string) $b['name'], 'args' => (object) ($b['input'] ?? [])],
                    'thoughtSignature' => $b['gemini_signature'] ?? null,
                ], static fn ($v) => $v !== null);
            }
        }

        return $parts;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function userParts(array $blocks, array $names): array
    {
        $parts = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'tool_result') {
                $id = (string) $b['tool_use_id'];
                $parts[] = ['functionResponse' => [
                    'id' => $id, 'name' => $names[$id] ?? 'unknown_tool',
                    'response' => ($b['is_error'] ?? false) ? ['error' => (string) $b['content']] : ['result' => (string) $b['content']],
                ]];
            } elseif (($b['type'] ?? '') === 'text') {
                $parts[] = ['text' => (string) $b['text']];
            }
        }

        return $parts;
    }
}
