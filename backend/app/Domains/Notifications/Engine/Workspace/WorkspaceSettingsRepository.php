<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Workspace;

use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use Illuminate\Support\Facades\DB;

/**
 * Reads `workspace_notification_settings` (one row per workspace, optional) and fills every gap from the platform
 * defaults and the workspace-type profile. A new workspace of any type works on defaults alone (spec §0).
 */
final class WorkspaceSettingsRepository
{
    /** @var array<string, WorkspaceSettings> */
    private array $memo = [];

    public function __construct(private readonly WorkspaceTypes $types) {}

    public function for(string $tenantId): WorkspaceSettings
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $row = DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->first();
        $profile = $this->types->profileFor($tenantId);
        $name = (string) (DB::table('tenants')->where('id', $tenantId)->value('name') ?? '');

        $json = static function (?object $row, string $column): ?array {
            if ($row === null || $row->{$column} === null) {
                return null;
            }

            return is_string($row->{$column}) ? (array) json_decode($row->{$column}, true) : (array) $row->{$column};
        };

        $quiet = $json($row, 'quiet_hours') ?? (array) config('notifications_v2.routing.default_quiet_hours');

        return $this->memo[$tenantId] = new WorkspaceSettings(
            tenantId: $tenantId,
            workspaceName: $name,
            typeKey: $profile->key,
            timezone: (string) ($row->timezone ?? config('notifications_v2.routing.default_timezone', 'Asia/Riyadh')),
            workDays: array_values(array_map('intval', $json($row, 'work_days') ?? $profile->workDays)),
            quietHours: [
                'enabled' => (bool) ($quiet['enabled'] ?? false),
                'start' => (string) ($quiet['start'] ?? '21:00'),
                'end' => (string) ($quiet['end'] ?? '08:00'),
            ],
            holidays: array_values(array_map('strval', $json($row, 'holidays') ?? [])),
            orgDomains: array_values(array_map(static fn ($d): string => strtolower(trim((string) $d)), $json($row, 'org_domains') ?? [])),
            routing: $json($row, 'routing') ?? [],
            settings: $json($row, 'settings') ?? [],
        );
    }

    public function forget(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->memo = [];
        } else {
            unset($this->memo[$tenantId]);
        }
    }
}
