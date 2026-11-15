<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/** A delivery channel. Internal: in_app, push, email. External: email only (spec §3). */
enum Channel: string
{
    case InApp = 'in_app';
    case Push = 'push';
    case Email = 'email';

    /** @return list<self> */
    public static function forScope(Scope $scope): array
    {
        return $scope === Scope::External ? [self::Email] : [self::InApp, self::Push, self::Email];
    }
}
