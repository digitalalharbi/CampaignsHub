<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which clients' money may a billing caller touch? (ADR 0002, REG-001)
 *
 * The tenant billing routes check `billing.view` / `billing.manage` and the tenant scope, and nothing named the
 * client — so a finance operator confined to one client listed, approved and paid every client's quotes and
 * invoices. The per-client invoice routes already enforce {@see ClientAccess};
 * this gives the tenant-wide routes the same answer from the same canonical helper, {@see ClientScopeResolver}.
 *
 * A capped caller reaches documents of their clients only. Documents with NO client belong to the unrestricted
 * (`clients.view_all`) agency view — fail-closed, like the resolver.
 */
final class BillingReach
{
    public function __construct(private readonly ClientScopeResolver $scopes) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  a Quote or Invoice query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, ?User $user, string $column = 'client_workspace_id'): Builder
    {
        return $this->scopes->constrain($query, $user, $column);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  a Payment query (reached through its invoice)
     * @return Builder<TModel>
     */
    public function constrainPayments(Builder $query, ?User $user): Builder
    {
        $ids = $this->scopes->reachableClientIds($user);

        return $ids === null ? $query : $query->whereIn('invoice_id', Invoice::query()->whereIn('client_workspace_id', $ids)->select('id'));
    }

    /** A document of an unreachable (or client-less, for a capped caller) client does not exist for this caller. */
    public function assertCanActOn(?User $user, ?string $clientId): void
    {
        abort_unless($this->scopes->canReachRow($user, $clientId), 404);
    }

    /**
     * The client a new quote is filed under, checked against everything the caller named.
     *
     * Project and request must exist in this tenant and agree with the client; a capped caller must end up with a
     * client they reach (a quote with none would be invisible to them and land in nobody's portal).
     *
     * @return string|null the resolved client id
     */
    public function resolveQuoteClient(?User $user, string $tenantId, ?string $clientId, ?string $projectId, ?string $requestId): ?string
    {
        if ($clientId !== null) {
            abort_unless(ClientWorkspace::query()->where('tenant_id', $tenantId)->whereKey($clientId)->exists(), 422, 'Unknown client.');
        }

        if ($projectId !== null) {
            $project = Project::query()->where('tenant_id', $tenantId)->find($projectId);
            abort_if($project === null, 422, 'Unknown project.');
            $projectClient = $project->client_workspace_id !== null ? (string) $project->client_workspace_id : null;
            abort_if($clientId !== null && $projectClient !== null && $projectClient !== $clientId, 422, 'The project does not belong to this client.');
            $clientId ??= $projectClient;
        }

        if ($requestId !== null) {
            $request = ExternalRequest::query()->where('tenant_id', $tenantId)->find($requestId);
            abort_if($request === null, 422, 'Unknown request.');
            $requestClient = $request->client_id !== null ? (string) $request->client_id : null;
            abort_if($clientId !== null && $requestClient !== null && $requestClient !== $clientId, 422, 'The request does not belong to this client.');
            $clientId ??= $requestClient;
        }

        abort_unless($this->scopes->canReachRow($user, $clientId), 403);

        return $clientId;
    }
}
