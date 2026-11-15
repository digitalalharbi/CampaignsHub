<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\EntitlementState;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Exceptions\EntitlementBlocked;

/**
 * Server-side plan entitlement (spec §1). Asked by API middleware AND by every scheduled job / sender before acting.
 * Resolution: workspace lifecycle state (grace / grandfathered / view-only) → plan feature row for (plan, type) →
 * (plan, any type) → (any plan, type) → (any, any) → config default.
 */
interface EntitlementGate
{
    public function state(string $tenantId, EntitlementKey $key): EntitlementState;

    public function allows(string $tenantId, EntitlementKey $key): bool;

    /** A quantitative limit (e.g. `external_recipients`, `monthly_messages`, `cc_per_message`, `devices_per_user`). null = unlimited. */
    public function limit(string $tenantId, string $limitKey): ?int;

    /** @throws EntitlementBlocked */
    public function assertAllows(string $tenantId, EntitlementKey $key): void;
}
