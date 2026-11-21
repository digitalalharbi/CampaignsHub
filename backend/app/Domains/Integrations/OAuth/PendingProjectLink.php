<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use Illuminate\Support\Facades\Cache;

/**
 * INTEG-PROJECT-LINK-001 — which PROJECT an authorisation was opened for, kept on the server.
 *
 * The journey starts from a project and ends on it. Between the two the browser leaves for the provider's
 * consent screen and comes back through an unauthenticated redirect, and everything that redirect carries in
 * its address can be edited by whoever holds the browser. So the address says only WHICH connection came
 * back; which project it is for, and for whom, is read from here.
 *
 * Written by the OAuth callback (from the single-use state the start request issued) and by nothing else.
 * Read by the project's own link endpoint, which answers «pending» only to the same user, in the same
 * tenant, asking about the same project. A different project in the address therefore resumes nothing.
 *
 * A cache record with a lifetime, like the state it continues: an abandoned journey leaves nothing behind
 * (discovery is not a binding), and nothing here is ever a permission — binding is authorised again, on its
 * own, by the project's capability check.
 */
final class PendingProjectLink
{
    private const PREFIX = 'integration-project-link:';

    public const TTL_MINUTES = 120;

    public static function put(string $connectionId, string $tenantId, string $projectId, int $userId): void
    {
        Cache::put(self::PREFIX.$connectionId, [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'user_id' => $userId,
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    /** True only when THIS user, in THIS tenant, opened THIS connection for THIS project. */
    public static function holds(string $connectionId, string $tenantId, string $projectId, int $userId): bool
    {
        $record = Cache::get(self::PREFIX.$connectionId);

        return is_array($record)
            && hash_equals((string) ($record['tenant_id'] ?? ''), $tenantId)
            && hash_equals((string) ($record['project_id'] ?? ''), $projectId)
            && (int) ($record['user_id'] ?? 0) === $userId;
    }

    public static function forget(string $connectionId): void
    {
        Cache::forget(self::PREFIX.$connectionId);
    }
}
