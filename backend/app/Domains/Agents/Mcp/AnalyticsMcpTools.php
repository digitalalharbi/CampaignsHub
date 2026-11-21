<?php

declare(strict_types=1);

namespace App\Domains\Agents\Mcp;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * MCP-ANALYTICS-001 — the analytics board over MCP, read-only, answering with the board's own numbers.
 *
 * Nothing here calculates anything. Each tool is bound to ONE existing GET route of the analytics API and runs it as an
 * internal request for the same person: the same middleware (workspace, portal, project reach, `project.can:analytics.view`),
 * the same controllers and services, the same FX / withheld-money rules and the same per-tenant caches. A figure over MCP is
 * therefore the figure on the board and on the /r/ report by construction — there is no second formula that could drift.
 *
 * The method is fixed to GET (a write is not expressible), the project comes from the route and is checked against the
 * workspace by the project middleware (another workspace's project is a 404), every answer is bounded in size and carries
 * its source and period. A section whose route does not exist yet answers «not_available_yet» — never invented data.
 */
final class AnalyticsMcpTools
{
    /** Longest list returned; the rest is counted, not sent. */
    private const MAX_ROWS = 40;

    /** A second pass with a smaller list when the answer is still larger than this (characters of JSON). */
    private const MAX_CHARS = 60000;

    /**
     * tool => [route name, project-scoped?, permission, source label, description]
     *
     * @var array<string, array{route: string, project: bool, permission: string, source: string, description: string}>
     */
    public const CATALOG = [
        'analytics_summary' => [
            'route' => 'api.v1.projects.scoped.metrics.summary', 'project' => true, 'permission' => 'analytics.view',
            'source' => 'Ad platforms as synced (daily_metrics), reporting currency',
            'description' => 'Analytics summary of one project for a period: spend, results, ROAS, CPA and the others, with the previous period and the change. Same figures as the Analytics board.',
        ],
        'analytics_platforms' => [
            'route' => 'api.v1.projects.scoped.metrics.platforms', 'project' => true, 'permission' => 'analytics.view',
            'source' => 'Ad platforms as synced (daily_metrics), reporting currency',
            'description' => 'The same period split by ad platform (Snapchat, TikTok, Meta, Google…), one row per platform.',
        ],
        'analytics_content' => [
            'route' => 'api.v1.projects.scoped.creatives.content-intelligence', 'project' => true, 'permission' => 'analytics.view',
            'source' => 'Creatives of the project as synced, by content format',
            'description' => 'Which content formats worked and which did not, with the verdict, over the period.',
        ],
        'analytics_site' => [
            'route' => 'api.v1.projects.scoped.measurement.analytics', 'project' => true, 'permission' => 'analytics.view',
            'source' => 'The client\'s own Google Analytics property, as measured by the site',
            'description' => 'What the website measured (Google Analytics): visits, engagement and conversions. Kept beside the platform figures, never added to them.',
        ],
        'analytics_store' => [
            'route' => 'api.v1.projects.scoped.commerce.depth', 'project' => true, 'permission' => 'analytics.view',
            'source' => 'The connected store (Salla, Zid, Shopify), orders as recorded',
            'description' => 'The store\'s own reading for the period: revenue, orders, customers, and where the orders came from. No customer identity.',
        ],
        'analytics_influencers' => [
            'route' => 'api.v1.influencers.impact.index', 'project' => false, 'permission' => 'influencers.view',
            'source' => 'Creator links, codes and store orders by coupon, as recorded',
            'description' => 'Influencer impact for the whole workspace over the period: clicks, confirmed orders, net revenue, cost, CPA and ROAS per creator. Workspace-wide (project_id is not used).',
        ],
        'analytics_attribution' => [
            'route' => 'api.v1.projects.scoped.metrics.attribution', 'project' => true, 'permission' => 'analytics.view',
            'source' => 'Attribution as reported by each platform, with the model used',
            'description' => 'How results are attributed for the period: the model and window each platform used, and where the platforms disagree.',
        ],
    ];

    public function __construct(private readonly Router $router) {}

    public static function handles(string $tool): bool
    {
        return isset(self::CATALOG[$tool]);
    }

    public static function permission(string $tool): ?string
    {
        return self::CATALOG[$tool]['permission'] ?? null;
    }

    /**
     * The MCP tool descriptors (always read-only: annotations say so too).
     *
     * @return list<array<string, mixed>>
     */
    public static function specs(): array
    {
        $out = [];
        foreach (self::CATALOG as $name => $def) {
            $props = [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Period length ending yesterday. Default 30. Ignored when from and to are given.'],
                'from' => ['type' => 'string', 'description' => 'Period start, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Period end, YYYY-MM-DD.'],
            ];
            if ($def['project']) {
                $props = ['project_id' => ['type' => 'string', 'description' => 'From list_projects.']] + $props;
            }
            $out[] = [
                'name' => $name,
                'description' => $def['description'],
                'inputSchema' => ['type' => 'object', 'properties' => $props, 'required' => $def['project'] ? ['project_id'] : [], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> the answer (JSON-able); `error` set when it is a refusal
     */
    public function run(User $user, string $tool, ?Project $project, array $args): array
    {
        $def = self::CATALOG[$tool];
        [$from, $to] = $this->period($args);
        $base = ['tool' => $tool, 'source' => $def['source'], 'period' => ['from' => $from, 'to' => $to]];

        // A section whose endpoint is not in this build is said so; nothing stands in for it.
        if (! Route::has($def['route'])) {
            return $base + ['status' => 'not_available_yet', 'note' => 'This section is not available yet.'];
        }

        $params = $def['project'] ? ['project' => (string) $project?->getKey()] : [];
        $uri = route($def['route'], $params, false);
        $res = $this->get($user, $uri, ['from' => $from, 'to' => $to] + ($def['project'] ? [] : ['page' => 1]));

        if ($res['status'] === 403) {
            return ['error' => 'Not permitted for this person on this project.'];
        }
        if ($res['status'] === 404) {
            return ['error' => 'Unknown project, or this person cannot see it. Call list_projects.'];
        }
        if ($res['status'] === 402 || $res['status'] === 409) {
            return $base + ['status' => 'not_available_yet', 'note' => 'This section is not enabled for this workspace.'];
        }
        if ($res['status'] < 200 || $res['status'] >= 300 || ! is_array($res['body'])) {
            return ['error' => 'The reading failed. Do not retry the same call.'];
        }

        $data = $res['body']['data'] ?? $res['body'];
        $meta = $res['body']['meta'] ?? null;

        return $base + ['status' => 'ok', 'data' => $this->bound($data, $meta)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: string, 1: string}
     */
    private function period(array $args): array
    {
        $ok = static fn (mixed $v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
        if ($ok($args['from'] ?? null) && $ok($args['to'] ?? null) && $args['from'] <= $args['to']) {
            $from = Carbon::parse($args['from']);
            $to = Carbon::parse($args['to']);
            if ($from->diffInDays($to) > 365) {
                $from = $to->copy()->subDays(364);
            }

            return [$from->toDateString(), $to->toDateString()];
        }
        $days = max(1, min(90, (int) ($args['days'] ?? 30)));
        $to = Carbon::yesterday();

        return [$to->copy()->subDays($days - 1)->toDateString(), $to->toDateString()];
    }

    /**
     * Run one GET route as the person, in-process, and put the request-scoped state back as it was.
     *
     * @param  array<string, mixed>  $query
     * @return array{status: int, body: mixed}
     */
    private function get(User $user, string $uri, array $query): array
    {
        $app = app();
        $outer = $app->bound('request') ? $app->make('request') : null;
        $outerRoute = $app->bound(RoutingRoute::class) ? $app->make(RoutingRoute::class) : null;
        $projects = $app->make(ProjectContext::class);
        $outerProject = $projects->projectId();

        $request = Request::create($uri, 'GET', $query);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(static fn () => $user);

        try {
            $app->instance('request', $request);
            $response = $this->router->dispatch($request);

            return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
        } catch (HttpExceptionInterface $e) {
            return ['status' => $e->getStatusCode(), 'body' => null];
        } catch (Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                return ['status' => 404, 'body' => null];
            }
            $app->make(ExceptionHandler::class)->report($e);

            return ['status' => 500, 'body' => null];
        } finally {
            if ($outer !== null) {
                $app->instance('request', $outer);
            }
            if ($outerRoute !== null) {
                $app->instance(RoutingRoute::class, $outerRoute);
            }
            $projects->setProjectId($outerProject);
        }
    }

    /**
     * Cap every list so one answer cannot grow without limit; what was cut is counted, not hidden.
     *
     * @return array<string, mixed>
     */
    private function bound(mixed $data, mixed $meta): array
    {
        foreach ([self::MAX_ROWS, 15, 5] as $cap) {
            $cut = 0;
            $out = ['figures' => $this->cap($data, $cap, $cut), 'meta' => $meta, 'rows_not_shown' => $cut];
            if (mb_strlen((string) json_encode($out, JSON_UNESCAPED_UNICODE)) <= self::MAX_CHARS) {
                return $out;
            }
        }

        return $out;
    }

    private function cap(mixed $v, int $max, int &$cut): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (array_is_list($v) && count($v) > $max) {
            $cut += count($v) - $max;
            $v = array_slice($v, 0, $max);
        }

        return array_map(fn ($x) => $this->cap($x, $max, $cut), $v);
    }
}
