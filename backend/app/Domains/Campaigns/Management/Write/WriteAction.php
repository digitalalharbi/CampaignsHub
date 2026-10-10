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
    /** Performed ON a campaign: create an ad set under it. */
    case CreateAdSet = 'create_ad_set';
    /** Performed ON an ad set: create an ad under it, bound to an existing creative. */
    case CreateAd = 'create_ad';
    case Targeting = 'targeting';
    case Placements = 'placements';
    /** Bind an ad to a different existing creative. */
    case Creative = 'creative';
    /** The ad's landing URL, where the platform keeps it on the ad rather than in the creative. */
    case Destination = 'destination';

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
            self::CreateAdSet => 'ad_set_management',
            self::CreateAd => 'ad_creation',
            self::Targeting => 'targeting',
            self::Placements => 'placements',
            self::Creative, self::Destination => 'creative_binding',
        };
    }

    /** Asks for an explicit confirmation in the interface and is recorded as destructive. */
    public function destructive(): bool
    {
        return $this === self::Delete || $this === self::Archive;
    }

    /** Creates a child entity on the platform (the mirror gains a row once the platform confirms). */
    public function createsChild(): bool
    {
        return $this === self::CreateAdSet || $this === self::CreateAd;
    }
}
