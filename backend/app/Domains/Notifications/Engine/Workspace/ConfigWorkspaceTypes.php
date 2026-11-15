<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Workspace;

use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\WorkspaceTypeProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The workspace-type abstraction (spec §0), read from `config/notification_workspace_types.php`. The resolution rules
 * are data as well (`resolve.portals`, `resolve.account_types`, `resolve.default`): this class never names a type.
 */
final class ConfigWorkspaceTypes implements WorkspaceTypes
{
    /** @var array<string, string> */
    private array $memo = [];

    public function profileFor(string $tenantId, ?int $userId = null): WorkspaceTypeProfile
    {
        return $this->profile($this->keyFor($tenantId, $userId));
    }

    public function profile(string $key): WorkspaceTypeProfile
    {
        $profiles = (array) config('notification_workspace_types.profiles', []);

        if (! isset($profiles[$key])) {
            throw new InvalidArgumentException("Unknown workspace type profile «{$key}».");
        }

        return WorkspaceTypeProfile::fromArray($key, (array) $profiles[$key]);
    }

    public function keys(): array
    {
        return array_values(array_map('strval', array_keys((array) config('notification_workspace_types.profiles', []))));
    }

    private function keyFor(string $tenantId, ?int $userId): string
    {
        $memoKey = $tenantId.'|'.($userId ?? '-');
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $resolve = (array) config('notification_workspace_types.resolve', []);
        $key = null;

        if ($userId !== null) {
            $portal = DB::table('memberships')->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('status', 'active')->value('portal');
            $key = $portal === null ? null : (((array) ($resolve['portals'] ?? []))[(string) $portal] ?? null);
        }

        if ($key === null) {
            $accountType = DB::table('tenants')->where('id', $tenantId)->value('account_type');
            $key = $accountType === null ? null : (((array) ($resolve['account_types'] ?? []))[(string) $accountType] ?? null);
        }

        return $this->memo[$memoKey] = (string) ($key ?? ($resolve['default'] ?? 'agency'));
    }
}
