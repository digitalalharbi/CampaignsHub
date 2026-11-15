<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIFECYCLE-001 §1 — plan feature `projects.trash_retention_days` (default 30, enabled on every plan).
 *
 * RELEASE-INTEGRATION — the key now lives in builder M's entitlement table (`EntitlementKey::TrashRetention`, limit
 * `trash_retention_days`), editable per plan / workspace type on `/admin/notifications` and per workspace through
 * `tenant_limit_overrides` (`notif.trash_retention_days`). Order: an admin plan row or a workspace override for the key → the
 * plan's `features` JSON (N1's interim store, kept so nothing set there is lost) → `config('lifecycle.trash_retention_days')`.
 * A value that is not a positive whole number of days never shortens the trash: the default applies.
 */
final class TrashRetention
{
    public const FEATURE = 'projects.trash_retention_days';

    public const LIMIT = 'trash_retention_days';

    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly SubscriptionService $subscriptions, private readonly EntitlementGate $gate) {}

    public function daysFor(string $tenantId): int
    {
        $default = (int) config('lifecycle.trash_retention_days', self::DEFAULT_DAYS);
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant === null) {
            return $default;
        }

        $admin = DB::table('notification_plan_features')->where('key', self::FEATURE)->exists()
            || DB::table('tenant_limit_overrides')->where('tenant_id', $tenantId)->where('metric', 'notif.'.self::LIMIT)->exists();
        if ($admin) {
            $days = $this->gate->limit($tenantId, self::LIMIT);
            if ($days !== null && $days > 0) {
                return $days;
            }
        }

        $value = $this->subscriptions->currentPlan($tenant)->features[self::FEATURE] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
