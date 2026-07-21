<?php

declare(strict_types=1);

namespace App\Domains\CRM\Enums;

/** Default lead lifecycle. Tenants may customise labels later; keys stay stable. */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case ProposalSent = 'proposal_sent';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }
}
