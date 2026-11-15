<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine;

use App\Domains\Notifications\Engine\Catalog\ConfigEventRegistry;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Workspace\ConfigWorkspaceTypes;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use Illuminate\Support\ServiceProvider;

/**
 * NOTIF-V2-ENGINE-001 — binds the shared engine's contracts to their implementations.
 *
 * `scoped`: one instance per request / queued job / console command, so memoised reads (catalog, profiles, settings)
 * never leak a stale answer from one job into the next.
 */
final class NotificationsEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EventRegistry::class, ConfigEventRegistry::class);
        $this->app->scoped(WorkspaceTypes::class, ConfigWorkspaceTypes::class);
        $this->app->scoped(WorkspaceSettingsRepository::class);
    }
}
