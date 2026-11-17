<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Engine\AgentToolbox;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Middleware\WithholdMoneyForMember;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * MCP-SERVER-001 — CampaignsHub as an MCP server (Model Context Protocol, Streamable HTTP, JSON responses).
 *
 * One endpoint, `POST /api/v1/mcp`, speaking JSON-RPC 2.0: `initialize`, `ping`, `tools/list`, `tools/call`; notifications are
 * acknowledged with 202. Authentication is the person's own access token (`Authorization: Bearer …`, created under «ربط
 * الذكاء الاصطناعي»), so Claude, Gemini or ChatGPT act AS that person: the same workspace, the same client ceiling, the same
 * permissions and the same tools the in-app assistant has — nothing more. Every project-level tool names a `project_id`
 * and is refused unless the person can see that project. Reads change nothing; `propose_change` only queues a proposal that a
 * person approves inside CampaignsHub.
 */
final class McpController extends Controller
{
    private const PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** The in-app assistant's tools that are offered over MCP. */
    private const TOOLS = ['list_campaigns', 'get_history', 'compare_targets', 'get_store_sales', 'list_creatives', 'get_rules', 'list_automation_rules', 'propose_change'];

    public function __construct(private readonly AgentToolbox $toolbox, private readonly ProjectAbilities $abilities) {}

    /** GET is not offered: this server sends no stream of its own. */
    public function stream(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }

    public function handle(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $body = $request->json()->all();
        if ($body === []) {
            return response()->json($this->error(null, -32700, 'Parse error'), 400);
        }

        // A batch (older clients) is answered as a batch; notifications inside it get no entry.
        if (array_is_list($body)) {
            $out = array_values(array_filter(array_map(fn ($m) => is_array($m) ? $this->one($m, $user) : $this->error(null, -32600, 'Invalid Request'), $body)));

            return $out === [] ? response('', 202) : response()->json($out);
        }

        $reply = $this->one($body, $user);

        return $reply === null ? response('', 202) : response()->json($reply);
    }

    /**
     * @param  array<mixed>  $message
     * @return array<string, mixed>|null null for a notification
     */
    private function one(array $message, User $user): ?array
    {
        $id = $message['id'] ?? null;
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (! array_key_exists('id', $message)) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, self::PROTOCOLS, true) ? $params['protocolVersion'] : self::PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'campaignshub', 'title' => 'CampaignsHub', 'version' => '1.0.0'],
                'instructions' => 'CampaignsHub: every paid campaign of the person\'s workspace in one place. Call list_projects first, then pass a project_id to the other tools. Figures come from the ad platforms as synced; nothing here changes a campaign — propose_change only queues a proposal a person approves in CampaignsHub. Answer in the language the person writes in.',
            ]),
            'ping' => $this->result($id, []),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->result($id, $this->call($user, (string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : [])),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $tools = [[
            'name' => 'list_projects',
            'description' => 'The projects (client accounts) this person can see in CampaignsHub, with their client. Call it first: every other tool needs a project_id.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
        ]];

        foreach ($this->toolbox->specs(self::TOOLS) as $spec) {
            $schema = $spec['input_schema'];
            $properties = ['project_id' => ['type' => 'string', 'description' => 'From list_projects.']] + (array) $schema['properties'];
            $tools[] = [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => ['project_id', ...(array) $schema['required']], 'additionalProperties' => false],
            ];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    private function call(User $user, string $name, array $arguments): array
    {
        if ($name === 'list_projects') {
            $rows = Project::query()->with('clientWorkspace:id,name')->orderBy('name')->limit(500)->get(['id', 'name', 'client_workspace_id', 'status'])
                ->filter(fn (Project $p): bool => in_array(ProjectCapability::CAMPAIGNS_VIEW, $this->abilities->for($user, (string) $p->getKey()), true))
                ->map(fn (Project $p): array => ['project_id' => (string) $p->getKey(), 'name' => $p->name, 'client' => $p->clientWorkspace?->name, 'status' => $p->status])
                ->values()->all();

            return $this->text(['projects' => $rows]);
        }

        if (! in_array($name, self::TOOLS, true)) {
            return $this->text(['error' => "Unknown tool {$name}."], true);
        }

        $projectId = (string) ($arguments['project_id'] ?? '');
        $project = $projectId === '' ? null : Project::query()->find($projectId);
        if ($project === null || ! in_array(ProjectCapability::CAMPAIGNS_VIEW, $this->abilities->for($user, (string) $project->getKey()), true)) {
            return $this->text(['error' => 'Unknown project, or this person cannot see it. Call list_projects.'], true);
        }
        if ($name === 'propose_change' && ! $user->hasPermission('ai.use')) {
            return $this->text(['error' => 'Proposing a change needs the ai.use permission in CampaignsHub.'], true);
        }

        unset($arguments['project_id']);
        // MCP-SCOPE-001 — a membership that withholds money on this project gets the answer without it, exactly as the app does.
        $money = $this->abilities->allows($user, (string) $project->getKey(), ProjectCapability::MONEY_VIEW);
        if (! $money && $name === 'propose_change' && ($arguments['action'] ?? null) === 'set_daily_budget') {
            return $this->text(['error' => 'Budgets are withheld for this person on this project.'], true);
        }
        $out = $this->toolbox->call($project, $user, 'assistant', self::TOOLS, $name, $arguments);
        $text = $out['content'];
        if (! $money) {
            $decoded = json_decode($text, true);
            $text = is_array($decoded) ? (string) json_encode(WithholdMoneyForMember::strip($decoded), JSON_UNESCAPED_UNICODE) : $text;
        }

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => ! $out['ok']];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    private function text(array $value, bool $error = false): array
    {
        return ['content' => [['type' => 'text', 'text' => (string) json_encode($value, JSON_UNESCAPED_UNICODE)]], 'isError' => $error];
    }

    /** @return array<string, mixed> */
    private function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
