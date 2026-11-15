<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/** A workspace member (internal) or an external recipient row. */
enum RecipientKind: string
{
    case User = 'user';
    case External = 'external';
}
