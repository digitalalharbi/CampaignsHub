<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Canonical campaign status. Unified campaigns use it directly; external campaigns are normalized to
 * it from each platform's own status vocabulary via {@see self::fromProvider()} while the raw status
 * is retained in the external campaign's `raw` payload.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';
    case Pending = 'pending';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Normalize a provider-reported status string onto the canonical set. Covers the common
     * vocabularies (Meta ACTIVE/PAUSED, Google ENABLED/REMOVED, TikTok CAMPAIGN_STATUS_*).
     */
    public static function fromProvider(?string $raw): self
    {
        $key = strtolower(trim((string) $raw));

        return match (true) {
            $key === '' => self::Unknown,
            str_contains($key, 'active'), str_contains($key, 'enabled'), str_contains($key, 'delivering'), str_contains($key, 'running') => self::Active,
            str_contains($key, 'paused'), str_contains($key, 'disabled'), str_contains($key, 'inactive') => self::Paused,
            str_contains($key, 'complete'), str_contains($key, 'ended'), str_contains($key, 'finished') => self::Completed,
            str_contains($key, 'archiv'), str_contains($key, 'removed'), str_contains($key, 'deleted') => self::Archived,
            str_contains($key, 'pending'), str_contains($key, 'review'), str_contains($key, 'draft') => self::Pending,
            default => self::Unknown,
        };
    }
}
