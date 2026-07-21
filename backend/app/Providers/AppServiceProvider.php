<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Audit\Listeners\RecordAuthAudit;
use App\Domains\CRM\Models\Company;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use App\Domains\Integrations\Registry\ConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared per-request tenant context — the authority on "current tenant".
        $this->app->singleton(TenantContext::class);

        // Advertising connector registry; Sandbox is excluded in production.
        $this->app->singleton(
            ConnectorRegistry::class,
            fn () => new ConnectorRegistry(
                includeSandbox: ! $this->app->environment('production'),
            ),
        );
    }

    public function boot(): void
    {
        // Stable, short morph aliases stored in polymorphic columns (non-enforcing, so framework
        // morphs like Sanctum's tokenable keep working).
        Relation::morphMap([
            'lead' => Lead::class,
            'opportunity' => Opportunity::class,
            'company' => Company::class,
        ]);

        // Audit authentication lifecycle events.
        Event::listen(Login::class, [RecordAuthAudit::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAuthAudit::class, 'handleLogout']);
    }
}
