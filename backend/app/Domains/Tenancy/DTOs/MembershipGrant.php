<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\DTOs;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;

/**
 * Everything a grant of access must state (ADR 0002).
 *
 * Deliberately has no defaults for portal or role. A grant that could be made without naming them
 * would be a grant nobody decided — and the portal in particular must never be inferred from an
 * account type or from `users.tenant_id`, because a person may hold several and those values can
 * only ever describe one.
 */
final readonly class MembershipGrant
{
    /**
     * @param  list<string>|null  $clientScopeIds  null = leave scopes untouched; [] = unrestricted
     *                                             within the tenant; a list = a hard ceiling.
     * @param  list<string>|null  $projectScopeIds  same convention.
     */
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public Portal $portal,
        public string $role,
        public ?Workspace $workspace = null,
        public ?array $clientScopeIds = null,
        public ?array $projectScopeIds = null,
        /** Who granted this. Null only for system provisioning (registration, seeding, migration). */
        public ?User $grantedBy = null,
    ) {}

    /**
     * A client user of an agency: confined to their own client space, in the client portal.
     *
     * Given its own constructor because the confinement is the entire point — an agency's client
     * granted `Portal::Agency`, or granted the client portal with no scope, would see the whole
     * agency. Naming the case makes that mistake hard to make by accident.
     *
     * @param  list<string>  $clientIds
     */
    public static function forAgencyClient(
        User $user,
        Tenant $tenant,
        array $clientIds,
        string $role = 'client_viewer',
        ?User $grantedBy = null,
    ): self {
        return new self(
            user: $user,
            tenant: $tenant,
            portal: Portal::ClientPortal,
            role: $role,
            clientScopeIds: $clientIds,
            grantedBy: $grantedBy,
        );
    }
}
