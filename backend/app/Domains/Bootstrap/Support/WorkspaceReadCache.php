<?php

declare(strict_types=1);

namespace App\Domains\Bootstrap\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PERF-BOOTSTRAP-001 — the short-lived read cache of what every page load asks first: the workspace's branding, its subscription,
 * a member's access, and the financial disclaimer.
 *
 * ## What is cached, and what never is
 *
 * Only the ANSWER, after the caller has been authorised. Every reader checks its portal, its section switch and its permission on
 * the request in hand and only then asks this class for the payload, so a cached payload is never a way past a check. What a
 * request decides with (`HasRoles::hasPermission()`, `ClientScopeResolver`, route middleware) is NOT read from here — those
 * still resolve from the database on every request. This only spares recomputing the document that describes them.
 *
 * ## Keys never cross a tenant
 *
 * `ws:v1:{section}:e{epoch}:t:{tenant}` plus, where the answer is a person's, `:m:{membership}:u:{user}`. The tenant id is always
 * in the key, supplied by the caller from the bound tenant context; there is no key without one. Two tenants, or two people of one
 * tenant, can therefore never read each other's entry.
 *
 * ## Invalidation: an epoch per section, bumped by the writes themselves
 *
 * A stale permission document is the one failure that matters here ("read fresh on every request" is a stated property of
 * `/me/access`), so invalidation does not depend on remembering every write path. {@see WorkspaceCacheInvalidator} watches the SQL
 * itself: any INSERT, UPDATE or DELETE on a table a section is derived from bumps that section's epoch, which is part of every key,
 * so every entry of the section is abandoned at once, whoever wrote and through whatever code (Eloquent, a pivot `sync()`, a raw
 * `DB::table()`, a queue worker). The cost of that coarseness is a lower hit rate after a write anywhere — these tables change
 * rarely and are read on every page load — and it can only ever err towards recomputing. The 10-minute TTL is the backstop.
 *
 * ## Redis down
 *
 * A cache that fails must not fail the page. Every cache call is wrapped; on a failure the payload is computed directly, and for the
 * next 30 seconds this process does not try the cache again, so a dead Redis costs one failed connection, not one per section.
 */
final class WorkspaceReadCache
{
    public const TTL_SECONDS = 600;

    public const BRANDING = 'branding';

    public const SUBSCRIPTION = 'subscription';

    public const ACCESS = 'access';

    public const DISCLAIMER = 'disclaimer';

    /**
     * Section => the tables whose writes invalidate it.
     *
     * @var array<string, list<string>>
     */
    public const WATCHED = [
        self::BRANDING => ['tenants'],
        self::SUBSCRIPTION => ['subscriptions', 'subscription_plans', 'subscription_payment_methods'],
        self::ACCESS => ['roles', 'permissions', 'permission_role', 'role_user', 'member_access_overrides', 'membership_scopes', 'memberships'],
        // The disclaimer is the text of `lang/{ar,en}/funding.php`; a deploy changes it, which the file's mtime in the key follows.
        self::DISCLAIMER => [],
    ];

    /** Unix time before which this process does not try the cache again after a failure; 0 while it is believed healthy. */
    private static int $downUntil = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $section, string $tenantId, Closure $compute, ?string $membershipId = null, int|string|null $userId = null, string $variant = ''): mixed
    {
        // No tenant, no key: an answer that cannot be filed under a tenant is not cached at all.
        if ($tenantId === '' || ! isset(self::WATCHED[$section])) {
            return $compute();
        }

        $key = self::key($section, $tenantId, $membershipId, $userId, $variant);
        if ($key === null) {
            return $compute();
        }

        $hit = self::guarded(static fn (): mixed => Cache::get($key));
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $hit['v'];
        }

        $value = $compute();
        // Wrapped so a cached `null` is told apart from a miss.
        self::guarded(static fn (): mixed => Cache::put($key, ['v' => $value], self::TTL_SECONDS));

        return $value;
    }

    /** Abandon every entry of the section (all tenants): the epoch is part of every key. */
    public static function bump(string $section): void
    {
        if (! isset(self::WATCHED[$section])) {
            return;
        }
        self::guarded(static fn (): mixed => Cache::increment(self::epochKey($section)));
    }

    public static function key(string $section, string $tenantId, ?string $membershipId = null, int|string|null $userId = null, string $variant = ''): ?string
    {
        $epoch = self::guarded(static fn (): mixed => Cache::get(self::epochKey($section)));
        if ($epoch === self::FAILED) {
            return null;
        }

        return 'ws:v1:'.$section.':e'.(int) $epoch.':t:'.$tenantId
            .($membershipId === null ? '' : ':m:'.$membershipId)
            .($userId === null ? '' : ':u:'.$userId)
            .($variant === '' ? '' : ':'.$variant);
    }

    private const FAILED = "\0cache-unavailable\0";

    private static function epochKey(string $section): string
    {
        return 'ws:v1:epoch:'.$section;
    }

    /** Run one cache call; on any failure answer FAILED (read as a miss) and stop trying for 30 seconds. */
    private static function guarded(Closure $call): mixed
    {
        if (self::$downUntil > time()) {
            return self::FAILED;
        }

        try {
            return $call();
        } catch (Throwable $e) {
            self::$downUntil = time() + 30;
            Log::warning('workspace read cache unavailable; answering without it', ['error' => $e->getMessage()]);

            return self::FAILED;
        }
    }

    /** For tests: believe the cache healthy again. */
    public static function reset(): void
    {
        self::$downUntil = 0;
    }
}
