<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/** Where an event may be delivered (spec §3). `Both` is a catalog value only; a delivery is always one of the other two. */
enum Scope: string
{
    case Internal = 'internal';
    case External = 'external';
    case Both = 'both';

    public function includes(self $scope): bool
    {
        return $this === self::Both || $this === $scope;
    }
}
