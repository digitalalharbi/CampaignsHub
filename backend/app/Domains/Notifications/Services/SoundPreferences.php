<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\Push\PushTypes;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-SOUND-001 — what the bell's ping may do for one person.
 *
 * The server decides «enabled» and «quiet right now» (it knows the person's timezone and their stored quiet hours), so the
 * page only has to obey. The page still decides «only a NEW critical or warning, once per batch» — that is what it sees arrive.
 */
final class SoundPreferences
{
    public const DEFAULT_VOLUME = 35;

    public function __construct(private readonly AlertQuietHours $quiet, private readonly TenantContext $tenant) {}

    /** @return array{enabled: bool, volume: int} */
    public function stored(User $user): array
    {
        $row = DB::table('notification_alert_settings')->where('user_id', $user->id)->first();

        return [
            'enabled' => $row === null ? $this->workspaceDefault() : (bool) $row->sound_enabled,
            'volume' => $row === null ? self::DEFAULT_VOLUME : (int) $row->sound_volume,
        ];
    }

    /**
     * RELEASE-INTEGRATION — until a person chooses, the bell follows the workspace's «default sound» (builder R's settings page,
     * `settings.default_sound`, default on). A person's own choice always wins.
     */
    private function workspaceDefault(): bool
    {
        $tenantId = $this->tenant->tenantId();
        if ($tenantId === null) {
            return true;
        }
        $value = app(WorkspaceSettingsRepository::class)->for((string) $tenantId)->settings['default_sound'] ?? true;

        return (bool) $value;
    }

    /** @return array{enabled: bool, volume: int, quiet: bool} */
    public function forReader(User $user): array
    {
        $tenantId = $this->tenant->tenantId();

        $stored = $this->stored($user);
        // NOTIF-V2-ENGINE-001 — the plan, server-side: without `notifications.sound` the bell stays silent whatever was chosen.
        if ($tenantId !== null && ! app(EntitlementGate::class)->allows((string) $tenantId, EntitlementKey::Sound)) {
            $stored['enabled'] = false;
        }

        return $stored + [
            'quiet' => $tenantId !== null && $this->quiet->isQuiet((int) $user->id, (string) $tenantId),
        ];
    }

    /**
     * Which of the five push types this person wants (all, until they choose).
     *
     * @return array<string, bool>
     */
    public function pushTypes(User $user): array
    {
        $raw = DB::table('notification_alert_settings')->where('user_id', $user->id)->value('push_types');
        $decoded = $raw === null ? null : json_decode((string) $raw, true);

        return PushTypes::chosen(is_array($decoded) ? $decoded : null);
    }

    /**
     * @param  array<string, bool>  $types  only the keys that arrived are changed
     * @return array<string, bool>
     */
    public function savePushTypes(User $user, array $types): array
    {
        $next = array_replace($this->pushTypes($user), array_intersect_key($types, array_flip(PushTypes::ALL)));
        $now = now();
        DB::table('notification_alert_settings')->upsert([[
            'user_id' => $user->id, 'push_types' => json_encode($next), 'created_at' => $now, 'updated_at' => $now,
        ]], ['user_id'], ['push_types', 'updated_at']);

        return $next;
    }

    /** @return array{enabled: bool, volume: int} */
    public function save(User $user, ?bool $enabled, ?int $volume): array
    {
        $current = $this->stored($user);
        $now = now();

        DB::table('notification_alert_settings')->upsert([[
            'user_id' => $user->id,
            'sound_enabled' => $enabled ?? $current['enabled'],
            'sound_volume' => max(0, min(100, $volume ?? $current['volume'])),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['user_id'], ['sound_enabled', 'sound_volume', 'updated_at']);

        return $this->stored($user);
    }
}
