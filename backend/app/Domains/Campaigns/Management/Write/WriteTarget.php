<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/**
 * The provider-side address of one entity, resolved by the service from rows this project owns.
 *
 * Nothing here comes from the request: the ids are read from the project's own mirror of the
 * provider, so a write can only ever address an entity the reader could already see.
 */
final class WriteTarget
{
    public function __construct(
        public readonly WriteLevel $level,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $accountExternalId,
        /** The campaign's provider id for an ad set; the ad set's provider id for an ad. */
        public readonly ?string $parentExternalId,
        /** Google Ads: the manager the account is reached through, for `login-customer-id`. */
        public readonly ?string $managerExternalId,
        public readonly ?string $currency,
    ) {}
}
