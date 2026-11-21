<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Models\User;

/**
 * May this person be MAILED something that names a campaign or an amount — NOTIFICATION-CHANNELS-001.
 *
 * The client-report rules say a client audience never sees campaign names or spend. The bell is read
 * inside the product, where `WithholdMoneyForMember` already strips the money; an email or a WhatsApp
 * message leaves the product and cannot be stripped afterwards. So the question is asked before the
 * message is composed, with the same capability the rest of the product uses (`money.view`), and a
 * message with no project to ask about is refused rather than assumed safe.
 */
final class SpendAudience
{
    public function __construct(private readonly ProjectAbilities $abilities) {}

    public function mayReceive(User $user, ?string $projectId): bool
    {
        if ($projectId === null || $projectId === '') {
            return false;
        }

        return $this->abilities->allows($user, $projectId, ProjectCapability::MONEY_VIEW);
    }
}
