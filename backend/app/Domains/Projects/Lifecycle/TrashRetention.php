<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;

/**
 * PROJECT-LIFECYCLE-001 §1 — plan feature `projects.trash_retention_days` (default 30, enabled on every plan).
 *
 * Read from the workspace's current plan `features` JSON (editable per plan from the admin plan editor), else the
 * config default. MERGE POINT: when builder M's `PlanEntitlementGate` (feature/notifications-v2) lands, register the
 * key there and read it through the gate here — this class is the only reader.
 */
final class TrashRetention
{
    public const FEATURE = 'projects.trash_retention_days';

    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function daysFor(string $tenantId): int
    {
        $default = (int) config('lifecycle.trash_retention_days', self::DEFAULT_DAYS);
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant === null) {
            return $default;
        }

        $value = $this->subscriptions->currentPlan($tenant)->features[self::FEATURE] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
