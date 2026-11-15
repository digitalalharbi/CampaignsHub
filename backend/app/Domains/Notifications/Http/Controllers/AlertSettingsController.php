<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Services\AlertQuietHours;
use App\Domains\Notifications\Services\SoundPreferences;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOTIF-SOUND-001 — the sound and quiet-hours half of the mobile & sound preferences, per person.
 *
 * A person controls only their own row; no permission beyond being signed in. The quiet window itself is stored where it
 * always was (`notification_preferences.quiet_hours`, written by `PUT /settings/notifications`) — this only reads it, with the
 * night default, so the page can show what will actually apply.
 */
final class AlertSettingsController extends Controller
{
    public function __construct(
        private readonly SoundPreferences $sound,
        private readonly AlertQuietHours $quiet,
        private readonly TenantContext $tenant,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload($request), 'Alert settings retrieved.');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sound_enabled' => ['sometimes', 'boolean'],
            'sound_volume' => ['sometimes', 'integer', 'between:0,100'],
            // NOTIF-PUSH-001 — which of the five push types this person wants.
            'push_types' => ['sometimes', 'array'],
            'push_types.*' => ['boolean'],
        ]);

        if (array_key_exists('sound_enabled', $data) || array_key_exists('sound_volume', $data)) {
            $this->sound->save($request->user(), $data['sound_enabled'] ?? null, $data['sound_volume'] ?? null);
        }
        if (isset($data['push_types'])) {
            $this->sound->savePushTypes($request->user(), array_map('boolval', $data['push_types']));
        }

        return ApiResponse::success($this->payload($request), 'Alert settings saved.');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $user = $request->user();
        $stored = $this->sound->stored($user);
        $tenantId = (string) $this->tenant->tenantId();

        return [
            'sound_enabled' => $stored['enabled'],
            'sound_volume' => $stored['volume'],
            'push_types' => $this->sound->pushTypes($user),
            'quiet_hours' => $this->quiet->window((int) $user->id, $tenantId),
        ];
    }
}
