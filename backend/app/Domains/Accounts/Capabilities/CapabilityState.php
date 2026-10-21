<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Capabilities;

/**
 * CAP-REGISTRY-001 — what one module is, for one workspace, client or project, and WHY.
 *
 * `reason` is null when it is on. Otherwise it is one of: platform_disabled, not_entitled, not_activated,
 * tenant_off, client_off, project_off — so a screen can say what to do about it instead of hiding it.
 */
final class CapabilityState
{
    public function __construct(
        public readonly string $module,
        public readonly bool $entitled,
        public readonly bool $enabled,
        public readonly ?string $reason,
        public readonly string $activation,
        /** The level whose switch decided the outcome, or null when no switch did. */
        public readonly ?string $decidedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'entitled' => $this->entitled,
            'enabled' => $this->enabled,
            'reason' => $this->reason,
            'activation' => $this->activation,
            'decided_at' => $this->decidedAt,
        ];
    }
}
