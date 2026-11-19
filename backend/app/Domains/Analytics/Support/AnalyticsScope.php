<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Support;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Closure;

/**
 * ANALYTICS-DATA-001 — run a read as ONE tenant and ONE project, outside a request.
 *
 * The aggregators read through the tenant and project scopes, which a request sets from its route. A queued snapshot
 * job, a console command or the MCP server has no route, so it states the scope here — and the previous scope is
 * restored afterwards, whatever happens, so a worker that handles two projects in a row can never carry the first
 * one's scope into the second.
 */
final class AnalyticsScope
{
    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function run(string $tenantId, string $projectId, Closure $read): mixed
    {
        $tenant = app(TenantContext::class);
        $project = app(ProjectContext::class);
        $before = [$tenant->tenantId(), $project->projectId()];

        $tenant->setTenantId($tenantId);
        $project->setProjectId($projectId);

        try {
            return $read();
        } finally {
            $tenant->setTenantId($before[0]);
            $project->setProjectId($before[1]);
        }
    }
}
