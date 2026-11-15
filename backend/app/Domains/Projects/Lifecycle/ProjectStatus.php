<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

/**
 * PROJECT-LIFECYCLE-001 — the five states a project can be in (docs/rules/project-lifecycle.md, rule 3).
 *
 * Stored in `projects.status`. Written ONLY by {@see ProjectStateMachine::transition()}; the allowed moves are
 * {@see self::allowedTargets()}. «Live» (aggregated, alerted, notified, picked) = active + paused — read through
 * {@see LiveProjects}, never by a hand-written filter.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
    case Deleted = 'deleted';

    /**
     * Values written before this contract. A legacy writer can never create a project nobody sees: each is read as
     * the canonical state it behaved as (the 2026-11-18 migration rewrote the stored rows the same way).
     */
    public const LEGACY = [
        'setup' => 'active',
        'onboarding' => 'active',
        'completed' => 'active',
        'closed' => 'active',
    ];

    /**
     * The allowed-transitions table. Restoring (archived/deleted → back) returns to the state recorded before the
     * archive/delete; the machine checks the target against this table and against that recorded state.
     *
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived, self::Deleted],
            self::Active => [self::Paused, self::Archived, self::Deleted],
            self::Paused => [self::Active, self::Archived, self::Deleted],
            self::Archived => [self::Draft, self::Active, self::Paused, self::Deleted],
            self::Deleted => [self::Draft, self::Active, self::Paused, self::Archived],
        };
    }

    public function canBecome(self $to): bool
    {
        return in_array($to, $this->allowedTargets(), true);
    }

    public function isLive(): bool
    {
        return in_array($this->value, LiveProjects::STATUSES, true);
    }

    /** i18n key for the label (rule 10 — never show the raw value). */
    public function labelKey(): string
    {
        return 'projects.lifecycle.status.'.$this->value;
    }

    public static function normalise(?string $value): self
    {
        $value = (string) $value;

        return self::tryFrom(self::LEGACY[$value] ?? $value) ?? self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
