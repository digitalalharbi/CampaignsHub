<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

/**
 * What an internal spend limit is drawn around — BUDGET-GOVERNANCE-001.
 *
 * The order is the hierarchy: a project limit contains its platforms, which contain their accounts,
 * which contain their campaigns. Two limits may cover the same spend on purpose — «10,000 across
 * everything, and no more than 4,000 on TikTok» is one plan, not a contradiction — so nothing here
 * tries to reconcile them. Each is measured against the spend inside its own scope and reported.
 */
enum SpendLimitScope: string
{
    case Project = 'project';
    case Platform = 'platform';
    case Account = 'account';
    case Campaign = 'campaign';

    /** Whether this scope needs an identifier beyond the project the limit already belongs to. */
    public function needsIdentifier(): bool
    {
        return $this !== self::Project;
    }

    /** Narrowest first, for reading a hierarchy from the bottom. */
    public function depth(): int
    {
        return match ($this) {
            self::Project => 0,
            self::Platform => 1,
            self::Account => 2,
            self::Campaign => 3,
        };
    }
}
