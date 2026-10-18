<?php

declare(strict_types=1);

namespace App\Domains\Agents\Contracts;

use App\Domains\Agents\Models\ActionProposal;

/**
 * Carries one APPROVED proposal to a platform. One implementation per provider, enabled only after that
 * provider's write call has been checked against its own documentation and a reversible test.
 *
 * The gateway has already re-checked every rule against fresh state before calling `execute`; an
 * executor must still send the proposal id as the platform's idempotency key where one exists, and
 * must never do more than the proposal says.
 */
interface ProposalExecutor
{
    public function supports(ActionProposal $proposal): bool;

    /** @return array{request: array<string,mixed>, response: array<string,mixed>} what was sent and what came back */
    public function execute(ActionProposal $proposal): array;
}
