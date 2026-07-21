<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Enums;

/** Lifecycle status of an integration connection. */
enum ConnectorStatus: string
{
    case AwaitingCredentials = 'awaiting_credentials';
    case Connected = 'connected';
    case Error = 'error';
    case Disconnected = 'disconnected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
