<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/**
 * CAMPAIGN-MGMT-WRITE-001 — every write CampaignsHub can ask a provider for, and the registry
 * capability each one is governed by.
 *
 * ARCHIVE and DELETE are separate on purpose. Providers do not agree on what «remove» means: Meta
 * keeps an archived campaign readable and offers delete as well; Google's only verb is REMOVE, which
 * is final; Snapchat and TikTok delete. The product offers exactly the verb the provider has, and
 * never presents one provider's semantics under another's name.
 */
enum WriteAction: string
{
    case Pause = 'pause';
    case Resume = 'resume';
    case Rename = 'rename';
    case Budget = 'budget';
    case Schedule = 'schedule';
    case BidStrategy = 'bid_strategy';
    case Archive = 'archive';
    case Delete = 'delete';
    case Duplicate = 'duplicate';

    /** The registry capability (and therefore the permission) this action is gated by. */
    public function capability(): string
    {
        return match ($this) {
            self::Pause, self::Resume => 'pause_resume',
            self::Rename => 'edit_campaign',
            self::Budget => 'budget_change',
            self::Schedule => 'schedule',
            self::BidStrategy => 'bid_strategy',
            self::Archive, self::Delete => 'remove',
            self::Duplicate => 'duplicate',
        };
    }

    /** Asks for an explicit confirmation in the interface and is recorded as destructive. */
    public function destructive(): bool
    {
        return $this === self::Delete || $this === self::Archive;
    }
}
