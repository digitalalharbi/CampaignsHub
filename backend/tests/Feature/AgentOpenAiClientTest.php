<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\Agents\Engine\OpenAiModelClient;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * AGENTS-ENGINE-001 — the OpenAI client speaks the Chat Completions contract OpenAI's own OpenAPI
 * specification defines, and the engine's neutral history survives the translation both ways.
 */
final class AgentOpenAiClientTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = [['name' => 'list_campaigns', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]]];

    private function reply(array $message, string $finish = 'stop'): array
    {
        return ['model' => 'model-x', 'choices' => [['index' => 0, 'message' => $message + ['role' => 'assistant', 'refusal' => null], 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18]];
    }

    public function test_a_tool_call_is_parsed_and_the_request_follows_the_contract(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->reply(['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_campaigns', 'arguments' => '{"days":7}']]]], 'tool_calls'))]);

        $turn = (new OpenAiModelClient('sk-test', 'org_1', 'proj_1'))->respond('model-x', 'SYSTEM', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'How are we?']]]], self::TOOLS, 2000);

        $this->assertSame('tool_use', $turn->stop);
        $this->assertSame([['id' => 'call_1', 'name' => 'list_campaigns', 'input' => ['days' => 7]]], $turn->toolCalls);
        $this->assertSame(['input_tokens' => 11, 'output_tokens' => 7], $turn->usage);
        Http::assertSent(function (Request $r): bool {
            return $r->url() === 'https://api.openai.com/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer sk-test') && $r->hasHeader('OpenAI-Organization', 'org_1') && $r->hasHeader('OpenAI-Project', 'proj_1')
                && $r['model'] === 'model-x' && $r['max_completion_tokens'] === 2000
                && $r['messages'][0] === ['role' => 'system', 'content' => 'SYSTEM']
                && $r['messages'][1] === ['role' => 'user', 'content' => 'How are we?']
                && $r['tools'][0]['type'] === 'function' && $r['tools'][0]['function']['name'] === 'list_campaigns';
        });
    }

    public function test_history_with_tool_results_becomes_assistant_tool_calls_and_tool_messages(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->reply(['content' => 'Spring leads.']))]);

        $turn = (new OpenAiModelClient('sk-test'))->respond('model-x', 'S', [
            ['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'Who leads?']]],
            ['role' => 'assistant', 'blocks' => [['type' => 'thinking', 'thinking' => 'x', 'signature' => 'y'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_campaigns', 'input' => []]]],
            ['role' => 'user', 'blocks' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"campaigns":[]}', 'is_error' => false]]],
        ], self::TOOLS);

        $this->assertSame('end', $turn->stop);
        $this->assertSame('Spring leads.', $turn->text);
        Http::assertSent(function (Request $r): bool {
            $m = $r['messages'];

            return $m[2] === ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'list_campaigns', 'arguments' => '{}']]]]
                && $m[3] === ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"campaigns":[]}']
                && count($m) === 4;   // the other provider's thinking block is not sent
        });
    }

    public function test_arguments_that_are_not_json_are_marked_and_never_guessed(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->reply(['content' => null, 'tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'list_campaigns', 'arguments' => '{days: 7']]]], 'tool_calls'))]);

        $turn = (new OpenAiModelClient('sk-test'))->respond('m', 'S', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'x']]]], self::TOOLS);

        $this->assertSame(['_invalid_arguments' => true], $turn->toolCalls[0]['input']);
    }

    public function test_a_refusal_and_a_content_filter_stop_are_refusals(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->reply(['content' => null, 'refusal' => 'I cannot help with that.']))
            ->push($this->reply(['content' => ''], 'content_filter'))]);
        $client = new OpenAiModelClient('sk-test');
        $history = [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'x']]]];

        $first = $client->respond('m', 'S', $history, []);
        $this->assertSame('refusal', $first->stop);
        $this->assertSame('I cannot help with that.', $first->text);
        $this->assertSame('refusal', $client->respond('m', 'S', $history, [])->stop);
    }

    public function test_a_provider_error_is_named_by_status_without_its_body(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test']], 401)]);

        try {
            (new OpenAiModelClient('sk-test'))->respond('m', 'S', [['role' => 'user', 'blocks' => [['type' => 'text', 'text' => 'x']]]], []);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertSame('model_request_failed_401', $e->getMessage());
        }
    }

    public function test_an_openai_key_needs_a_model_and_uses_the_one_it_names(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        config(['agents.models.openai.analyst' => null]);

        $key = new AIProviderCredential(['tenant_id' => $tenant->id, 'provider' => 'openai', 'credential_scope' => 'tenant', 'status' => 'active']);
        $key->setSecret('sk-test-not-real');
        $key->save();

        try {
            app(ModelClientFactory::class)->for($project, 'analyst');
            $this->fail('expected model_not_configured');
        } catch (RuntimeException $e) {
            $this->assertSame('model_not_configured', $e->getMessage());
        }

        $key->forceFill(['allowed_models' => ['model-x']])->save();
        $picked = app(ModelClientFactory::class)->for($project, 'analyst');
        $this->assertSame('openai', $picked['provider']);
        $this->assertSame('model-x', $picked['model']);
        $this->assertInstanceOf(OpenAiModelClient::class, $picked['client']);
    }
}
