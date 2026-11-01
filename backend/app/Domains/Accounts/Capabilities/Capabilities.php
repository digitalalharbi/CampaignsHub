<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Capabilities;

use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * CAP-REGISTRY-001 — the one answer to «is this module available here?».
 *
 * Entitlement is ADDITIVE and activation NARROWS, in that order:
 *
 *   1. The operator's kill switch beats everything.
 *   2. A workspace is entitled when the module is one it already had (`default_entitled`), when its active
 *      or trialing plan carries it (`features.modules.<key>`), or when it was given the module — the
 *      tenant's own `enabled_modules` or a console grant. Nothing here ever removes an entitlement.
 *   3. An `explicit` module is OFF until a switch turns it on; a `default_on` module is ON.
 *   4. Switches are read top down — agency, client, project. A switch OFF is final for everything below
 *      it: a project cannot turn on what its client turned off. A switch ON activates, and can be undone
 *      lower down.
 *
 * Switching a module off hides and refuses the module. It never edits, deletes or recomputes the data the
 * module read, so a figure outside the module is the same figure with the module on or off.
 */
final class Capabilities
{
    public function __construct(
        private readonly AccountEntitlements $entitlements,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public static function catalogue(): array
    {
        /** @var array<string, array<string, mixed>> $modules */
        $modules = (array) config('capabilities.modules', []);

        return $modules;
    }

    /**
     * SUPERADMIN-FEATURES-001 — modules switched off for EVERY workspace: the environment's kill switch and the console's, together.
     *
     * @return list<string>
     */
    public static function platformDisabled(): array
    {
        $raw = PlatformSetting::query()->where('is_singleton', true)->value('disabled_capabilities');
        $console = is_string($raw) ? (array) json_decode($raw, true) : (array) ($raw ?? []);

        return array_values(array_unique(array_map('strval', [...(array) config('capabilities.disabled', []), ...$console])));
    }

    public static function exists(string $module): bool
    {
        return array_key_exists($module, self::catalogue());
    }

    /**
     * @return array<string, CapabilityState> keyed by module
     */
    public function resolve(Tenant $tenant, ?string $clientId = null, ?string $projectId = null): array
    {
        $switches = $this->switches($tenant, $clientId, $projectId);
        $given = $this->entitlements->modules($tenant);
        $killed = self::platformDisabled();
        $planModules = $this->planModules($tenant);

        $out = [];
        foreach (self::catalogue() as $module => $def) {
            $activation = (string) ($def['activation'] ?? 'default_on');

            if (in_array($module, $killed, true)) {
                $out[$module] = new CapabilityState($module, false, false, 'platform_disabled', $activation, null);

                continue;
            }

            $legacy = $def['legacy_module'] ?? null;
            $entitled = ($def['default_entitled'] ?? false) === true
                || ($planModules[$module] ?? false) === true
                || in_array($module, $given, true)
                || (is_string($legacy) && in_array($legacy, $given, true));

            if (! $entitled) {
                $out[$module] = new CapabilityState($module, false, false, 'not_entitled', $activation, null);

                continue;
            }

            $enabled = $activation === 'default_on';
            $reason = $enabled ? null : 'not_activated';
            $decidedAt = null;
            $locked = false;

            foreach ([CapabilitySwitch::TENANT, CapabilitySwitch::CLIENT, CapabilitySwitch::PROJECT] as $level) {
                $switch = $switches[$level][$module] ?? null;
                if ($switch === null || $locked) {
                    continue;
                }
                $decidedAt = $level;
                if ($switch === false) {
                    $enabled = false;
                    $locked = true;
                    $reason = $level.'_off';
                } else {
                    $enabled = true;
                    $reason = null;
                }
            }

            $out[$module] = new CapabilityState($module, true, $enabled, $reason, $activation, $decidedAt);
        }

        return $out;
    }

    /**
     * A counter that moves whenever a switch for this workspace changes, so anything that caches an answer can
     * put it in its key and never read an answer older than the last change.
     */
    public function version(string $tenantId): int
    {
        return (int) Cache::get('capabilities:version:'.$tenantId, 0);
    }

    public function bump(string $tenantId): void
    {
        $key = 'capabilities:version:'.$tenantId;
        Cache::add($key, 0);
        Cache::increment($key);
    }

    public function allows(string $module, Tenant $tenant, ?string $clientId = null, ?string $projectId = null): bool
    {
        return ($this->resolve($tenant, $clientId, $projectId)[$module] ?? null)?->enabled === true;
    }

    /**
     * Switches by level, each as module => bool. A level with no row for a module simply has no key.
     *
     * Read without the tenant scope and bound to THIS tenant explicitly, so it is correct from a job or a
     * console command as well as from a request, and cannot return another workspace's decisions.
     *
     * @return array<string, array<string, bool>>
     */
    private function switches(Tenant $tenant, ?string $clientId, ?string $projectId): array
    {
        $rows = CapabilitySwitch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where(function ($q) use ($tenant, $clientId, $projectId): void {
                $q->where(fn ($t) => $t->where('scope_type', CapabilitySwitch::TENANT)->where('scope_key', $tenant->getKey()));
                if ($clientId !== null) {
                    $q->orWhere(fn ($c) => $c->where('scope_type', CapabilitySwitch::CLIENT)->where('scope_key', $clientId));
                }
                if ($projectId !== null) {
                    $q->orWhere(fn ($p) => $p->where('scope_type', CapabilitySwitch::PROJECT)->where('scope_key', $projectId));
                }
            })
            ->get(['scope_type', 'module', 'enabled']);

        $by = [CapabilitySwitch::TENANT => [], CapabilitySwitch::CLIENT => [], CapabilitySwitch::PROJECT => []];
        foreach ($rows as $row) {
            $by[(string) $row->scope_type][(string) $row->module] = (bool) $row->enabled;
        }

        return $by;
    }

    /** @return array<string, bool> modules the active or trialing plan carries */
    private function planModules(Tenant $tenant): array
    {
        $subscription = $this->subscriptions->subscriptionFor($tenant);
        if ($subscription === null || ! in_array($subscription->status, ['active', 'trialing'], true)) {
            return [];
        }

        $modules = $subscription->plan?->features['modules'] ?? [];

        return is_array($modules) ? array_map(static fn ($v): bool => $v === true, $modules) : [];
    }
}
