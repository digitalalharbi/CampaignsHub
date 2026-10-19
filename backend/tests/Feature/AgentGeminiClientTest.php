<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Agents\Engine\AnthropicModelClient;
use App\Domains\Agents\Engine\GeminiModelClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * AGENTS-ENGINE-001 — Gemini through `models.generateContent` as Google's v1beta discovery document defines it:
 * JSON-Schema declarations, name-matched function responses, and thought signatures replayed untouched.
 */
final class AgentGeminiClientTest extends TestCase
{
    private const TOOLS = [['name' => 'list_campaigns', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer']], 'additionalProperties' => false]]];

    private function reply(array $parts, string $finish = 'STOP'): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish]], 'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 3]];
    }

    public function test_a_signed_function_call_is_parsed_and_replayed_exactly(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->reply([
                ['text' => 'thinking…', 'thought' => true, 'thoughtSignature' => 'SIG_T'],
                ['functionCall' => ['id' => 'fc_1', 'name' => 'list_campaigns', 'args' => ['days' => 7]], 'thoughtSignature' => 'SIG_C'],
            ]))
            ->push($this->reply([['text' => 'Spring leads.']]))]);
        $client = new GeminiModelClient('g-key');
        $history = [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'Who leads?']]]];

        $first = $client->respond('model-g', 'SYSTEM', $history, self::TOOLS, 1000);
        $this->assertSame('tool_use', $first->stop);
        $this->assertSame([['id' => 'fc_1', 'name' => 'list_campaigns', 'input' => ['days' => 7]]], $first->toolCalls);
        $this->assertSame(['input_tokens' => 20, 'output_tokens' => 8], $first->usage);

        $history[] = ['role' => 'assistant', 'blocks' => $first->blocks];
        $history[] = ['role' => 'user', 'blocks' => [['type' => 'tool_result', 'tool_use_id' => 'fc_1', 'content' => '{"campaigns":[]}', 'is_error' => false]]];
        $second = $client->respond('model-g', 'SYSTEM', $history, self::TOOLS, 1000);
        $this->assertSame('Spring leads.', $second->text);

        $sent = Http::recorded();
        /** @var Request $req */
        $req = $sent[0][0];
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/model-g:generateContent', $req->url());
        $this->assertTrue($req->hasHeader('x-goog-api-key', 'g-key'));
        $this->assertSame(['parts' => [['text' => 'SYSTEM']]], $req['systemInstruction']);
        $this->assertSame(['type' => 'object', 'properties' => ['days' => ['type' => 'integer']], 'additionalProperties' => false], $req['tools'][0]['functionDeclarations'][0]['parametersJsonSchema']);
        $this->assertSame(1000, $req['generationConfig']['maxOutputTokens']);

        $replay = $sent[1][0]['contents'];
        $this->assertSame('model', $replay[1]['role']);
        $this->assertSame(['text' => 'thinking…', 'thought' => true, 'thoughtSignature' => 'SIG_T'], $replay[1]['parts'][0]);
        $this->assertSame('SIG_C', $replay[1]['parts'][1]['thoughtSignature']);
        $this->assertSame(['id' => 'fc_1', 'name' => 'list_campaigns', 'response' => ['result' => '{"campaigns":[]}']], $replay[2]['parts'][0]['functionResponse']);
    }

    public function test_safety_and_a_blocked_prompt_are_refusals_and_a_malformed_call_is_named(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->reply([['text' => '']], 'SAFETY'))
            ->push(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT'], 'usageMetadata' => ['promptTokenCount' => 4]])
            ->push($this->reply([], 'MALFORMED_FUNCTION_CALL'))]);
        $client = new GeminiModelClient('g-key');
        $h = [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'x']]]];

        $this->assertSame('refusal', $client->respond('m', 'S', $h, [])->stop);
        $this->assertSame('refusal', $client->respond('m', 'S', $h, [])->stop);
        $this->expectExceptionObject(new RuntimeException('model_malformed_call'));
        $client->respond('m', 'S', $h, []);
    }

    public function test_a_provider_error_is_named_by_status(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $this->expectExceptionObject(new RuntimeException('model_request_failed_400'));
        (new GeminiModelClient('g-key'))->respond('m', 'S', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'x']]]], []);
    }

    public function test_the_anthropic_replay_drops_another_provider_s_private_blocks(): void
    {
        $replay = (fn (array $b): array => $this->replay($b))->call(new AnthropicModelClient('k'), [
            ['type' => 'gemini_part', 'part' => ['text' => 't', 'thought' => true]],
            ['type' => 'text', 'text' => 'hello', 'gemini_signature' => 'SIG'],
            ['type' => 'tool_use', 'id' => 'fc_1', 'name' => 'list_campaigns', 'input' => [], 'gemini_signature' => 'SIG2'],
        ]);

        $this->assertCount(2, $replay);
        $this->assertSame('text', $replay[0]->type);
        $this->assertSame('tool_use', $replay[1]->type);
    }
}
