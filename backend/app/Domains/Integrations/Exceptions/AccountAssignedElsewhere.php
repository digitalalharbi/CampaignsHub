<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Exceptions;

use RuntimeException;

/**
 * ORCH-100 §I — an account already feeds a project, and that is not this one.
 *
 * A conflict rather than a validation failure: nothing about the request is malformed, and nothing
 * about it will become valid by being retyped. Something else is true in the world, and the refusal
 * says what and where so the operator can detach it there instead of guessing.
 */
final class AccountAssignedElsewhere extends RuntimeException
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $projectId,
    ) {
        parent::__construct('This account is already connected to another project. Detach it there first.');
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'external_account_id' => $this->accountId,
            'assigned_project_id' => $this->projectId,
        ];
    }
}
