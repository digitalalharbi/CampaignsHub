<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors\Enums;

use App\Domains\Integrations\Connectors\ConnectionCenterService;

/**
 * The HONEST lifecycle state of a connector for a project. These are deliberately fine-grained so the
 * UI never has to lie: a connector is never shown as "connected" when it merely has an adapter, and it
 * is never shown as {@see self::ProductionVerified} without real credentials AND a real successful sync.
 *
 * Derivation lives in {@see ConnectionCenterService}.
 */
enum ConnectionState: string
{
    /** Adapter exists and is ready to connect (e.g. the Sandbox), no connection established yet. */
    case Available = 'available';

    /** A real platform whose credentials / app approval are not provisioned — an external dependency. */
    case AwaitingCredentials = 'awaiting_credentials';

    /** A real, working connection against the deterministic Sandbox provider (clearly-labelled demo data). */
    case SandboxVerified = 'sandbox_verified';

    /** Real credentials AND at least one real successful sync. Only state that implies live production data. */
    case ProductionVerified = 'production_verified';

    /** Connected, but the granted scopes are missing a permission the sync needs. */
    case PermissionMissing = 'permission_missing';

    /** The stored token has expired; a refresh / reconnect is required before syncing. */
    case TokenExpired = 'token_expired';

    /** The last sync attempt failed (see the connection's last_error / the sync run). */
    case SyncFailed = 'sync_failed';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::AwaitingCredentials => 'Awaiting External Dependency',
            self::SandboxVerified => 'Sandbox Verified',
            self::ProductionVerified => 'Production Verified',
            self::PermissionMissing => 'Permission Missing',
            self::TokenExpired => 'Token Expired',
            self::SyncFailed => 'Sync Failed',
        };
    }

    /** True only when the connector is actually moving real (or real-demo) data right now. */
    public function isHealthy(): bool
    {
        return $this === self::SandboxVerified || $this === self::ProductionVerified;
    }

    /** True when the state is blocked on something outside our control (real platform credentials). */
    public function awaitingExternalDependency(): bool
    {
        return $this === self::AwaitingCredentials;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
