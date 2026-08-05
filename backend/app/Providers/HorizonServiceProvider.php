<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * PROD-001 — the queue dashboard, and who is allowed to see it.
 *
 * Horizon's own scaffolding ships an empty email allow-list, which fails closed and is therefore safe
 * but unusable: nobody can open the dashboard in any environment but `local`. This product already
 * knows who runs it — `users.is_platform_admin`, the one flag deliberately kept out of mass
 * assignment — so the gate asks that rather than a list of addresses somebody has to remember to
 * edit on every staff change.
 *
 * ## Why the dashboard is not merely "an admin page"
 *
 * Horizon shows job payloads. A payload here can carry a tenant id, a client's name, a store's
 * external id — and a failed job's exception carries whatever the provider said back. That is
 * cross-tenant by nature: one screen listing every tenant's work. No tenant operator, however
 * senior inside their own workspace, has any business on it, which is exactly why the gate reads the
 * platform flag and never a per-tenant role or permission.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        /*
         * Notifications are left unrouted on purpose.
         *
         * Horizon can wire long-wait alerts to mail/Slack/SMS, and every one of those channels is
         * `awaiting_provider_credentials` in this installation (see the runbook's delivery section).
         * Routing them here would produce a queue monitor that silently fails to alert — the exact
         * shape of failure a queue monitor exists to prevent. The wait threshold is configured in
         * `config/horizon.php` and surfaced on `/api/v1/ready` instead, which needs no provider.
         */
    }

    /** Only the platform operator, in every environment but `local`. */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => (bool) $user?->is_platform_admin);
    }
}
