<?php

declare(strict_types=1);

namespace App\Domains\Requests\Support;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Models\Tenant;
use InvalidArgumentException;

/**
 * The owner of one incoming service request: a subscriber's workspace, or the platform itself.
 *
 * The two shapes are the only ones that can be built — a tenant owner always names its tenant, a platform
 * owner never does — so «platform, but filed under this agency» is not a state the intake can be handed.
 */
final class RequestOwner
{
    public function __construct(
        public readonly string $scope,
        public readonly ?Tenant $tenant,
    ) {
        if ($scope === ExternalRequest::OWNER_TENANT && $tenant === null) {
            throw new InvalidArgumentException('A tenant-owned request names its tenant.');
        }
        if ($scope === ExternalRequest::OWNER_PLATFORM && $tenant !== null) {
            throw new InvalidArgumentException('A platform-owned request names no tenant.');
        }
        if (! in_array($scope, [ExternalRequest::OWNER_TENANT, ExternalRequest::OWNER_PLATFORM], true)) {
            throw new InvalidArgumentException('Unknown request owner scope.');
        }
    }

    public function isPlatform(): bool
    {
        return $this->scope === ExternalRequest::OWNER_PLATFORM;
    }
}
