<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Agents\Engine\AnthropicModelClient;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * AGENTS-STREAM-001 — the Claude client streams through the official SDK, over a transport the test owns.
 *
 * A recorded event stream is replayed by a fake PSR-18 client, so this proves the real SDK path (request shape, event parsing,
 * accumulation, Stop) with no network at all: the fake is the only transport the client is given.
 */
final class AgentAnthropicStreamTest extends TestCase
{
    public function test_text_streams_tool_calls_are_assembled_and_the_stable_prompt_is_cached(): void
    {
        $transport = new RecordedStream($this->events());
        $client = new AnthropicModelClient('sk-ant-test-not-real', $transport);
        $deltas = [];

        $turn = $client->stream('claude-sonnet-5-5', 'STABLE RULES', 'Project: P', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'كيف الأداء؟']]]],
            [['name' => 'list_campaigns', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false]]],
            ['effort' => 'medium', 'max_tokens' => 4000], function (string $d) use (&$deltas): void {
                $deltas[] = $d;
            }, static fn (): bool => false);

        $this->assertSame(['سأقرأ ', 'الحملات.'], $deltas);
        $this->assertSame('tool_use', $turn->stop);
        $this->assertSame([['id' => 'toolu_1', 'name' => 'list_campaigns', 'input' => ['days' => 7]]], $turn->toolCalls);
        $this->assertSame(['input_tokens' => 12, 'output_tokens' => 30, 'cache_read_input_tokens' => 2048], $turn->usage);

        $body = json_decode((string) $transport->requests[0]->getBody(), true);
        $this->assertTrue($body['stream']);
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertSame('Project: P', $body['system'][1]['text']);
        $this->assertArrayNotHasKey('cache_control', $body['system'][1]);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertSame('sk-ant-test-not-real', $transport->requests[0]->getHeaderLine('x-api-key'));
        $this->assertSame('api.anthropic.com', $transport->requests[0]->getUri()->getHost());
    }

    public function test_stop_ends_the_stream_and_keeps_only_the_words_already_shown(): void
    {
        $client = new AnthropicModelClient('k', new RecordedStream($this->events()));
        $n = 0;

        $turn = $client->stream('claude-sonnet-5-5', 'S', '', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'x']]]], [], [],
            function () use (&$n): void {
                $n++;
            }, function () use (&$n): bool {
                return $n >= 1;
            });

        $this->assertSame('stopped', $turn->stop);
        $this->assertSame('سأقرأ ', $turn->text);
        $this->assertSame([['type' => 'text', 'text' => 'سأقرأ ']], $turn->blocks);
        $this->assertSame([], $turn->toolCalls);
    }

    private function events(): string
    {
        $e = static fn (string $type, array $data): string => "event: {$type}\ndata: ".json_encode(['type' => $type] + $data, JSON_UNESCAPED_UNICODE)."\n\n";

        return $e('message_start', ['message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 12, 'output_tokens' => 1, 'cache_read_input_tokens' => 2048]]])
            .$e('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
            .$e('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'سأقرأ ']])
            .$e('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'الحملات.']])
            .$e('content_block_stop', ['index' => 0])
            .$e('content_block_start', ['index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_campaigns', 'input' => (object) []]])
            .$e('content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"days":']])
            .$e('content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '7}']])
            .$e('content_block_stop', ['index' => 1])
            .$e('message_delta', ['delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 30]])
            .$e('message_stop', []);
    }
}

/** The only transport the client gets: replays one recorded event stream and keeps every request it was handed. */
final class RecordedStream implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly string $body) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, ['Content-Type' => 'text/event-stream'], $this->body);
    }
}
