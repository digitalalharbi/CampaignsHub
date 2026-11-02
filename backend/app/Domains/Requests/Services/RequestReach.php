<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which requests may an internal (agency) caller touch? One answer for the inbox, the detail and every
 * write, so a list and a detail cannot disagree (ADR 0002, REG-001).
 *
 * The membership's client ceiling applies through {@see ClientScopeResolver} — the canonical helper — with the
 * rule it documents for tables whose rows may belong to no client: a capped caller reaches their clients'
 * requests PLUS the not-yet-converted requests assigned to them. An unconverted request of somebody else
 * stays out of reach, and an empty ceiling reaches nothing. A holder of `clients.view_all` is untouched.
 */
final class RequestReach
{
    public function __construct(private readonly ClientScopeResolver $scopes) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  an ExternalRequest query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, ?User $user): Builder
    {
        return $this->scopes->constrainAllowingOwn($query, $user, ['external_requests.assigned_to'], 'external_requests.client_id');
    }

    /** May the caller file a request under this (existing) client? */
    public function canFileUnder(?User $user, string $clientId): bool
    {
        return $this->scopes->canReach($user, $clientId);
    }
}
