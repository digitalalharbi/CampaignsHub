<?php

declare(strict_types=1);

namespace App\Domains\CRM\Enums;

enum LeadSource: string
{
    case Website = 'website';
    case Referral = 'referral';
    case Paid = 'paid';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Phone = 'phone';
    case Event = 'event';
    case Exhibition = 'exhibition';
    case Manual = 'manual';
    case Api = 'api';
    case Webhook = 'webhook';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
