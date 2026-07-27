<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Client Activity tab — a REAL timeline assembled from existing event sources (audit_logs + request_events),
 * never a hand-made array. Events cover the client itself and everything under it (its projects, campaigns,
 * requests): request submitted/converted, client created, classification/settings changed, project/campaign
 * created, team access changed, report generated/shared, etc. Each item carries actor/action/time/source/
 * old/new/related entity. Tenant + client scoped throughout.
 */
final class ClientActivityController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /app/clients/{client}/activity */
    public function __invoke(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assertView($request->user(), $c);
        $tenantId = (string) $this->tenant->tenantId();

        $projectIds = DB::table('projects')->where('tenant_id', $tenantId)->where('client_workspace_id', $c->id)->pluck('id')->all();
        $campaignIds = DB::table('unified_campaigns')->where('tenant_id', $tenantId)->where('client_workspace_id', $c->id)->pluck('id')->all();
        $requestIds = DB::table('external_requests')->where('tenant_id', $tenantId)->where('client_id', $c->id)->pluck('id')->all();

        $entityIds = array_merge([(string) $c->id], $projectIds, $campaignIds, array_map('strval', $requestIds));

        // 1) Audit log entries for the client and everything under it.
        $audit = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.tenant_id', $tenantId)
            ->whereIn('a.entity_id', $entityIds)
            ->select('a.id', 'a.action', 'a.entity_type', 'a.entity_id', 'a.before', 'a.after', 'a.created_at', 'u.name as actor')
            ->orderByDesc('a.created_at')->limit(200)->get()
            ->map(fn ($r) => [
                'id' => 'audit:'.$r->id,
                'action' => $r->action,
                'actor' => $r->actor,
                'source' => 'audit',
                'time' => $r->created_at,
                'old' => $r->before ? json_decode($r->before, true) : null,
                'new' => $r->after ? json_decode($r->after, true) : null,
                'related_entity' => ['type' => $r->entity_type, 'id' => $r->entity_id],
            ]);

        // 2) Per-request lifecycle events (submitted/status/assigned/converted/comment/file).
        $events = $requestIds === [] ? collect() : DB::table('request_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->leftJoin('external_requests as r', 'r.id', '=', 'e.request_id')
            ->whereIn('e.request_id', $requestIds)
            ->select('e.id', 'e.type', 'e.from_status', 'e.to_status', 'e.message', 'e.created_at', 'u.name as actor', 'r.reference')
            ->orderByDesc('e.created_at')->limit(200)->get()
            ->map(fn ($e) => [
                'id' => 'event:'.$e->id,
                'action' => 'request.'.$e->type,
                'actor' => $e->actor,
                'source' => 'request_event',
                'time' => $e->created_at,
                'old' => $e->from_status ? ['status' => $e->from_status] : null,
                'new' => $e->to_status ? ['status' => $e->to_status] : null,
                'related_entity' => ['type' => 'request', 'id' => $e->reference],
            ]);

        $timeline = $audit->concat($events)->sortByDesc('time')->values()->take(100);

        return response()->json(['data' => ['timeline' => $timeline]]);
    }
}
