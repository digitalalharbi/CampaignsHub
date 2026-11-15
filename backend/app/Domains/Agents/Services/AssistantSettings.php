<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AGENTS-ASSISTANT-001 — the workspace's assistant settings and the daily token cap.
 *
 * Stored beside the retention choice in `tenants.settings.ai` (no new table; the retention key is untouched):
 * - `assistant_model`: which Claude model «المساعد» uses with the workspace's own key (one of `agents.assistant.models`);
 * - `daily_token_cap`: tokens ONE person may spend with the assistant per Riyadh day in this workspace.
 *
 * The cap is read from what was actually spent (`agent_messages.usage` of the person's own conversations since Riyadh midnight),
 * not from a counter that could drift, and it is checked before every model call of a turn.
 */
final class AssistantSettings
{
    public const CAP_MIN = 10_000;

    public const CAP_MAX = 5_000_000;

    /** @return list<string> */
    public static function models(): array
    {
        return array_values((array) config('agents.assistant.models', ['claude-sonnet-5-5']));
    }

    public static function defaultModel(): string
    {
        return (string) config('agents.assistant.default_model', 'claude-sonnet-5-5');
    }

    public static function defaultCap(): int
    {
        return max(self::CAP_MIN, min(self::CAP_MAX, (int) config('agents.assistant.daily_token_cap', 200_000)));
    }

    public static function model(?string $tenantId): string
    {
        $chosen = $tenantId === null ? null : data_get(self::settings($tenantId), 'ai.assistant_model');

        return is_string($chosen) && in_array($chosen, self::models(), true) ? $chosen : self::defaultModel();
    }

    public static function cap(?string $tenantId): int
    {
        $chosen = $tenantId === null ? null : data_get(self::settings($tenantId), 'ai.daily_token_cap');

        return is_numeric($chosen) ? max(self::CAP_MIN, min(self::CAP_MAX, (int) $chosen)) : self::defaultCap();
    }

    /** @param  array{assistant_model?: string, daily_token_cap?: int}  $values  already validated by the caller */
    public static function set(Tenant $tenant, array $values): void
    {
        $settings = (array) $tenant->settings;
        $ai = (array) ($settings['ai'] ?? []);
        if (isset($values['assistant_model'])) {
            $ai['assistant_model'] = $values['assistant_model'];
        }
        if (isset($values['daily_token_cap'])) {
            $ai['daily_token_cap'] = max(self::CAP_MIN, min(self::CAP_MAX, (int) $values['daily_token_cap']));
        }
        $settings['ai'] = $ai;
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /** Tokens (input + output) this person spent today (Riyadh) with the agents in this workspace. */
    public static function usedToday(string $tenantId, int|string $userId): int
    {
        $since = Carbon::now('Asia/Riyadh')->startOfDay()->utc();

        return (int) DB::table('agent_messages as m')
            ->join('agent_threads as t', 't.id', '=', 'm.thread_id')
            ->where('m.tenant_id', $tenantId)
            ->where('t.tenant_id', $tenantId)
            ->where('t.created_by', $userId)
            ->where('m.role', 'assistant')
            ->where('m.created_at', '>=', $since)
            ->selectRaw("COALESCE(SUM(COALESCE((m.usage->>'input_tokens')::bigint, 0) + COALESCE((m.usage->>'output_tokens')::bigint, 0)), 0) AS used")
            ->value('used');
    }

    /** @return array<string, mixed> */
    private static function settings(string $tenantId): array
    {
        return (array) Tenant::withoutGlobalScopes()->whereKey($tenantId)->value('settings');
    }
}
