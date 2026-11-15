<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

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
            'enabled' => $row === null ? true : (bool) $row->sound_enabled,
            'volume' => $row === null ? self::DEFAULT_VOLUME : (int) $row->sound_volume,
        ];
    }

    /** @return array{enabled: bool, volume: int, quiet: bool} */
    public function forReader(User $user): array
    {
        $tenantId = $this->tenant->tenantId();

        return $this->stored($user) + [
            'quiet' => $tenantId !== null && $this->quiet->isQuiet((int) $user->id, (string) $tenantId),
        ];
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
