<?php

declare(strict_types=1);

namespace App\Domains\Drive\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Drive\Models\DriveLink;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Drive links may a caller read or point somewhere? (ADR 0002, REG-001)
 *
 * A link is attached to a scope — tenant, client, project or campaign — and every scope but the first resolves to
 * ONE client. The membership's client ceiling ({@see ClientScopeResolver}) is applied to that client: a capped
 * member reaches links of their clients, never a sibling's and never the tenant-level one (which is the agency's
 * own, not a client's). The write side also proves the scope id exists in THIS tenant — `scope_id` used to be an
 * unvalidated uuid, so a link could be re-pointed at, or created for, an id nobody had checked.
 */
final class DriveReach
{
    /** What a file may be attached to, and the table that proves the target exists in this tenant. */
    private const ATTACH_TARGETS = ['creative', 'report'];

    public function __construct(private readonly ClientScopeResolver $scopes) {}

    /**
     * @param  Builder<DriveLink>  $query
     * @return Builder<DriveLink>
     */
    public function constrainLinks(Builder $query, ?User $user): Builder
    {
        $ids = $this->scopes->reachableClientIds($user);

        if ($ids === null) {
            return $query;
        }

        $projects = Project::query()->whereIn('client_workspace_id', $ids)->select('id');

        return $query->where(function (Builder $w) use ($ids, $projects): void {
            $w->where(fn (Builder $c) => $c->where('scope', 'client')->whereIn('scope_id', $ids))
                ->orWhere(fn (Builder $c) => $c->where('scope', 'project')->whereIn('scope_id', $projects))
                ->orWhere(fn (Builder $c) => $c->where('scope', 'campaign')->whereIn('scope_id', UnifiedCampaign::query()->whereIn('project_id', $projects)->select('id')));
        });
    }

    public function canReachLink(?User $user, DriveLink $link): bool
    {
        return $this->canReachScope($user, (string) $link->scope, $link->scope_id !== null ? (string) $link->scope_id : null);
    }

    /** 404 for a link the caller's ceiling does not reach — its existence is not theirs to learn. */
    public function assertCanReachLink(?User $user, DriveLink $link): void
    {
        abort_unless($this->canReachLink($user, $link), 404);
    }

    /**
     * A new or re-pointed link: the scope must be real in this tenant and inside the caller's ceiling.
     * 422 for an id that is not in the tenant, 403 for one that is but is not theirs.
     */
    public function assertCanLinkScope(?User $user, string $scope, ?string $scopeId): void
    {
        if ($scope === 'tenant') {
            abort_if($scopeId !== null, 422, 'A tenant-level link has no scope id.');
        } else {
            abort_if($scopeId === null, 422, 'A scope id is required.');
            abort_if($this->clientOfScope($scope, $scopeId) === false, 422, 'Unknown scope target.');
        }

        abort_unless($this->canReachScope($user, $scope, $scopeId), 403);
    }

    /** A file may only be attached to a creative or report of this tenant that the caller's ceiling reaches. */
    public function assertCanAttachTo(?User $user, string $type, string $id): void
    {
        abort_unless(in_array($type, self::ATTACH_TARGETS, true), 422, 'Unsupported attachment target.');

        $projectId = $type === 'creative'
            ? ExternalCreative::query()->whereKey($id)->value('project_id')
            : Report::query()->whereKey($id)->value('project_id');
        abort_if($projectId === null, 422, 'Unknown attachment target.');

        $client = Project::query()->whereKey($projectId)->value('client_workspace_id');
        abort_unless($this->scopes->canReachRow($user, $client !== null ? (string) $client : null), 403);
    }

    /** A link resolves through a provider connection: it must be this tenant's, and its client (if any) the caller's. */
    public function assertCanUseConnection(?User $user, ?string $connectionId): void
    {
        if ($connectionId === null) {
            return;
        }

        $connection = ProviderConnection::query()->find($connectionId);
        abort_if($connection === null, 422, 'Unknown connection.');
        abort_unless($connection->client_workspace_id === null || $this->scopes->canReach($user, (string) $connection->client_workspace_id), 403);
    }

    private function canReachScope(?User $user, string $scope, ?string $scopeId): bool
    {
        $ids = $this->scopes->reachableClientIds($user);

        if ($ids === null) {
            return true;
        }

        if ($scope === 'tenant' || $scopeId === null) {
            return false;
        }

        $client = $this->clientOfScope($scope, $scopeId);

        return is_string($client) && in_array($client, $ids, true);
    }

    /**
     * The client a scope target belongs to; null when the target exists but has no client, false when it does
     * not exist in this tenant (every model here is tenant-scoped).
     */
    private function clientOfScope(string $scope, string $scopeId): string|false|null
    {
        $client = match ($scope) {
            'client' => ClientWorkspace::query()->whereKey($scopeId)->exists() ? $scopeId : false,
            'project' => Project::query()->whereKey($scopeId)->exists()
                ? Project::query()->whereKey($scopeId)->value('client_workspace_id') : false,
            'campaign' => ($pid = UnifiedCampaign::query()->whereKey($scopeId)->value('project_id')) !== null
                ? Project::query()->whereKey($pid)->value('client_workspace_id') : false,
            default => false,
        };

        return is_bool($client) || $client === null ? $client : (string) $client;
    }
}
