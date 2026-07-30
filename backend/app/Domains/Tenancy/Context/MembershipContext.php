<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Context;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;

/**
 * The active membership for this request — the single source of truth for portal, tenant, workspace
 * and client scope (ADR 0002).
 *
 * Before this existed, authorisation read `users.tenant_id`, which made a user permanently one
 * tenant's and gave no way to express "this person is an agency operator here and a client there".
 * Everything that decides *what a request may touch* now reads the membership instead; `tenant_id`
 * on the user survives only as a compatibility column and is not consulted for scope.
 *
 * SECURITY: the membership here is authoritative and is only ever set from a membership that has been
 * verified to belong to the authenticated user and to be active. It is never built from request input.
 */
final class MembershipContext
{
    private ?Membership $membership = null;

    public function set(Membership $membership): void
    {
        $this->membership = $membership;
    }

    public function forget(): void
    {
        $this->membership = null;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function has(): bool
    {
        return $this->membership !== null;
    }

    public function portal(): ?Portal
    {
        return $this->membership?->portal;
    }

    public function tenantId(): ?string
    {
        return $this->membership?->tenant_id;
    }

    public function workspaceId(): ?string
    {
        return $this->membership?->workspace_id;
    }

    /**
     * Set only for a membership confined to one client space. When present it is a HARD ceiling: the
     * request may not reach past that single client, whatever else the user's role would allow.
     */
    public function clientWorkspaceId(): ?string
    {
        return $this->membership?->client_workspace_id;
    }

    public function isClientScoped(): bool
    {
        return $this->membership?->isClientScoped() ?? false;
    }

    public function isPortal(Portal $portal): bool
    {
        return $this->membership?->portal === $portal;
    }
}
