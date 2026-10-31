<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Tenancy\Models\Tenant;

/**
 * AGENT-CHAT-RETENTION-001 — how long this workspace keeps agent conversations after their last message.
 *
 * The workspace's own choice (`settings.ai.chat_retention_days`, 7–730 days) over the product default (`agents.chat_retention_days`,
 * 90). It is a limit, never an off switch: there is no «keep forever», so conversations are always eventually deleted. The value
 * governs every member's conversations in the workspace; it does not give anyone the ability to read them.
 */
final class AgentChatRetention
{
    public const MIN = 7;

    public const MAX = 730;

    public static function default(): int
    {
        return max(self::MIN, min(self::MAX, (int) config('agents.chat_retention_days', 90)));
    }

    public static function forTenant(?string $tenantId): int
    {
        if ($tenantId === null) {
            return self::default();
        }
        $chosen = data_get(Tenant::withoutGlobalScopes()->whereKey($tenantId)->value('settings'), 'ai.chat_retention_days');

        return is_numeric($chosen) ? max(self::MIN, min(self::MAX, (int) $chosen)) : self::default();
    }

    /** @param  int  $days  already validated to MIN..MAX by the caller */
    public static function set(Tenant $tenant, int $days): int
    {
        $settings = (array) $tenant->settings;
        $settings['ai'] = array_merge((array) ($settings['ai'] ?? []), ['chat_retention_days' => max(self::MIN, min(self::MAX, $days))]);
        $tenant->forceFill(['settings' => $settings])->save();

        return self::forTenant((string) $tenant->getKey());
    }
}
