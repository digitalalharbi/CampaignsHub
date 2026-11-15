<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Data\Delivery;

/**
 * Builds the words of a delivery. The engine ships `CatalogMessageComposer` (catalog title/body, project + workspace,
 * reference). The external email design (spec §11–§13) replaces it for the external scope by rebinding this contract.
 */
interface MessageComposer
{
    public function compose(Delivery $delivery): ComposedMessage;
}
