<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\EventDefinition;
use App\Domains\Notifications\Engine\Enums\Scope;

/** The ONE event catalog (spec §3): config defaults merged with platform overrides. */
interface EventRegistry
{
    public function get(string $eventKey): ?EventDefinition;

    /** @return list<EventDefinition> enabled and disabled, ordered by key */
    public function all(): array;

    /** @return list<EventDefinition> enabled events whose scope includes `$scope` */
    public function forScope(Scope $scope): array;
}
